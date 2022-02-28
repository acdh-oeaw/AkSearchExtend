<?php

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
            if ($url === null) {
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
    
    public function getElectronicURLs() {
        error_log('ELECTrONIC URLS');
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

    /**
     * Special implementation of getRealTimeHoldings() taking care of very specific field mappings
     * 
     * https://redmine.acdh.oeaw.ac.at/issues/19566
     * https://redmine.acdh.oeaw.ac.at/issues/14550
     */
    public function getRealTimeHoldings() {
        $id = $this->getUniqueID();

        // check if the record is an LKR one
        $lkrValue = 'LKR/ITM-OeAW';
        $marc     = $this->getMarcRecord();

        $f970a = $this->getMarcField($marc, 970, 7, null, 'a');
        $f773w = $this->getMarcField($marc, 773, 1, 8, 'w');
        $f773g = $this->getMarcField($marc, 773, 1, 8, 'g');
        
        if ($f970a === $lkrValue && !empty($f773w) && !empty($f773g)) {
            $ctrlnum = preg_replace('/^.*[)]/', '', $f773w);
            $param   = new ParamBag(['fl' => 'id']);
            $record  = $this->searchService->search('Solr', new Query("ctrlnum:$ctrlnum"), 0, 1, $param)->first();
            if ($record !== null) {
                $id      = $record->getRawData()['id'];
                $barcode = preg_replace('/^.*:/', '', $f773g);
            }
            //print_r([$id, $barcode]);
        }

        // get holdings
        $results = $this->holdLogic->getHoldings($id, $this->tryMethod('getConsortialIDs'));
        
        // if record is an LKR, remove items not matching the barcode
        if (!empty($barcode)) {
            $holdings            = $results['holdings'];
            $results['holdings'] = [];
            foreach ($holdings as $key => $location) {
                $items             = $location['items'];
                $location['items'] = [];
                foreach ($items as $item) {
                    if ($item['barcode'] === $barcode || $item['enumeration_a'] === $barcode) {
                        $location['items'][] = $item;
                    }
                }
                if (count($location['items']) > 0) {
                    $results['holdings'][$key] = $location;
                }
            }
        }
        
        if(isset($results['electronic_holdings'])) {
            $results['electronic_holdings'] = $this->checkElectronicHoldings($results['electronic_holdings'], $marc);
        }
        
        return $results;
    }

    private function getMarcField($marc, $field, $ind1 = null, $ind2 = null,
                                  $subfield = null) {
        $value = null;
        foreach ($marc->getFields($field) as $i) {
            if ($i instanceof File_MARC_Data_Field && (!empty($ind1) && intval($i->getIndicator(1)) !== $ind1 || !empty($ind2) && intval($i->getIndicator(2)) !== $ind2)) {
                continue;
            }
            if ($value !== null) {
                throw new \RuntimeException("More than one matching field");
            }
            $value = $i;
        }
        if ($value === null) {
            return null;
        }
        if ($subfield === null) {
            return $value->getData();
        } else {
            $value = $value->getSubfield($subfield);
            return $value ? $value->getData() : null;
        }
    }

    /**
     * Add the E-media Link for the electronic holdings  
     * https://redmine.acdh.oeaw.ac.at/issues/19474
     * 
     * @param array $eh
     * @param type $marc
     * @return array
     */
    private function checkElectronicHoldings(array $eh, $marc): array {
        foreach($eh as $k => $v) {
            $electronic = $this->getMarcField($marc, 'AVE', null, null, 'x');
            if(!empty($electronic)) {
                $eh[$k]['e_url'] = $electronic; 
            }
        }
        return $eh;
    }

}
