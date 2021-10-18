<?php

namespace aksearchExt;

use File_MARC_Data_Field;
use VuFindSearch\Query\Query;
use VuFindSearch\ParamBag;

class SolrMarc extends \AkSearch\RecordDriver\SolrMarc {

    /**
     * Check the actual resource is Open Access or not
     * @return bool
     */
    public function getOpenAccessData(): bool {
        if($this->getMarcRecord()->getField(506) !== false) {
            return true;            
        }
        return false;
    }

    public function getRealTimeHoldings() {
        $id = $this->getUniqueID();

        // check if the record is an LKR one
        $lkrValue = 'LKR/ITM-OeAW';
        $marc = $this->getMarcRecord();

        $f970a = $this->getMarcField($marc, '970a');
        $f773w = $this->getMarcField($marc, '773w');
        $f773g = $this->getMarcField($marc, '773g');

        if ($f970a === $lkrValue && !empty($f773w) && !empty($f773g)) {
            $ctrlnum = preg_replace('/^.*[)]/', '', $f773w);
            $record = $this->searchService->search('Solr', new Query("ctrlnum:$ctrlnum"), 0, 1, new ParamBag(['fl' => 'id']))->first();
            $id = $record->getRawData()['id'];
            $barcode = preg_replace('/^.*:/', '', $f773g);
        }

        // get holdings
        $results = $this->holdLogic->getHoldings($id, $this->tryMethod('getConsortialIDs'));

        // if record is an LKR, remove items not matching the barcode
        if (!empty($barcode)) {
            $holdings = $results['holdings'];
            $results['holdings'] = [];
            foreach ($holdings as $key => $location) {
                $items = $location['items'];
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

    private function getMarcField($marc, $field) {
        $v = $marc->getField(substr($field, 0, -1));
        if ($v === false) {
            return null;
        }
        $v = $v->getSubfield(substr($field, -1));
        return $v ? $v->getData() : null;
    }

}
