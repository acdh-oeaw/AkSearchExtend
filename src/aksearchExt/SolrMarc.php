<?php

/*
 * The MIT License
 *
 * Copyright 2022 Austrian Centre for Digital Humanities.
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */

namespace aksearchExt;

use File_MARC_Data_Field;
use VuFindSearch\Query\Query;
use VuFindSearch\ParamBag;
use VuFind\View\Helper\Root\RecordLink;
use VuFind\RecordTab\ComponentParts;
use aksearchExt\container\IlsHoldingId;
use aksearchExt\container\HoldingData;
use aksearchExt\container\ItemData;

class SolrMarc extends \AkSearch\RecordDriver\SolrMarc {

    static private $acIdCallers = [
        RecordLink::class . '::getChildRecordSearchUrl',
        ComponentParts::class . '::getResults',
    ];

    /**
     * Restores the getContainerTitle() to the VuFind original one
     * (AkSearch provided own version based on custom fields)
     * 
     * @see getConsolidatedParents()
     */
    public function getContainerTitle() {
        return \VuFind\RecordDriver\DefaultRecord::getContainerTitle();
    }

    /**
     * Returns Basisklassifikation
     * https://redmine.acdh.oeaw.ac.at/issues/19501
     */
    public function getClassification() {
        $values = $this->fields['basiskl_str_mv'] ?? false;
        return $values ? implode("<br/>", $values) : null;
    }

    /**
     * Search for URLs in MARC 856 taking URL from subfield u and label from
     * subfields 3 or x
     * 
     * https://redmine.acdh.oeaw.ac.at/issues/19527
     */
    public function getURLs() {
        $labelSubfields = ['3', 'x'];
        $retVal = [];
        $urls = $this->getMarcRecord()->getFields('856');
        foreach ($urls as $urlField) {
            $url = $urlField->getSubfield('u');
            if (!is_object($url)) {
                continue;
            }
            $url = $url->getData();
            foreach ($labelSubfields as $subfield) {
                $label = $urlField->getSubfield($subfield);
                if ($label) {
                    break;
                }
            }
            $label = $label ? $label->getData() : $url;
            $retVal[] = ['url' => $url, 'desc' => $label];
        }
        return $retVal;
    }

    /**
     * To bypass how AkSearch displays the "Published in" field in the single record view
     * without overriding the `AkSearch\View\Helper\Root\RecordDataFormatterFactory::getDefaultCoreSpecs()`
     * 
     * It's a little messy because the `hierarchy_parent_id` solr field is an array
     * while `container_title` (used by `getContainerTitle`) is single-valued.
     * 
     * https://redmine.acdh.oeaw.ac.at/issues/19801
     */
    public function getConsolidatedParents() {
        $parents = $this->fields['hierarchy_parent_id'] ?? [];
        $parents = is_array($parents) ? $parents : [$parents];
        if (count($parents) === 0) {
            return null;
        }
        return [[
        'id' => $parents[0],
        'title' => $this->getContainerTitle(),
        'volNo' => '',
        ]];
    }

    /**
     * Check the actual resource is Open Access or not
     * @return bool
     */
    public function getOpenAccessData(): bool {
        $oa = $this->getMarcFieldsAsObject($this->getMarcRecord(), 506, null, null, [
            'f']);
        if (isset($oa[0]->f) && strtolower($oa[0]->f) == "unrestricted online access") {
            return true;
        }
        return false;
    }

    /**
     * https://redmine.acdh.oeaw.ac.at/issues/20340
     * @return bool
     */
    public function isPeerReviewed(): bool {
        $pr = $this->getMarcFieldsAsObject($this->getMarcRecord(), 500, null, null, [
            'a']);

        foreach ($pr as $v) {
            if (isset($v->a) && strtolower($v->a) == "refereed/peer-reviewed") {
                return true;
            }
        }

        return false;
    }

    /**
     * OEAW library uses AC ids (ones stored in MARC field 009, they are also extracted into solr field `ctrlnum` 
     * but there are different ids in the `ctrlnum` solr fields as well) for denoting parent-child relations between resources.
     *
     * This implementation of getUniqueID() checks if it's called in such a context and serves the AC id when it's needed.
     * Otherwise is serves the ordinary id (the one from the `id` solr field)
     * 
     * Related to https://redmine.acdh.oeaw.ac.at/issues/19800
     */
    public function getUniqueID() {
        $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1];
        $caller = ($caller['class'] ?? '') . '::' . ($caller['function'] ?? '');
        if (!in_array($caller, self::$acIdCallers)) {
            return parent::getUniqueID();
        }
        return $this->getMarcRecord()->getField(9)->getData();
    }

    /**
     * Collects all possible holding identifiers, including information required
     * for LKR items filtering.
     * 
     * @return array<\aksearchExt\container\IlsHoldingId>
     */
    public function getHoldingIds(): array {
        static $ids = [];
        if (count($ids) === 0) {
            $ids[] = new IlsHoldingId($this->getUniqueID());

            $marc = $this->getMarcRecord();
            $lkrRecords = $this->getMarcFieldsAsObject($marc, 773, 1, 8, ['w']);
            foreach ($lkrRecords as $lkrRecord) {
                if (empty($lkrRecord->w) || empty($lkrRecord->g)) {
                    continue;
                }
                $ctrlnum = preg_replace('/^.*[)]/', '', $lkrRecord->w);
                $param = new ParamBag(['fl' => 'id']);
                $record = $this->searchService->search('Solr', new Query("ctrlnum:$ctrlnum"), 0, 1, $param)->first();
                if ($record !== null) {
                    // there might be many subfield g values (!)
                    // for now we optimistically assume the prefix (which we skip using preg_replace())
                    // doesn't count
                    $ids[] = new IlsHoldingId($record->getRawData()['id'], null, preg_replace('/^.*:/', '', $lkrRecord->g), true);
                }
            }
        }
        return $ids;
    }

    /**
     * LKR:
     * - https://redmine.acdh.oeaw.ac.at/issues/14550
     * - https://redmine.acdh.oeaw.ac.at/issues/19566
     * 
     */
    public function getRealTimeHoldings(): array {
        $results = $this->holdLogic->getHoldings($this->getHoldingIds());

        //<-- Electronic holdings
        //    https://redmine.acdh.oeaw.ac.at/issues/19474
        $marc = $this->getMarcRecord();
        $ave = $this->getMarcFieldsAsObject($marc, 'AVE');
        if (count($ave) > 0) {
            foreach ($this->getMarcFieldsAsObject($marc, 'AVE') as $ave) {
                if (!empty($ave->x)) {
                    $holding = new HoldingData(new IlsHoldingId($ave->{0}));
                    $holding->items[] = ItemData::fromAve($ave);
                    $results['electronic_holdings'][] = $holding;
                }
            }
        }
        //-->

        return $results;
    }

    public function hasElectronicHoldings(): bool {
        $marc = $this->getMarcRecord();
        $ave = $this->getMarcFieldsAsObject($marc, 'AVE');
        if (count($ave) > 0) {
            foreach ($this->getMarcFieldsAsObject($marc, 'AVE') as $ave) {
                if (!empty($ave->x)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Display Exemplarbeschreibung and Ex Libris data on the holdings tab
     * https://redmine.acdh.oeaw.ac.at/issues/19506
     */
    public function getHolding991992() {
        $id = $this->getUniqueID();
        $marc = $this->getMarcRecord();
        // get holdings
        $results = $this->holdLogic->getHoldings($id, $this->tryMethod('getConsortialIDs'));
        $this->getHolding991992Data($marc, $results['holdings']);
        return $this->formatHolding991992Data($results['holdings']);
    }

    private function formatHolding991992Data(array $data): array {

        $exemplerdata = array();
        foreach ($data as $name => $holding) {
            if (isset($holding['items'])) {
                //$exemplerdata[$name];
                foreach ($holding['items'] as $item) {
                    if ($item['exemplarbeschreibung']) {
                        $exemplerdata[$name]['exemplarbeschreibung'] = $item['exemplarbeschreibung'];
                    }

                    if ($item['exLibris']) {
                        $exemplerdata[$name]['exLibris'] = $item['exLibris'];
                    }
                }
            }
        }
        return $exemplerdata;
    }

    /**
     * https://redmine.acdh.oeaw.ac.at/issues/19506
     * Display Exemplarbeschreibung and Ex Libris data on the holdinstab
     * @param type $marc
     * @param type $data
     */
    private function getHolding991992Data($marc, array &$data) {
        //Exemplarbeschreibung
        $keys992 = array('8', 'b', 'c', 'd', 'e', 'f', 'k', 'l', 'm', 'p', 'q', 'r',
            's');
        //exLibris
        $keys991 = array('8', 'a', 'b', 'c', 'd', 'f', 'i', 'j', 'k', 'l', 'm', 't');

        foreach ($data as &$v) {
            if (isset($v['items'])) {
                foreach ($v['items'] as &$iv) {
                    $iv['exLibris'] = $this->getFieldsByKeysAndField($marc, $keys991, '991');
                    $iv['exemplarbeschreibung'] = $this->getFieldsByKeysAndField($marc, $keys992, '992');
                }
            }
        }
    }

    private function getFieldsByKeysAndField($marc, $keys, $field): array {
        $values = [];
        foreach ($this->getMarcFieldsAsObject($marc, $field, null, null) as $field) {
            foreach ($keys as $k) {
                if (!empty($field->$k)) {
                    $values[$k][] = $field->$k;
                } else {
                    //we have to add an empty result because we need the same amount of values for each field
                    $values[$k][] = '';
                }
            }
        }
        return $values;
    }

    /**
     * Fetches all values of a given MARC field (optionally fullfilling given
     * indicator 1/2 filters) as an array of objects (with subfields mapped to
     * object properties). 
     * 
     * Remark - in case of the same subfield repeated within a single MARC field
     * (don't know if it's possible or not) the last subfield value is used.
     * 
     * @param type $marc
     * @param type $field
     * @param type $ind1
     * @param type $ind2
     * @param type $singleValueAsLiteral
     * @return array
     */
    private function getMarcFieldsAsObject($marc, $field, $ind1 = null,
            $ind2 = null,
            $singleValueAsLiteral = true): array {
        $fields = [];
        foreach ($marc->getFields($field) as $i) {
            if ($i instanceof File_MARC_Data_Field && (!empty($ind1) && intval($i->getIndicator(1)) !== $ind1 || !empty($ind2) && intval($i->getIndicator(2)) !== $ind2)) {
                continue;
            }
            $fieldData = new \stdClass();
            foreach ($i->getSubfields() as $subfield => $value) {
                if (!isset($fieldData->$subfield)) {
                    $fieldData->$subfield = [];
                }
                $fieldData->$subfield[] = $value->getData();
            }
            foreach ($fieldData as $k => &$v) {
                if (count($v) === 1 && ($singleValueAsLiteral === true || is_array($singleValueAsLiteral) && in_array($k, $singleValueAsLiteral))) {
                    $v = $v[0];
                }
            }
            unset($v);
            $fields[] = $fieldData;
        }
        return $fields;
    }

    /**
     * AK: Get all possible contributors grouped by role in the right order.
     * 
     * TODO: Make that function shorter and cleaner if possible! Implement a fallback
     * to MarcXML!
     *
     * @return array    An array with all contributors grouped by role
     */
    public function getContributorsByRole() {

        // Initialize return variable
        $contributors = [];

        // Initialize other variables
        $name = null;
        $role = null;
        $auth = null;

        // Get primary author
        $primaryName = $this->fields['author'] ?? null;
        $primaryRole = $this->fields['author_role'] ?? null;
        $primaryAuth = $this->fields['author_GndNo_str'] ?? null;

        $authors = array();
        if ($primaryName !== null) {
            $authors = $this->createSecondaryAuthors($primaryName, $primaryRole);
        }

        // Get primary corporate author
        $corpName = $this->fields['author_corporate'][0] ?? null;
        $corpRole = $this->fields['author_corporate_role'][0] ?? null;
        $corpAuth = $this->fields['author_corporate_GndNo_str'] ?? null;

        // Get primary meeting author
        $meetingName = $this->fields['author_meeting_txt'] ?? null;
        $meetingRole = $this->fields['author_meeting_role_str'] ?? null;
        $meetingAuth = $this->fields['author_meeting_GndNo_str'] ?? null;

        // Get secondary authors
        $secNames = $this->fields['author2'] ?? null;
        $secRole = $this->fields['author2_role'] ?? null;
        $authors2 = array();
        if ($secNames !== null) {
            $authors2 = $this->createSecondaryAuthors($secNames, $secRole);
        }

        // Get secondary corporate authors
        $secCorps = $this->fields['author2_corporate_NameRoleGnd_str_mv'] ?? null;

        // Get secondary meeting authors
        $secMeetings = $this->fields['author2_meeting_NameRoleGnd_str_mv'] ?? null;

        // Add primary corporation authors to array (values from Marc21 field 110)
        if ($corpName) {
            $contributors[$corpRole][] = [
                'entity' => 'corporation',
                'name' => $corpName,
                'role' => $corpRole,
                'auth' => $corpAuth,
                'primary' => true
            ];
        }

        // Add primary meeting authors to array (values from Marc21 field 111)
        if ($meetingName) {
            $contributors[$meetingRole][] = [
                'entity' => 'meeting',
                'name' => $meetingName,
                'role' => $meetingRole,
                'auth' => $meetingAuth,
                'primary' => true
            ];
        }

        // Add primary person authors to array (values from Marc21 field 700)
        $authorsArray = array_merge_recursive($this->addAuthorsToContributorsArray($authors), $this->addAuthorsToContributorsArray($authors2));
        $contributors = array_merge_recursive($contributors, $authorsArray);

        // Add secondary corporation authors to array (values from Marc21 field 710)
        if ($secCorps) {
            $this->getSecondaryAuthorData($contributors, $secCorps, 'corporation');
        }
        // Add secondary meeting authors to array (values from Marc21 field 711)
        if ($secMeetings) {
            $this->getSecondaryAuthorData($contributors, $secMeetings, 'meeting');
        }

        $this->removeContributorDuplicates($contributors);
        return $contributors;
    }

    /**
     * Create the secondary author additional data (corporation, meeting, etc..)
     * @param array $contributors
     * @param array $data
     * @param string $entity
     * @return array
     */
    private function getSecondaryAuthorData(array &$contributors, array $data,
            string $entity): array {
        if (count($data) > 0) {
            foreach ($data as $key => $value) {
                if (($key % 3) == 0) { // First of 3 values
                    $name = $value;
                } else if (($key % 3) == 1) { // Second of 3 values
                    $role = $value;
                } else if (($key % 3) == 2) { // Third and last of 3 values
                    $auth = $value;

                    // We have all values now, add them to the return array:
                    $contributors[$role][] = [
                        'entity' => $entity,
                        'name' => $name,
                        'role' => $role,
                        'auth' => $auth,
                        'primary' => false
                    ];
                }
            }
        }
        return $contributors;
    }

    /**
     * Remove the duplicates from the authors array
     * @param array $contributors
     * @return array
     */
    private function removeContributorDuplicates(array &$contributors): array {
        foreach ($contributors as $k => $v) {
            $names = [];
            foreach ($v as $ok => $ov) {
                if (count($names) > 0) {
                    if (in_array($ov['name'], $names)) {
                        unset($contributors[$k][$ok]);
                    } else {
                        $names[] = $ov['name'];
                    }
                } else {
                    $names[] = $ov['name'];
                }
            }
        }
        return $contributors;
    }

    /**
     * Create the MARC 700 field authors for the gui core-phtml
     * @param type $names
     * @param type $roles
     * @return array
     */
    private function createSecondaryAuthors($names, $roles): array {
        $authors2 = array();
        foreach ($names as $key1 => $value1) {
            // store IP
            $authors2[$key1]['name'] = $value1;
            // store type of cam
            $authors2[$key1]['role'] = $roles[$key1] ?? '';
        }
        return $authors2;
    }

    /**
     * AK: Get names of all contributor
     * https://redmine.acdh.oeaw.ac.at/issues/19499 
     * TODO: Fallback to MarcXML
     *
     * @return array
     */
    public function getContributorsForSearchListView(): array {
        // Primary authors
        $primPers = (array) ($this->fields['author'] ?? []);
        $primRole = (array) ($this->fields['author_role'] ?? []);
        $primCorpRole = (array) ($this->fields['author_corporate_role'] ?? []);
        $primCorp = (array) ($this->fields['author_corporate'] ?? []);
        $primMeet = (array) ($this->fields['author_meeting_txt'] ?? []);
        // Secondary authors
        $secPers = (array) ($this->fields['author2'] ?? []);
        $secRole = (array) ($this->fields['author2_role'] ?? []);
        $secCorp = (array) ($this->fields['author2_corporate_txt_mv'] ?? []);
        $secMeet = (array) ($this->fields['author2_meeting_txt_mv'] ?? []);

        $authorsCorp = $this->mergeAuthorsAndRoles($primCorp, $primCorpRole);
        $authors = $this->mergeAuthorsAndRoles($primPers, $primRole);
        $authors2 = $this->mergeAuthorsAndRoles($secPers, $secRole);
        // Merge array
        /*
          echo '<pre>';
          var_dump($authors);
          var_dump($authors2);
          echo '</pre>';
         */
        $merged = array_merge($authors, $authorsCorp, $primMeet, $authors2, $secCorp, $secMeet);
        $mergedWithOutRole = array_merge($primPers, $primCorp, $primMeet, $secPers, $secCorp, $secMeet);

        //we have to pass two arrays, one if for the display text with the authors and roles
        //the second is for the search url, because if we add the roles also, then the search will fails
        // Return merged array
        return array('text' => $merged, 'url' => $mergedWithOutRole);
    }

    /**
     * https://redmine.acdh.oeaw.ac.at/issues/19499
     * @param type $names
     * @param type $roles
     * @return array
     */
    private function mergeAuthorsAndRoles($names, $roles): array {
        $authors = array();
        foreach ($names as $key1 => $value1) {
            if (count($authors) > 0) {
                foreach ($authors as $ak => $av) {
                    if ($av['name'] == $value1) {
                        $authors[$ak]['role'][] = $roles[$key1];
                    } else {
                        $authors[$key1] = array("name" => $value1, "role" => array(
                                $roles[$key1]));
                    }
                }
            } else {
                $authors[$key1] = array("name" => $value1, "role" => array($roles[$key1]));
            }
        }
        return $this->mergeRolesForSearchView($authors);
    }

    /**
     * https://redmine.acdh.oeaw.ac.at/issues/19499
     * @param type $names
     * @param type $roles
     * @return array
     */
    private function mergeRolesForSearchView(array $authors) {
        $result = [];
        foreach ($authors as $k => $v) {
            $result[$k] = $v['name'] . ' [ ' . implode(", ", $v["role"]) . ' ]';
        }
        return $result;
    }

    /**
     * https://redmine.acdh.oeaw.ac.at/issues/14549
     * 
     * Extend the Description tab with the responsibility Note
     * @return array
     */
    public function getExtraDescriptionData(): array {
        $marc = $this->getMarcRecord();
        $value = $this->getMarcFieldsAsObject($marc, '245', null, null);

        $return = array();
        if (count($value) > 0) {
            foreach ($value as $v) {
                if (isset($v->c)) {
                    $return[] = $v->c;
                }
            }
        }
        return $return;
    }

    private function addAuthorsToContributorsArray(array $authors): array {
        $contributors = [];
        if (count($authors) > 0) {

            $basicRole = $authors[0]['role'];
            foreach ($authors as $value) {
                if (!isset($value['role'])) {
                    $value['role'] = $basicRole;
                }
                // We have all values now, add them to the return array:
                $contributors[$value['role']][] = [
                    'entity' => 'person',
                    'name' => $value['name'],
                    'role' => $value['role'],
                    'auth' => null,
                    'primary' => false
                ];
            }
        }

        return $contributors;
    }

    /**
     * https://redmine.acdh.oeaw.ac.at/issues/20271
     * 
     * Add the subfield 0 and remove the , from the subfield join
     * @param type $extended
     * @return type
     */
    public function getAllSubjectHeadings($extended = false) {

        $returnValue = [];
        $subjectFields = $this->getMarcRecord()->getFields('689');

        $ind1 = 0;
        foreach ($subjectFields as $subjectField) {
            $ind1 = $subjectField->getIndicator(1);
            $ind2 = $subjectField->getIndicator(2);

            if (is_numeric($ind1) && is_numeric($ind2)) {
                $subfields = $subjectField->getSubfields('[axvtyzbcgh0]', true);
                $subfieldData = [];
                foreach ($subfields as $subfield) {
                    $subfieldData[] = $subfield->getData();
                }
                $returnValue[$ind1][$ind2] = (join(' ', $subfieldData));
            }
        }

        $subjectFields982 = $this->getMarcRecord()->getFields('982');
        $fieldCount = $ind1 + 1;
        foreach ($subjectFields982 as $subjectField982) {
            $ind1 = $subjectField982->getIndicator(1);
            $ind2 = $subjectField982->getIndicator(2);
            if (empty(trim($ind1)) && empty(trim($ind2))) {

                $subfields = $subjectField982->getSubfields('a', false);
                if (!empty($subfields)) {
                    $subfieldData = [];
                    $tokenCount = 0;
                    foreach ($subfields as $subfield) {
                        $subfieldContent = $subfield->getData();
                        $tokens = preg_split("/\s+[\/-]\s+/", $subfieldContent);
                        foreach ($tokens as $token) {
                            $returnValue[$fieldCount][$tokenCount] = $token;
                            $tokenCount++;
                        }
                    }
                    $fieldCount++;
                }
            }
        }
        $returnValue = array_map(
                'unserialize', array_unique(array_map('serialize', $returnValue))
        );
        return $this->stripNonSortingChars($returnValue);
    }

    /**
     * https://redmine.acdh.oeaw.ac.at/issues/19917
     * 
     * Get the record view subtitle
     * @return string
     */
    public function getSubTitle() {
        $marc = $this->getMarcRecord();
        $st = $this->getMarcFieldsAsObject($marc, 249, null, null, null);
        $str = "";

        foreach ($st as $k => $v) {
            if (isset($v->a) && isset($v->v)) {

                array_map(function ($a, $b) use (&$str) {
                    if (!empty($a) && !empty($b)) {
                        $str .= $a . ' (' . $b . ')<br/>';
                    } else if (!empty($a)) {
                        $str .= $a . '<br/>';
                    }
                }, $v->a, $v->v);
            } else if (isset($v->a)) {
                array_map(function ($a) use (&$str) {
                    if (!empty($a)) {
                        $str .= $a . '<br/>';
                    }
                }, $v->a);
            }
        }


        return $str;
    }

    /**
     * https://redmine.acdh.oeaw.ac.at/issues/19917
     * 
     * Get the Record view title
     * @return type
     */
    public function getWholeTitle() {
        $field245C = $this->getMarcFieldsAsObject($this->getMarcRecord(), 245, null, null, [
            'c']);
        if (isset($field245C[0]->c)) {
            $field245C = $field245C[0]->c;
        } else {
            $field245C = "";
        }
        // AK: Join the title and title section together. With array_filter we remove
        // possible empty values.
        return implode(
                ' / ',
                array_filter(
                        [trim($this->getTitle()), trim($this->getTitleSection()), $field245C],
                        array($this, 'filterCallback')
                )
        );
    }

    /**
     * Get the full title of the record.
     * 
     * AK: Separate by colon
     *
     * @return string
     */
    public function getTitle() {

        $matches = $this->getFieldArray('245', ['a', 'b'], true, ' : ');
        $title880 = $this->getTitle880();
        $str = "";
        //if we have 880 title then we fetch that first
        if (!empty($title880)) {
            $str .= $title880;
        }

        if (is_array($matches) && count($matches) > 0) {
            if (!empty($str)) {
                $str .= '<br/>';
            }
            $str .= $this->stripNonSortingChars($matches[0]);
        }
        return $str;
    }

    /**
     * https://redmine.acdh.oeaw.ac.at/issues/19490
     * If the 245 has field 6 with value 880- then we will fetch the 880 title first
     * @return string
     */
    private function getTitle880() {
        //first check if the actual 245 has a field 6, if yes then we fetch the 880
        $field880 = $this->getMarcFieldsAsObject($this->getMarcRecord(), 880, null, null, null);
        $str = "";
        $fchk = ['a', 'b', 'c'];
        $fields = [];
        foreach ($field880 as $k => $val) {
            $f = 6;
            if (isset($val->$f)) {
                foreach ($val->$f as $v) {
                    if (strpos($v, '245-') !== false) {
                        $fields[] = $k;
                    }
                }
            }
        }

        foreach ($fields as $f) {
            foreach ($fchk as $fc) {
                if (isset($field880[$f]->$fc)) {
                    $str .= implode(' / ', $field880[$f]->$fc);
                }
            }
        }
        return $str;
    }

    /**
     * https://redmine.acdh.oeaw.ac.at/issues/19917
     * Display the 249 B fields on the record view table
     * @return string
     */
    public function getTitleAddition(): string {
        $str = "";
        $field = $this->getFieldsByKeysAndField($this->getMarcRecord(), ['b'], '249');

        if (isset($field['b']) && count($field['b']) > 0) {
            foreach ($field['b'] as $f) {
                $str .= $f;
            }
        }
        return $str;
    }

    /**
     * https://redmine.acdh.oeaw.ac.at/issues/19917
     * Display the 249 C fields on the record view table
     * @return string
     */
    public function getStatementOfResponsibility(): string {
        $str = "";
        $field = $this->getFieldsByKeysAndField($this->getMarcRecord(), ['c'], '249');

        if (isset($field['c']) && count($field['c']) > 0) {
            foreach ($field['c'] as $f) {
                $str .= $f;
            }
        }
        return $str;
    }

    /**
     * Get publication details from 264 fields. This function take several variants
     * of subfield notation into account, like e. g. multiple subfields a and b.
     * 
     * For these special cases in austrian libraries, see:
     * https://wiki.obvsg.at/Katalogisierungshandbuch/Kategorienuebersicht264FE
     * 
     * ACDH-CH
     * https://redmine.acdh.oeaw.ac.at/issues/19490#264
     * @return array
     */
    public function getPublicationDetailsAut() {
        // Create result array as return value
        $result = [];

        // Get all fields 264 and add their data to an easy-to-process array
        $fs264 = $this->getFieldsAsArray('264');

        // Iterate over each field 264
        foreach ($fs264 as $f264) {
            $subfieldResult = [];

            // Get subfields
            $subfs = $f264['subfs'];

            // Array columns of subfields a, b and c            
            $subfsA = array_column($subfs, 'a');
            $subfsB = array_column($subfs, 'b');
            $subfsC = array_column($subfs, 'c');
            $subfs6 = array_column($subfs, '6');

            //$field880_6 = $this->fetchOrtVerlagAdditionalData($subfs6[0]);
            // Join subfields c (= dates) to a string
            $dates = (!empty($subfsC)) ? join(', ', $subfsC) : null;

            // Check if subfields a and b exists
            if (!empty($subfsA) && !empty($subfsB)) {

                // Create pairs of subfields a (= place) and b (= publisher name) if
                // their counts are the same. The result is a colon separated string
                // like: "Place : Publisher Name"
                if (count($subfsA) === count($subfsB)) {
                    $size = count($subfsA);
                    for ($i = 0; $i < $size; $i++) {
                        $subfieldResult[] = $subfsA[$i] . ' : ' . $subfsB[$i];
                    }
                } else {
                    // If the count is of subfields a and b is not the same, just
                    // join them separately and then join them again, separated by
                    // a colon.
                    $subfieldResult[] = join(', ', $subfsA) . ' : ' . join(', ',
                                    $subfsB);
                }
            } else {
                // If subfield a or b doesn't exist, join just the existing one
                if (!empty($subfsA)) {
                    $subfieldResult[] = join(', ', $subfsA);
                }
                if (!empty($subfsB)) {
                    $subfieldResult[] = join(', ', $subfsB);
                }
            }

            // If dates exist, add them as last item to the array            
            if ($dates != null) {
                $subfieldResult[] = $dates;
            }

            $finalData = join(', ', $subfieldResult);
            if (!empty($field880_6)) {
                $finalData = $field880_6 . '<br/>' . $finalData;
            }

            // Create result array if we have results
            if (!empty($subfieldResult)) {
                $result[] = [
                    // Add indicators to the return array. This makes it possible to
                    // display the different meanings the publication details could
                    // have to the user.
                    'ind1' => $f264['ind1'],
                    'ind2' => $f264['ind2'],
                    // Join the processed data from the subfields of one single field
                    // 264 to a comma separated string.
                    'data' => $finalData
                ];
            }
        }

        return $result;
    }

    /**
     * ACDH-CH
     * https://redmine.acdh.oeaw.ac.at/issues/19490#264
     * 
     * fetch the 264-6 and 880-6 and if their last two numbers are the same, then 
     * we have to fetch the 880-6 values and display them
     * 
     * @param string $st
     * @return string
     */
    private function fetchOrtVerlagAdditionalData(string $st): string {
        $st = str_replace('880-', '', $st);
        $fs880_6 = $this->getFieldsByKeysAndField($this->getMarcRecord(), ['6', 'a', 'b', 'c'], '880');
        $fetch = [];
        $str = "";
        if (isset($fs880_6['6'])) {
            foreach ($fs880_6['6'] as $fk => $fv) {
                $nv = str_replace('264-', '', substr($fv, 0, strpos($fv, "/")));
                if ($nv == $st) {
                    $fetch[] = $fk;
                }
            }
        } else {
            return "";
        }

        foreach ($fetch as $k) {
            if (isset($fs880_6['a'][$k])) {
                $str = $fs880_6['a'][$k];
            }
            if (isset($fs880_6['b'][$k])) {
                if (!empty($str)) {
                    $str .= " : ";
                }
                $str .= $fs880_6['b'][$k];
            }
            if (isset($fs880_6['c'][$k])) {
                if (!empty($str)) {
                    $str .= ", ";
                }
                $str .= $fs880_6['c'][$k];
            }
        }
        return strip_tags($str);
    }

    public function getPublishers(): array {

        return $this->getPublicationInfo('b');
        //return $this->getPublishersAdditional();
    }

    private function getPublishersAdditional() {
        $fs880b = $this->getFieldsByKeysAndField($this->getMarcRecord(), ['b', '6'], '880');
        $fs264b = $this->getFieldsByKeysAndField($this->getMarcRecord(), ['b', '6'], '264');
        $fetch = [];

        if (isset($fs880b['6'])) {
            foreach ($fs880b['6'] as $fk => $fv) {
                if (strpos($fv, '264-') !== false) {
                    $nv = str_replace('264-', '', substr($fv, 0, strpos($fv, "/")));

                    if (array_keys($fs264b['6'], '880-' . $nv)) {

                        $key2646_arr = array_keys($fs264b['6'], '880-' . $nv);
                        $key2646 = "";
                        if (count((array) $key2646_arr) > 0) {
                            $key2646 = $key2646_arr[0];
                            $fetch[] = $fs264b['b'][$key2646] . ' / ' . $fs880b['b'][$fk];
                        } elseif (isset($fs880b['b'][$fk])) {
                            $fetch[] = $fs880b['b'][$fk];
                        }
                    }
                }
            }
        }
        return $fetch;
    }

}
