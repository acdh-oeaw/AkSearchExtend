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
use VuFind\View\Helper\Root\RecordLinker;
use VuFind\RecordTab\ComponentParts;
use VuFind\Marc\MarcReader;
use aksearchExt\container\IlsHoldingId;
use aksearchExt\container\HoldingData;
use aksearchExt\container\ItemData;
use aksearchExt\AcdhChHelper\Field880Helper;

class SolrMarc extends \AkSearch\RecordDriver\SolrMarc {

    private ?array $holdingIds = null;

    static private $acIdCallers = [
        RecordLinker::class . '::getChildRecordSearchUrl',
        ComponentParts::class . '::getResults',
    ];

    /**
     * Restores the getContainerTitle() to the VuFind original one
     * (AkSearch provided own version based on custom fields)
     * 
     * @see getConsolidatedParents()
     */
    public function getContainerTitle(): string {
        return \VuFind\RecordDriver\DefaultRecord::getContainerTitle();
    }

    /**
     * Returns Basisklassifikation
     * https://redmine.acdh.oeaw.ac.at/issues/19501
     * @return null|array<string>
     */
    public function getClassification(): array | null {
        $values = $this->fields['basiskl_str_mv'] ?? false;
        //return $values ? implode("<br/>", $values) : null;
        return $values ? $values : null;
    }

    public function getGenres(): array {
        $genres = $this->fields['bibForm_txtF_mv'] ?? [];
        if ($genres === []) {
            foreach ($this->getMarcReader()->getFields('655') as $field) {
                $genre = $this->getMarcReader()->getSubfield($field, 'a');
                if ($genre !== '') {
                    $genres[] = $genre;
                }
            }
        }
        return (array) $genres;
    }

    /**
     * Search for URLs in MARC 856 taking URL from subfield u and label from
     * subfields 3 or x
     * 
     * https://redmine.acdh.oeaw.ac.at/issues/19527
     * @return array<array<string, string>>
     */
    public function getURLs(): array {
        $reader         = $this->getMarcReader();
        $labelSubfields = ['3', 'x'];
        $retVal         = [];
        $urls           = $reader->getFields('856');
        foreach ($urls as $urlField) {
            $url = $reader->getSubfield($urlField, 'u');
            if (empty($url)) {
                continue;
            }
            foreach ($labelSubfields as $subfield) {
                $label = $reader->getSubfield($urlField, $subfield);
                if (!empty($label)) {
                    break;
                }
            }
            $label    = empty($label) ? $url : $label;
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
     *
     * @return null|array<array<string, string>>
     */
    public function getConsolidatedParents(): array | null {
        $parents = $this->fields['hierarchy_parent_id'] ?? [];
        $parents = is_array($parents) ? $parents : [$parents];
        if (count($parents) === 0) {
            return null;
        }

        if (!empty($this->getSuperiorDocument())) {
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
     */
    public function getOpenAccessData(): bool {
        $oa = $this->getMarcFieldsAsObject('506', null, null, [
            'f']);
        if (isset($oa[0]->f) && strtolower($oa[0]->f) == "unrestricted online access") {
            return true;
        }
        return false;
    }

    /**
     * Return alternative titles, appending linked original-script 880$t values
     * to titles sourced from 505$t.
     *
     * @return array|null
     */
    public function getTitleAlt() {
        $titles = parent::getTitleAlt();
        if (!is_array($titles) || count($titles) === 0) {
            return $titles;
        }

        $fields505 = $this->getMarcFieldsAsObject('505', null, null, ['6', 't']);
        $fields880 = $this->getMarcFieldsAsObject('880', null, null, ['6', 't']);
        $field880Helper = new Field880Helper($fields880);
        $matchedTitles = [];

        foreach ($fields505 as $field505) {
            if (!isset($field505->t, $field505->{6})) {
                continue;
            }

            $mainTitles = is_array($field505->t) ? $field505->t : [$field505->t];
            $linkages   = is_array($field505->{6}) ? $field505->{6} : [$field505->{6}];
            $originalTitles = [];
            foreach ($linkages as $linkage) {
                $originalTitles = array_merge(
                    $originalTitles,
                    $field880Helper->getLinkedSubfieldValues('505', $linkage, ['t'])
                );
            }
            $originalTitles = array_values(array_unique($originalTitles));
            if (count($originalTitles) === 0) {
                continue;
            }

            foreach ($mainTitles as $mainTitle) {
                foreach ($titles as $index => $title) {
                    if (!isset($matchedTitles[$index]) && $title === $mainTitle) {
                        $titles[$index] .= ' (' . implode('; ', $originalTitles) . ')';
                        $matchedTitles[$index] = true;
                        break;
                    }
                }
            }
        }

        return $titles;
    }

    /**
     * Return MARC or ISIL Code of org governing the identifier in field 001 from field 003
     * @return string
     */
    public function getOwningInstitution(): string {
        $owningInstitution = $this->getMarcReader()->getField('003');
        return is_string($owningInstitution) && $owningInstitution !== ''
            ? $owningInstitution : 'none';
    }

    /**
     * https://redmine.acdh.oeaw.ac.at/issues/20340
     */
    public function isPeerReviewed(): bool {
        $pr = $this->getMarcFieldsAsObject('500', null, null, [
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
    public function getUniqueID(): string {
        $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1];
        $caller = ($caller['class'] ?? '') . '::' . ($caller['function'] ?? '');
        if (!in_array($caller, self::$acIdCallers)) {
            return parent::getUniqueID();
        }
        return $this->getMarcReader()->getField('009');
    }

    /**
     * Collects all possible holding identifiers, including information required
     * for LKR items filtering.
     * 
     * @return array<IlsHoldingId>
     */
    public function getHoldingIds(): array {
        if ($this->holdingIds !== null) {
            return $this->holdingIds;
        }
        $ids = [];
        $uniqueId = $this->getUniqueID();
        if (ctype_digit($uniqueId)) {
            $ids[] = new IlsHoldingId($uniqueId);
            $lkrRecords = $this->getMarcFieldsAsObject('773', 1, 8, ['w', 'g']);
            foreach ($lkrRecords as $lkrRecord) {
                if (empty($lkrRecord->w) || empty($lkrRecord->g)) {
                    continue;
                }
                $ctrlnum = preg_replace('/^.*[)]/', '', $lkrRecord->w);
                $param   = new ParamBag(['fl' => 'id']);
                $record  = $this->searchService->invoke(new \VuFindSearch\Command\SearchCommand('Solr', new Query("ctrlnum:$ctrlnum"), 0, 1, $param))->getResult()->first();
                if ($record !== null) {
                    // There might be many subfield g values (!)
                    // For now we optimistically assume the prefix (which we skip using preg_replace()) doesn't count
                    // There can be multiple LKR records per single holding - collect them in one entry (#24391)
                    $rawId = $record->getRawData()['id'];
                    if (!ctype_digit((string) $rawId)) {
                        continue;
                    }
                    $itemFilter = array_map(
                        static fn($value) => preg_replace('/^.*:/', '', $value),
                        (array) $lkrRecord->g
                    );
                    if (!isset($ids[$rawId])) {
                        $ids[$rawId] = new IlsHoldingId($rawId, null, $itemFilter, true);
                    } else {
                        $ids[$rawId]->itemFilter = array_merge($ids[$rawId]->itemFilter, $itemFilter);
                    }
                }
            }
        }
        return $this->holdingIds = array_values($ids);
    }

    /**
     * LKR:
     * - https://redmine.acdh.oeaw.ac.at/issues/14550
     * - https://redmine.acdh.oeaw.ac.at/issues/19566
     * 
     * @return array<string, mixed>
     */
    public function getRealTimeHoldings(): array {
        $results = $this->holdLogic->getHoldings($this->getHoldingIds());

        //<-- Electronic holdings
        //    https://redmine.acdh.oeaw.ac.at/issues/19474
        $ave = $this->getMarcFieldsAsObject('AVE');

        if (count($ave) > 0) {
            foreach ($this->getMarcFieldsAsObject('AVE') as $ave) {
                if (!empty($ave->x) && !empty($ave->Z)) {
                    $holding                                 = new HoldingData(new IlsHoldingId($ave->Z));
                    $holding->items[]                        = ItemData::fromAve($ave);
                    $results['electronic_holdings'][$ave->Z] = $holding;
                } else if (!empty($ave->Z)) {
                    $holding                                         = new HoldingData(new IlsHoldingId($ave->Z));
                    $holding->items[]                                = ItemData::fromAve($ave);
                    $results['electronic_holdings_extra'][][$ave->Z] = $holding;
                }
            }
        }
        $this->mergeElectronicHoldingsExtraData($results);
        //-->
        return $results;
    }

    /**
     * https://redmine.acdh.oeaw.ac.at/issues/20327
     */
    private function mergeElectronicHoldingsExtraData(array &$results): void {
        if (empty($results['electronic_holdings']) || empty($results['electronic_holdings_extra'])) {
            return;
        }
        $extra = $results['electronic_holdings_extra'] ?? [];
        foreach ($results['electronic_holdings'] as $k => $v) {
            foreach ($v->items as $ik => $iv) {
                foreach ($extra as $ek => $ev) {
                    if (!isset($ev[$k]->items[$ik]) || $ev[$k]->items[$ik]->mmsId !== $k) {
                        continue;
                    }
                    $iv->portfolios[$ek] = $ev[$k]->items[$ik]->portfolio->merge($iv->portfolios[$ek] ?? null);
                }
            }
        }
    }

    /**
     * Check if we have electronic data, if yes then fetch the values
     */
    public function hasElectronicHoldings(): bool {
        $ave = $this->getMarcFieldsAsObject('AVE');
        if (count($ave) > 0) {
            foreach ($this->getMarcFieldsAsObject('AVE') as $ave) {
                if (!empty($ave->x)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * We have to check the 991 and 992 fields for the exemplarspezifika tab
     * if there is no fields then we will not display the tab
     */
    public function is911992(): bool {
        $active = false;
        if (count($this->getMarcFieldsAsObject('991', null, null)) > 0) {
            $active = true;
        } else if (count($this->getMarcFieldsAsObject('992', null, null)) > 0) {
            $active = true;
        }


        return $active;
    }

    /**
     * Display Exemplarbeschreibung and Ex Libris data on the holdings tab
     * https://redmine.acdh.oeaw.ac.at/issues/19506
     */
    public function getHolding991992(): array {
        $id      = $this->getUniqueID();
        $marc    = $this->getMarcReader();
        // get holdings because we have to use the holdings result array to display the data inside the tab
        //$results = $this->holdLogic->getHoldings($id, $this->tryMethod('getConsortialIDs'));
        $results = $this->getRealTimeHoldings();
        $this->getHolding991992Data($marc, $results['holdings']);

        return $this->formatHolding991992Data($results['holdings']);
    }

    /**
     * @return array<mixed>
     */
    private function formatHolding991992Data(array $data): array {
        $exemplerdata = [];
        foreach ($data as $name => $holding) {
            if (isset($holding->items)) {
                //$exemplerdata[$name];
                foreach ($holding->items as $item) {
                    if ($item->exemplarbeschreibung) {
                        $exemplerdata[$name]['exemplarbeschreibung'] = $item->exemplarbeschreibung;
                    }

                    if ($item->exLibris) {
                        $exemplerdata[$name]['exLibris'] = $item->exLibris;
                    }
                }
            }
        }
        return $exemplerdata;
    }

    /**
     * https://redmine.acdh.oeaw.ac.at/issues/19506
     * Display Exemplarbeschreibung and Ex Libris data on the holdinstab
     */
    private function getHolding991992Data(MarcReader $marc, array &$data): void {
        //Exemplarbeschreibung
        $keys992 = ['8', 'b', 'c', 'd', 'e', 'f', 'k', 'l', 'm', 'p', 'q', 'r', 's'];
        //exLibris
        $keys991 = ['8', 'a', 'b', 'c', 'd', 'f', 'i', 'j', 'k', 'l', 'm', 't'];

        foreach ($data as &$v) {
            if (isset($v->items)) {
                foreach ($v->items as &$iv) {
                    $iv->exLibris             = $this->getFieldsByKeysAndField($keys991, '991');
                    $iv->exemplarbeschreibung = $this->getFieldsByKeysAndField($keys992, '992');
                }
            }
        }
    }

    /**
     * @param array<string> $keys
     * @return array<string, array<string>>
     */
    private function getFieldsByKeysAndField(array $keys, string $field): array {
        $values = [];
        foreach ($this->getMarcFieldsAsObject($field, null, null) as $field) {
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
     * @return array<\stdClass>
     */
    private function getMarcFieldsAsObject(string $field,
                                           int | null $ind1 = null,
                                           int | null $ind2 = null,
                                           bool | array $singleValueAsLiteral = true): array {
        $marc   = $this->getMarcReader();
        $fields = [];
        foreach ($marc->getFields(sprintf('%03d', $field)) as $i) {
            if (is_array($i) && ($ind1 !== null && intval($i['i1']) !== $ind1 || $ind2 !== null && intval($i['i2']) !== $ind2)) {
                continue;
            }
            $fieldData = [];
            foreach ($i['subfields'] as $subfield) {
                $subcode               = $subfield['code'];
                $fieldData[$subcode]   ??= [];
                $fieldData[$subcode][] = $subfield['data'];
            }
            foreach ($fieldData as $k => &$v) {
                if (count($v) === 1 && ($singleValueAsLiteral === true || is_array($singleValueAsLiteral) && in_array($k, $singleValueAsLiteral))) {
                    $v = $v[0];
                }
            }
            unset($v);
            $fields[] = (object) $fieldData;
        }
        return $fields;
    }

    /**
     * AK: Get all possible contributors grouped by role in the right order.
     * 
     * TODO: Make that function shorter and cleaner if possible! Implement a fallback
     * to MarcXML!
     *
     * @return array<string, mixed> An array with all contributors grouped by role
     */
    public function getContributorsByRole(): array {
        // Initialize return variable
        $contributors = [];

        // Initialize other variables
        $name = null;
        $role = null;
        $auth = null;

        // Get primary author
        $primaryName = $this->fields['author'] ?? null;
        $primaryRole = $this->fields['author_role'] ?? [];
        $primaryAuth = $this->fields['author_GndNo_str'] ?? null;

        $authors = array();
        if ($primaryName !== null) {
            $authors = $this->createSecondaryAuthors($primaryName, $primaryRole);
        }

        // Get primary corporate author
        $corpName = $this->fields['author_corporate'][0] ?? null;
        $corpRole = $this->fields['author_corporate_role'][0] ?? null;
        // The role field is not populated for every MARC 110 import. Fall back
        // to the source record so the $4 value is still available for display.
        if ($corpRole === null) {
            $corpRole = $this->getPrimaryDisplayRoles('110', [])[0] ?? null;
        }
        $corpAuth = $this->fields['author_corporate_GndNo_str'] ?? null;

        // Get primary meeting author
        $meetingName = $this->fields['author_meeting_txt'] ?? null;
        $meetingRole = $this->fields['author_meeting_role_str'] ?? null;
        $meetingAuth = $this->fields['author_meeting_GndNo_str'] ?? null;

        // Get secondary authors
        $secNames = $this->fields['author2'] ?? null;
        $secRole = (array) ($this->fields['author2_role'] ?? []);
        $secOW = (array) ($this->fields['author2_original_writing_str_mv'] ?? []); 
            
        $authors2 = array();
        if ($secNames !== null) {
            $authors2 = $this->createSecondaryAuthors($secNames, $secRole, $secOW);
        }

        // Get secondary corporate authors
        $secCorps = $this->fields['author2_corporate_NameRoleGnd_str_mv'] ?? null;

        // Get secondary meeting authors
        $secMeetings = $this->fields['author2_meeting_NameRoleGnd_str_mv'] ?? null;

        // Add primary corporation authors to array (values from Marc21 field 110)
        if ($corpName) {
            $corpRole = is_string($corpRole) && $corpRole !== '' ? $corpRole : self::DEFAULT_AUTHOR_ROLE;
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
            $meetingRole = is_string($meetingRole) && $meetingRole !== '' ? $meetingRole : self::DEFAULT_AUTHOR_ROLE;
            $contributors[$meetingRole][] = [
                'entity'  => 'meeting',
                'name'    => $meetingName,
                'role'    => $meetingRole,
                'auth'    => $meetingAuth,
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
        $this->changeExtNameToBasicName($contributors);
        return $contributors;
    }

    /**
     * https://redmine.acdh.oeaw.ac.at/issues/19490#7xx-700-710-711
     * We have to create the name_extemd key because first we need to filter out
     * the duplications. and if it is filtered then we simply copy the extended value
     * into the name, and then we dont have to extend the gui in all templates to apply
     * the 700 rules for the name display.
     * @param array $contributors
     */
    private function changeExtNameToBasicName(array &$contributors): void {
        foreach ($contributors as $key => $val) {
            foreach ($val as $k => $v) {
                if (isset($v['name_extended'])) {
                    $contributors[$key][$k]['name'] = $v['name_extended'];
                }
            }
        }
    }

    /**
     * Create the secondary author additional data (corporation, meeting, etc..)
     * @param array $contributors
     * @param array $data
     * @return array
     */
    private function getSecondaryAuthorData(array &$contributors, array $data,
                                            string $entity): array {
        if (count($data) > 0) {
            // make phpstan happy
            $entity = $name   = $role   = $auth   = '';
            foreach ($data as $key => $value) {
                if (($key % 3) == 0) { // First of 3 values
                    $name = $value;
                } else if (($key % 3) == 1) { // Second of 3 values
                    $role = $value;
                } else if (($key % 3) == 2) { // Third and last of 3 values
                    $auth = $value;

                    // We have all values now, add them to the return array:
                    $contributors[$role][] = [
                        'entity'  => $entity,
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
                        $names[] = $this->removeBracketsFromText($ov['name']);
                    }
                } else {
                    $names[] = $this->removeBracketsFromText($ov['name']);
                }
            }
        }
        return $contributors;
    }

    /**
     * Create the MARC 700 field authors for the gui core-phtml
     */
    private function createSecondaryAuthors(array $names, array $roles,
                                            array $ow = []): array {
        $authors2 = array();
        foreach ($names as $key1 => $value1) {
            // store IP
            $authors2[$key1]['name'] = $value1;
            if (isset($ow[$key1]) && !empty($ow[$key1]) && $ow[$key1] !== 'noLinkedField') {
                $authors2[$key1]['name_extended'] = $ow[$key1] . ' / ' . $value1;
            }

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
        $primMeetRole = (array) ($this->fields['author_meeting_role_str'] ?? []);
        // Secondary authors
        $secPers      = (array) ($this->fields['author2'] ?? []);
        $secOW        = (array) ($this->fields['author2_original_writing_str_mv'] ?? [
            ]);
        $secRole      = (array) ($this->fields['author2_role'] ?? []);
        $secCorp      = (array) ($this->fields['author2_corporate_txt_mv'] ?? []);
        $secCorpRole  = (array) ($this->fields['author2_corporate_role_str_mv'] ?? []);
        $secMeet      = (array) ($this->fields['author2_meeting_txt_mv'] ?? []);
        $secMeetRole  = (array) ($this->fields['author2_meeting_role_str_mv'] ?? []);

        $authorsCorp       = $this->mergeAuthorsAndRoles($primCorp, $primCorpRole);
        $authors           = $this->mergeAuthorsAndRoles($primPers, $primRole);
        $authors2          = $this->mergeAuthorsAndRoles($secPers, $secRole, $secOW);
        // Merge array
        $authorsMeet       = $this->mergeAuthorsAndRoles($primMeet, $primMeetRole);
        $authorsSecCorp    = $this->mergeAuthorsAndRoles($secCorp, $secCorpRole);
        $authorsSecMeet    = $this->mergeAuthorsAndRoles($secMeet, $secMeetRole);
        $merged = [];
        $mergedWithOutRole = [];
        foreach ([$authors, $authorsCorp, $authorsMeet, $authors2, $authorsSecCorp, $authorsSecMeet] as $group) {
            foreach ($group as $entry) {
                $merged[] = $entry['text'];
                $mergedWithOutRole[] = $entry['name'];
            }
        }

        //we have to pass two arrays, one if for the display text with the authors and roles
        //the second is for the search url, because if we add the roles also, then the search will fails
        // Return merged array
        return array('text' => $merged, 'url' => $mergedWithOutRole);
    }

    /**
     * Display the MARC 1XX $4 role code and, when available, the readable $e
     * term. Fall back to the indexed role when the source $4 is absent.
     *
     * @param string $tag MARC field tag
     * @param array $fallbackRoles Indexed role codes
     *
     * @return array
     */
    private function getPrimaryDisplayRoles(string $tag, array $fallbackRoles): array
    {
        $roles = [];
        $marcReader = $this->getMarcReader();
        foreach ($marcReader->getFields($tag) as $index => $field) {
            $roleCode = trim($marcReader->getSubfield($field, '4'));
            $roleTerm = trim($marcReader->getSubfield($field, 'e'));
            $indexedRole = $fallbackRoles[$index] ?? '';

            if ($roleCode !== '' && $roleTerm !== '') {
                $roles[$index] = $roleCode . ' (' . $roleTerm . ')';
            } else {
                $roles[$index] = $roleCode !== '' ? $roleCode
                    : ($roleTerm !== '' ? $roleTerm : $indexedRole);
            }
        }
        return $roles ?: $fallbackRoles;
    }

    /** https://redmine.acdh.oeaw.ac.at/issues/25237 */
    const DEFAULT_AUTHOR_ROLE = 'Participant';

    /**
     * https://redmine.acdh.oeaw.ac.at/issues/19499
     */
    private function mergeAuthorsAndRoles(array $names, array $roles,
                                          array $ow = []): array {
        $authors = [];
        foreach ($names as $index => $name) {
            if (!is_string($name) || trim($name) === '') {
                continue;
            }
            $displayName = !empty($ow[$index]) && $ow[$index] !== 'noLinkedField'
                ? $ow[$index] . ' / ' . $name : $name;
            $role = is_string($roles[$index] ?? null) && trim($roles[$index]) !== ''
                ? $roles[$index] : self::DEFAULT_AUTHOR_ROLE;
            if (!isset($authors[$name])) {
                $authors[$name] = ['name' => $displayName, 'role' => []];
            }
            $authors[$name]['role'][] = $role;
        }
        return $this->mergeRolesForSearchView($authors);
    }

    /**
     * https://redmine.acdh.oeaw.ac.at/issues/19499
     */
    private function mergeRolesForSearchView(array $authors): array {
        $result = [];
        foreach ($authors as $k => $v) {
            $result[] = [
                'name' => $k,
                'text' => $v['name'] . ' [ ' . implode(', ', array_unique($v['role'])) . ' ]',
            ];
        }
        return $result;
    }

    /**
     * https://redmine.acdh.oeaw.ac.at/issues/14549
     * 
     * Extend the Description tab with the responsibility Note
     */
    public function getExtraDescriptionData(): array {
        $value = $this->getMarcFieldsAsObject('245', null, null);

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
                    'entity'        => 'person',
                    'name'          => $value['name'],
                    'name_extended' => (isset($value['name_extended']) ? $value['name_extended'] : null),
                    'role'          => $value['role'],
                    'auth'          => null,
                    'primary'       => false
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
     * @return array
     */
    public function getAllSubjectHeadings(mixed $extended = false): array {
        $chains = [];
        foreach ($this->getMarcReader()->getFields('689') as $field) {
            $group = $field['i1'] ?? '';
            $position = $field['i2'] ?? '';
            if (!ctype_digit((string) $group) || !ctype_digit((string) $position)) {
                continue;
            }
            $parts = [];
            $authority = null;
            foreach ($field['subfields'] ?? [] as $subfield) {
                if (in_array($subfield['code'], ['a', 'x', 'v', 't', 'y', 'z', 'b', 'c', 'g', 'h'], true)) {
                    $parts[] = $subfield['data'];
                } elseif ($subfield['code'] === '0') {
                    $authority = $subfield['data'];
                } elseif ($subfield['code'] === '2' && $authority === null
                    && str_starts_with($subfield['data'], 'https://')) {
                    $authority = $subfield['data'];
                }
            }
            if ($parts !== []) {
                $chains[(int) $group][(int) $position] = [implode(' ', $parts), $authority];
            }
        }
        ksort($chains);
        foreach ($chains as &$chain) {
            ksort($chain);
            $chain = array_values($chain);
        }
        unset($chain);
        foreach ($this->getMarcReader()->getFields('982') as $field) {
            if (trim($field['i1'] ?? '') !== '' || trim($field['i2'] ?? '') !== '') {
                continue;
            }
            $chain = [];
            foreach ($this->getSubfields($field, 'a') as $value) {
                foreach (preg_split('/\s+[\/-]\s+/', $value) ?: [] as $term) {
                    if (trim($term) !== '') {
                        $chain[] = [trim($term), null];
                    }
                }
            }
            if ($chain !== []) {
                $chains[] = $chain;
            }
        }
        return array_values(array_unique($chains, SORT_REGULAR));
    }

    /** Subject terms used by citation exports. */
    public function getAllSubjects(): array {
        $subjects = [];
        foreach ($this->getMarcReader()->getFields('689') as $field) {
            $type = strtoupper($this->getMarcReader()->getSubfield($field, 'D'));
            if ($type !== 'S') {
                continue;
            }
            $parts = [];
            foreach ($field['subfields'] ?? [] as $subfield) {
                if (in_array($subfield['code'], ['a', 'x', 'v', 't', 'y', 'z', 'b', 'c', 'g', 'h'], true)) {
                    $parts[] = $subfield['data'];
                }
            }
            if ($parts !== []) {
                $subjects[] = implode(', ', $parts);
            }
        }
        foreach ($this->getMarcReader()->getFields('982') as $field) {
            foreach ($this->getSubfields($field, 'a') as $value) {
                $subjects[] = $value;
            }
        }
        return array_values(array_unique($subjects));
    }

    /**
     * https://redmine.acdh.oeaw.ac.at/issues/19917
     * https://redmine.acdh.oeaw.ac.at/issues/20336
     * Get the record view subtitle
     */
    public function getSubTitle(): string {
        $st  = $this->getMarcFieldsAsObject('249', null, null, false);
        $str = "";

        foreach ($st as $k => $v) {
            if (isset($v->a) && isset($v->v)) {

                array_map(function ($a, $b) use (&$str) {
                    if (!empty($a) && !empty($b)) {
                        $str .= $this->removeBracketsFromText($a) . ' (' . $this->removeBracketsFromText($b) . ')<br/>';
                    } else if (!empty($a)) {
                        $str .= $this->removeBracketsFromText($a) . '<br/>';
                    }
                }, $v->a, $v->v);
            } else if (isset($v->a)) {
                array_map(function ($a) use (&$str) {
                    if (!empty($a)) {
                        $str .= $this->removeBracketsFromText($a) . '<br/>';
                    }
                }, $v->a);
            }
        }


        return $str;
    }

    /**
     * https://redmine.acdh.oeaw.ac.at/issues/20336
     */
    public function removeBracketsFromText(string $text): string {
        return str_replace(">>", '', str_replace("<<", '', $text));
    }

    /**
     * https://redmine.acdh.oeaw.ac.at/issues/20336
     */
    public function removeBracketsFromArray(array $data): array {
        foreach ($data as $k => $v) {
            $data[$k] = str_replace(">>", '', str_replace("<<", '', $v));
        }
        return $data;
    }

    /**
     * https://redmine.acdh.oeaw.ac.at/issues/19917
     * 
     * Get the Record view title
     */
    public function getWholeTitle(): string {
        $field245C = $this->getMarcFieldsAsObject('245', null, null, [
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
                [trim($this->removeBracketsFromText($this->getTitle())), trim($this->getTitleSection()),
                    $field245C],
                  array($this, 'filterCallback')
            )
        );
    }

    /**
     * Get the full title of the record.
     */
    public function getTitle(): string {

        $matches  = $this->getFieldArray('245', ['a', 'b'], true, ' : ');
        $title880 = $this->getTitle880();
        $str      = "";
        //if we have 880 title then we fetch that first
        if (!empty($title880)) {
            $str .= $this->removeBracketsFromText($title880);
        }

        if (is_array($matches) && count($matches) > 0) {
            if (!empty($str)) {
                $str .= '<br/>';
            }
            $str .= $this->removeBracketsFromText($this->stripNonSortingChars($matches[0]));
        }
        return $str;
    }

    /**
     * https://redmine.acdh.oeaw.ac.at/issues/19490
     * https://redmine.acdh.oeaw.ac.at/issues/21064
     * If the 245 has field 6 with value 880- then we will fetch the 880 title first
     */
    private function getTitle880(): string {
        //first check if the actual 245 has a field 6, if yes then we fetch the 880
        $field880   = $this->getMarcFieldsAsObject('880', null, null, false);
        $f880Helper = new Field880Helper($field880);
        $str        = "";
        return $f880Helper->getTitle880();
    }

    /**
     * https://redmine.acdh.oeaw.ac.at/issues/19917
     * Display the 249 B fields on the record view table
     */
    public function getTitleAddition(): string {
        $str   = "";
        $field = $this->getFieldsByKeysAndField(['b'], '249');

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
     */
    public function getStatementOfResponsibility(): string {
        $str   = "";
        $field = $this->getFieldsByKeysAndField(['c'], '249');

        if (isset($field['c']) && count($field['c']) > 0) {
            foreach ($field['c'] as $f) {
                $str .= $f;
            }
        }
        return $str;
    }

    /**
     * https://redmine.acdh.oeaw.ac.at/issues/19487
     * fetch solr container_reference and container_title
     * we need the baseurl also, but thats available only in the templates
     */
    public function getSuperiorDocument(string $baseUrl = ""): array {
        $arr = [];
        if (isset($this->fields['container_reference']) && isset($this->fields['container_title'])) {
            $arr['container_reference'] = $this->fields['container_reference'];
            $arr['container_title']     = $this->removeBracketsFromText($this->fields['container_title']);
        } else if (isset($this->fields['container_title'])) {
            $arr['container_title'] = $this->removeBracketsFromText($this->fields['container_title']);
        }
        return $arr;
    }

    /**
     * fetch related documents from field 787
     * @return array
     */
    public function getRelatedDocument(): array {
        $rd = $this->getMarcFieldsAsObject('787', 0, null, ['i', 't', 'w']);
        $arr = [];
        foreach ($rd as $d) {
            if (isset($d->w) && preg_match("/^\(AT-OBV\)AC.*/", $d->w)) {
                array_push($arr, $d);
            }
        }
        return $arr;
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
     */
    public function getPublicationDetailsAut(): array {
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
            $subfsA = $this->removeBracketsFromArray(array_column($subfs, 'a'));
            $subfsB = $this->removeBracketsFromArray(array_column($subfs, 'b'));
            $subfsC = $this->removeBracketsFromArray(array_column($subfs, 'c'));
            $subfs6 = array_column($subfs, '6');

            //if we dont have the subf 6 then we will not fetch the additional infos
            if (isset($subfs6[0])) {
                $field880_6 = $this->fetchOrtVerlagAdditionalData($subfs6[0]);
            }
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
     */
    private function fetchOrtVerlagAdditionalData(string $st): string {

        $st = str_replace('880-', '', $st);
        if (strpos($st, "/") !== false) {
            $st = strstr($st, '/', true);
        }

        $fs880_6 = $this->getFieldsByKeysAndField(['6', 'a', 'b', 'c', 'n', 'p'], '880');
        $fetch   = [];
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
        $str = $this->fetchOrtVerlagFields($fs880_6, $fetch);
        return strip_tags($str);
    }

    /**
     * https://redmine.acdh.oeaw.ac.at/issues/19490#Separators-between-subfields
     */
    private function fetchOrtVerlagFields(array $f, array $fetch): string {
        $str = "";
        foreach ($fetch as $k) {

            $valA = ($this->checkVariable($f['a'][$k])) ? $this->checkVariable($f['a'][$k]) : null;
            $valB = ($this->checkVariable($f['b'][$k])) ? $this->checkVariable($f['b'][$k]) : null;
            $valC = ($this->checkVariable($f['c'][$k])) ? $this->checkVariable($f['c'][$k]) : null;

            if ($valA && $valB && $valC) {
                $b   = (is_array($f['b'][$k])) ? implode(', ', $f['b'][$k]) : $f['b'][$k];
                $c   = (is_array($f['c'][$k])) ? implode(', ', $f['c'][$k]) : $f['c'][$k];
                $a   = (is_array($f['a'][$k])) ? implode(', ', $f['a'][$k]) : $f['a'][$k];
                $str = $a . ' : ' . $b . ',' . $c;
            } else if ($valA && $valB) {
                $b   = (is_array($f['b'][$k])) ? implode(', ', $f['b'][$k]) : $f['b'][$k];
                $a   = (is_array($f['a'][$k])) ? implode(', ', $f['a'][$k]) : $f['a'][$k];
                $str = $a . ' : ' . $b;
            } else if ($valA && $valC) {
                $c   = (is_array($f['c'][$k])) ? implode(', ', $f['c'][$k]) : $f['c'][$k];
                $a   = (is_array($f['a'][$k])) ? implode(', ', $f['a'][$k]) : $f['a'][$k];
                $str = $a . ', ' . $c;
            } else if ($valB && $valC) {
                $b   = (is_array($f['b'][$k])) ? implode(', ', $f['b'][$k]) : $f['b'][$k];
                $c   = (is_array($f['c'][$k])) ? implode(', ', $f['c'][$k]) : $f['c'][$k];
                $str = $b . ', ' . $c;
            } else if ($valA) {
                $a   = (is_array($f['a'][$k])) ? implode(', ', $f['a'][$k]) : $f['a'][$k];
                $str = $a;
            } else if ($valB) {
                $str = (is_array($f['b'][$k])) ? implode(', ', $f['b'][$k]) : $f['b'][$k];
            } else if ($valC) {
                $str = (is_array($f['c'][$k])) ? implode(', ', $f['c'][$k]) : $f['c'][$k];
            }
        }
        return $str;
    }

    private function checkVariable(string | array | null $var): bool {
        if (isset($var) && !empty($var)) {
            return true;
        }
        return false;
    }

    /**
     * https://redmine.acdh.oeaw.ac.at/issues/19490#264 Search result
     */
    public function getPublishers(): array {
        //old basic solution
        //return $this->getPublicationInfo('b');

        $fs880b = $this->getFieldsByKeysAndField(['b', '6'], '880');
        $fs264b = $this->getFieldsByKeysAndField(['b', '6'], '264');
        $fetch  = [];

        if (isset($fs880b['6'])) {
            foreach ($fs880b['6'] as $fk => $fv) {
                if (strpos($fv, '264-') !== false) {
                    $nv = str_replace('264-', '', substr($fv, 0, strpos($fv, "/")));

                    if (array_keys($fs264b['6'], '880-' . $nv)) {
                        $key2646_arr = array_keys($fs264b['6'], '880-' . $nv);
                        $key2646     = "";
                        if (count((array) $key2646_arr) > 0) {
                            $key2646 = $key2646_arr[0];

                            if ($this->checkVariable($fs880b['b'][$fk]) && $this->checkVariable($fs264b['b'][$key2646])) {
                                $str     = "";
                                $str     = (is_array($fs880b['b'][$fk])) ? implode(', ', $fs880b['b'][$fk]) : $fs880b['b'][$fk];
                                (!empty($str)) ? $str     .= " / " : "";
                                $str     .= (is_array($fs264b['b'][$key2646])) ? implode(', ', $fs264b['b'][$key2646]) : $fs264b['b'][$key2646];
                                $fetch[] = $str;
                            } else if ($this->checkVariable($fs880b['b'][$fk])) {
                                $fetch[] = (is_array($fs880b['b'][$fk])) ? implode(', ', $fs880b['b'][$fk]) : $fs880b['b'][$fk];
                            } else if ($this->checkVariable($fs264b['b'][$key2646])) {
                                $fetch[] = (is_array($fs264b['b'][$key2646])) ? implode(', ', $fs264b['b'][$key2646]) : $fs264b['b'][$key2646];
                            }
                        } elseif (isset($fs880b['b'][$fk])) {
                            $fetch[] = $fs880b['b'][$fk];
                        }
                    }
                }
            }
        }
        return $fetch;
    }

    /**
     * https://redmine.acdh.oeaw.ac.at/issues/20567
     */
    public function getContentsPiecesData(): array {
        $title       = ($this->fields['title'] ?? null);
        $publishDate = ($this->fields['publishDate'][0] ?? null);
        $edition     = ($this->fields['edition'][0] ?? null);
        $band        = ($this->fields['hierarchy_sequence'][0] ?? null);
        return array('title'       => $title, 'publishDate' => $publishDate, 'edition'     => $edition,
            'band'        => $band);
    }
}
