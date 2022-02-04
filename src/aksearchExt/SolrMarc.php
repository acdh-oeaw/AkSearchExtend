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
     */
    public function getContainerTitle() {
        return \VuFind\RecordDriver\DefaultRecord::getContainerTitle();
    }

    /**
     * To bypass how AkSearch displays the "Published in" field in the single record view
     * without overriding the `AkSearch\View\Helper\Root\RecordDataFormatterFactory::getDefaultCoreSpecs()`
     * 
     * It's a little messy because the `hierarchy_parent_id` solr field is an array
     * while `container_title` (used by `getContainerTitle`) is single-valued.
     */
    public function getConsolidatedParents() {
        $parents = $this->fields['hierarchy_parent_id'] ?? [];
        $parents = is_array($parents) ? $parents : [$parents];
        if (count($parents) === 0) {
            return null;
        }
        $title = $this->getContainerTitle();
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
     * See https://redmine.acdh.oeaw.ac.at/issues/19566 for details
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
            $id      = $record->getRawData()['id'];
            $barcode = preg_replace('/^.*:/', '', $f773g);
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
                    if ($item['barcode'] === $barcode) {
                        $location['items'][] = $item;
                    }
                }
                if (count($location['items']) > 0) {
                    $results['holdings'][$key] = $location;
                }
            }
        }

        return $results;
    }

    private function getMarcField($marc, $field, $ind1 = null, $ind2 = null, $subfield = null) {
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
}
