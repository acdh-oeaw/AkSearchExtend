<?php

namespace aksearchExt\AcdhChHelper;

class Field880Helper {

    private $data;
    private static $titleEnabledFields = ['a', 'b', 'n', 'p', 'c'];
    private $fields = [];
    private $availableFields = [];

    /**
     * 

     * 
     */
    public function __construct(array $data) {
        $this->data = $data;
    }

    /**
     * Return the title
     * @return string
     */
    public function getTitle880(): string {

        if (!$this->getField6Values()) {
            return "";
        }

        if (!$this->fetchAvailableFields()) {
            return "";
        }

        return $this->fetchTitle();
    }

    /**
     * We have to fetch the title based on some rules. The order is coming from the $titleEnabledFields array.
     * 
     * * a (no preceding character, it is always the first subfield)
     * : b
     * . n
     * : p (if there is a preceding $n); . p (if there is no preceding $n)
     * : p
     * / c
     * 
     * https://redmine.acdh.oeaw.ac.at/issues/21064#Examples
     * 
     * @return string
     */
    private function fetchTitle(): string {

        $str = "";
        $isN = false;
        if (array_key_exists("n", $this->availableFields)) {
            $isN = true;
        }

        foreach ($this->availableFields as $k => $v) {

            if (strtolower($k) == "a") {
                $str .= implode(' , ', $v);
            }
            
            $this->createStrByRules($str, $v, $k, "b", " : ");

            $this->createStrByRules($str, $v, $k, "n", " . ");
           
            if (strtolower($k) == "p" && $isN) {
                if (!empty($str)) {
                    $str .= " : " . implode(' , ', $v);
                } else {
                    $str .= implode(' , ', $v);
                }
            } else if (strtolower($k) == "p" && !$isN) {
                if (!empty($str)) {
                    $str .= " . " . implode(' , ', $v);
                } else {
                    $str .= implode(' , ', $v);
                }
            }
            
            $this->createStrByRules($str, $v, $k, "c", " / ");
        
        }
        return $str;
    }
    
   
    /**
     * Fetch the field by the rules
     * @param string $str
     * @param array $v
     * @param string $k
     * @param string $field
     * @param string $separator
     */
    private function createStrByRules(string &$str = "", array $v, string $k, string $field, string $separator) {
        if (strtolower($k) == $field) {
            if (!empty($str)) {
                $str .= $separator . implode(' , ', $v);
            } else {
                $str .= implode(' , ', $v);
            }
        }
    }

    /**
     * Fetch the available fields from the record
     * @return bool
     */
    private function fetchAvailableFields(): bool {
        foreach ($this->fields as $f) {
            foreach (self::$titleEnabledFields as $fc) {
                if (isset($this->data[$f]->$fc)) {
                    $this->availableFields[$fc] = $this->data[$f]->$fc;
                }
            }
        }

        if (count($this->availableFields) > 0) {
            return true;
        }
        return false;
    }

    /**
     * Get the Field 6 values from the 880 values
     * @return bool
     */
    private function getField6Values(): bool {
        foreach ($this->data as $k => $val) {
            if (isset($val->{6})) {
                foreach ($val->{6} as $v) {
                    if (strpos($v, '245-') !== false) {
                        $this->fields[] = $k;
                    }
                }
            }
        }

        if (count($this->fields) > 0) {
            return true;
        }
        return false;
    }
}
