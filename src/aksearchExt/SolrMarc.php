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
        $retVal         = [];
        $urls           = $this->getMarcRecord()->getFields('856');
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
            $label    = $label ? $label->getData() : $url;
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
            'id'    => $parents[0],
            'title' => $this->getContainerTitle(),
            'volNo' => '',
        ]];
    }

    /**
     * Check the actual resource is Open Access or not
     * @return bool
     */
    public function getOpenAccessData(): bool {
        return $this->getMarcRecord()->getField(506) !== false;
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

    public function getRealTimeHoldings() {
        $id = $this->getUniqueID();

        // get normal holdings
        $results                       = $this->holdLogic->getHoldings($id, $this->tryMethod('getConsortialIDs'));
        $results['lkrHoldingsSummary'] = [];

        // <-- LKR
        //     https://redmine.acdh.oeaw.ac.at/issues/14550
        //     https://redmine.acdh.oeaw.ac.at/issues/19566
        $marc       = $this->getMarcRecord();
        $lkrRecords = $this->getMarcFieldsAsObject($marc, 773, 1, 8, ['w']);
        foreach ($lkrRecords as $lkrRecord) {
            if (empty($lkrRecord->w) || empty($lkrRecord->g)) {
                continue;
            }
            $ctrlnum = preg_replace('/^.*[)]/', '', $lkrRecord->w);
            $param   = new ParamBag(['fl' => 'id']);
            $record  = $this->searchService->search('Solr', new Query("ctrlnum:$ctrlnum"), 0, 1, $param)->first();
            //print_r(['LKR1', $ctrlnum, (int)is_object($record)]);
            if ($record !== null) {
                $lkrId      = $record->getRawData()['id'];
                // $matchValue is an array (!) as there might be many subfield g values
                // for now we optimistically assume the prefix (which we skip using preg_replace())
                // doesn't count
                $matchValue = preg_replace('/^.*:/', '', $lkrRecord->g);
                //print_r(['LKR2', $lkrId, $matchValue]);
                $lkrResults = $this->holdLogic->getHoldings($lkrId, $this->tryMethod('getConsortialIDs'));
                // add only items matching the barcode/enumeration_a
                foreach ($lkrResults['holdings'] as $group => $holding) {
                    $holdingId        = null;
                    $items            = $holding['items'];
                    $holding['items'] = [];
                    foreach ($items as $item) {
                        $holdingId  = $item['holding_id'];
                        $itemValues = [$item['barcode'], $item['enumeration_a'],
                            $item['enumeration_b']];
                        if (count(array_intersect($matchValue, $itemValues)) > 0) {
                            //print_r($item);
                            $holding['items'][] = $item;
                        }
                    }
                    if (count($holding['items']) > 0) {
                        if (!isset($results['holdings'][$group])) {
                            $results['holdings'][$group] = [
                                'items'    => [],
                                'lkrItems' => [],
                            ];
                        }
                        if (!isset($results['holdings'][$group]['lkrItems'])) {
                            $results['holdings'][$group]['lkrItems'] = [];
                        }
                        foreach ($holding['items'] as $i) {
                            $results['holdings'][$group]['lkrItems'][] = $i;
                        }
                        if (!isset($results['lkrHoldingsSummary'][$group])) {
                            $results['lkrHoldingsSummary'][$group] = [];
                        }
                        $results['lkrHoldingsSummary'][$group][] = $lkrResults['holdingsSummary'][$group];
                    }
                }
            }
        }
        //--> LKR
        //<-- E-media Link for the electronic holdings
        //    https://redmine.acdh.oeaw.ac.at/issues/19474
        if (isset($results['electronic_holdings'])) {
            $electronic = null;
            foreach ($this->getMarcFieldsAsObject($marc, 'AVE', null, null) as $ave) {
                if (!empty($ave->x)) {
                    $electronic = $ave->x;
                    break;
                }
            }
            if (!empty($electronic)) {
                foreach ($results['electronic_holdings'] as &$v) {
                    $v['e_url'] = $electronic;
                }
                unset($v);
            }
        }

        return $results;
    }

    /**
     * Display Exemplarbeschreibung and Ex Libris data on the holdingss tab
     * https://redmine.acdh.oeaw.ac.at/issues/19506
     */
    public function getHolding991992() {
        $id      = $this->getUniqueID();
        $marc    = $this->getMarcRecord();
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
                    $iv['exLibris']             = $this->getFieldsByKeysAndField($marc, $keys991, '991');
                    $iv['exemplarbeschreibung'] = $this->getFieldsByKeysAndField($marc, $keys992, '992');
                }
            }
        }
    }

    private function getFieldsByKeysAndField($marc, $keys, $field): array {
        $values = [];
        foreach ($this->getMarcFieldsAsObject($marc, $field, null, null) as $field) {
            foreach ($keys as $k) {
                $values[$k] = [];
                if (!empty($field->$k)) {
                    $values[$k][] = $field->$k . ';<br>';
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
        $secRole  = $this->fields['author2_role'] ?? null;
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
                'entity'  => 'corporation',
                'name'    => $corpName,
                'role'    => $corpRole,
                'auth'    => $corpAuth,
                'primary' => true
            ];
        }

        // Add primary meeting authors to array (values from Marc21 field 111)
        if ($meetingName) {
            $contributors[$meetingRole][] = [
                'entity'  => 'meeting',
                'name'    => $meetingName,
                'role'    => $meetingRole,
                'auth'    => $meetingAuth,
                'primary' => true
            ];
        }
        // Add primary person authors to array (values from Marc21 field 700)
        if ($authors) {
            $basicRole = $authors[0]['role'];
            foreach ($authors as $value) {
                if (!isset($value['role'])) {
                    $value['role'] = $basicRole;
                }
                // We have all values now, add them to the return array:
                $contributors[$value['role']][] = [
                    'entity'  => 'person',
                    'name'    => $value['name'],
                    'role'    => $value['role'],
                    'auth'    => null,
                    'primary' => false
                ];
            }
        }

        // Add secondary person authors to array (values from Marc21 field 700)
        if ($authors2) {
            $basicRole = $authors2[0]['role'];
            foreach ($authors2 as $value) {
                if (!isset($value['role'])) {
                    $value['role'] = $basicRole;
                }
                // We have all values now, add them to the return array:
                $contributors[$value['role']][] = [
                    'entity'  => 'person',
                    'name'    => $value['name'],
                    'role'    => $value['role'],
                    'auth'    => null,
                    'primary' => false
                ];
            }
        }

        // Add secondary corporation authors to array (values from Marc21 field 710)
        if ($secCorps) {
            foreach ($secCorps as $key => $value) {
                if (($key % 3) == 0) { // First of 3 values
                    $name = $value;
                } else if (($key % 3) == 1) { // Second of 3 values
                    $role = $value;
                } else if (($key % 3) == 2) { // Third and last of 3 values
                    $auth = $value;

                    // We have all values now, add them to the return array:
                    $contributors[$role][] = [
                        'entity'  => 'corporation',
                        'name'    => $name,
                        'role'    => $role,
                        'auth'    => $auth,
                        'primary' => false
                    ];
                }
            }
        }

        // Add secondary meeting authors to array (values from Marc21 field 711)
        if ($secMeetings) {
            foreach ($secMeetings as $key => $value) {
                if (($key % 3) == 0) { // First of 3 values
                    $name = $value;
                } else if (($key % 3) == 1) { // Second of 3 values
                    $role = $value;
                } else if (($key % 3) == 2) { // Third and last of 3 values
                    $auth = $value;

                    // We have all values now, add them to the return array:
                    $contributors[$role][] = [
                        'entity'  => 'meeting',
                        'name'    => $name,
                        'role'    => $role,
                        'auth'    => $auth,
                        'primary' => false
                    ];
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
        $primPers     = (array) ($this->fields['author'] ?? []);
        $primRole     = (array) ($this->fields['author_role'] ?? []);
        $primCorpRole = (array) ($this->fields['author_corporate_role'] ?? []);
        $primCorp     = (array) ($this->fields['author_corporate'] ?? []);
        $primMeet     = (array) ($this->fields['author_meeting_txt'] ?? []);
        // Secondary authors
        $secPers      = (array) ($this->fields['author2'] ?? []);
        $secRole      = (array) ($this->fields['author2_role'] ?? []);
        $secCorp      = (array) ($this->fields['author2_corporate_txt_mv'] ?? []);
        $secMeet      = (array) ($this->fields['author2_meeting_txt_mv'] ?? []);

        $authorsCorp = $this->mergeAuthorsAndRoles($primCorp, $primCorpRole);
        $authors     = $this->mergeAuthorsAndRoles($primPers, $primRole);
        $authors2    = $this->mergeAuthorsAndRoles($secPers, $secRole);
        // Merge array
        $merged      = array_merge($authors, $authorsCorp, $primMeet, $authors2, $secCorp, $secMeet);

        // Return merged array
        return $merged;
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
            // store IP
            $authors[$key1] = $value1 . ' [' . $roles[$key1] . ']';
        }
        return $authors;
    }
}
