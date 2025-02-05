<?php

/*
 * The MIT License
 *
 * Copyright 2022 zozlak.
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

namespace aksearchExt\container;

use SimpleXMLElement;

/**
 * A containter class for storing holding data
 *
 * @author zozlak
 */
class HoldingData implements \ArrayAccess {

    public string $libraryCode       = '';
    public string $locationCode      = '';
    public string $callNos           = '';
    public string $callNumberNotes   = '';
    public string $holdingsAvailable = '';
    public string $gaps              = '';
    public string $holdingsPrefix    = '';
    public string $holdingsNotes     = '';
    public string $orderBy           = '';
    public ?IlsHoldingId $id         = null;

    /**
     * 
     * @var array<ItemData>
     */
    public array $items = [];

    /**
     * 
     * @var array<ItemData>
     */
    public array $lkrItems = [];

    public function __construct(IlsHoldingId $id = null, string $orderBy = '') {
        $this->id      = $id;
        $this->orderBy = $orderBy;
    }

    public function readFromMarc(SimpleXMLElement $marc) {
        foreach ($marc->record as $record) {
            foreach ($record->datafield as $field) {
                $attr = $field->attributes();
                $tag  = $attr['tag'] . '_' . $attr['ind1'] . $attr['ind2'];
                if ($tag === '852_81' || $tag === '852_8 ') {
                    $subfields             = $this->extractMarcSubfields($field);
                    $this->libraryCode     = $subfields->b ?? '';
                    $this->locationCode    = $subfields->c ?? '';
                    $this->callNos         = $subfields->h ?? '';
                    $this->callNumberNotes = $subfields->z ?? '';
                } elseif ($tag === '866_30') {
                    $subfields               = $this->extractMarcSubfields($field);
                    $this->holdingsAvailable = $subfields->a ?? '';
                    $this->gaps              = $subfields->z ?? '';
                    $this->holdingsPrefix    .= $subfields->{9} ?? '';
                } elseif ($tag === '866_ 0') {
                    $subfields            = $this->extractMarcSubfields($field);
                    $this->holdingsPrefix .= $subfields->a ?? '';
                    $this->holdingsNotes  = $subfields->z ?? '';
                }
            }
        }
    }

    /**
     * Returns holding-level data in a form convinient for rendering in the template
     * @return array
     */
    public function getSummary(): array {
        return [
            'locationName'      => $this->locationCode,
            'callNos'           => $this->callNos,
            'callnumberNotes'   => $this->callNumberNotes,
            'holdingsAvailable' => $this->holdingsAvailable,
            'gaps'              => $this->gaps,
            'holdingsPrefix'    => $this->holdingsPrefix,
            'holdingsNotes'     => $this->holdingsNotes,
        ];
    }

    /**
     * Helper for parsing MARC fetched from the Alma REST API
     * 
     * @param \SimpleXMLElement $field
     * @return object
     */
    private function extractMarcSubfields(SimpleXMLElement $field): object {
        $data = new \stdClass();
        foreach ($field->subfield as $i) {
            $code        = $i->attributes()['code'];
            $data->$code = (string) $i;
        }
        return $data;
    }

    public function offsetExists(mixed $offset): bool {
        return isset($this->$offset);
    }

    public function offsetGet(mixed $offset): mixed {
        return $this->$offset;
    }

    public function offsetSet(mixed $offset, mixed $value): void {
        $this->$offset = $value;
    }

    public function offsetUnset(mixed $offset): void {
        unset($this->$offset);
    }
}
