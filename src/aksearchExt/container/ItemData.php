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
use aksearchExt\Alma;

/**
 * A container for the holding item description
 *
 * @author zozlak
 */
class ItemData {

    /**
     * Creates the ItemData object representing an e-holding item from 
     * the MARC AVE field (already converted to a PHP object)
     * 
     * @param object $ave
     * @return self
     */
    static public function fromAve(object $ave): self {
        $data = new ItemData();
        $data->availability = true;
        $data->url = $ave->x ?? '';
        $data->mmsId = $ave->{0};
        $data->description = $ave->P ?? 'missing title';
        $data->status = $ave->b ?? '';
        return $data;
    }
    
    /**
     * Creates the ItemData object from the SimpleXMLElement representing
     * the item in the Alma REST API fetch items endpoint
     * (GET /almaws/v1/bibs/{mms_id}/holdings/{holding_id}/items)
     * 
     * @param SimpleXMLElement $item
     * @return self
     */
    static public function fromAlma(SimpleXMLElement $item): self {
        $data = new ItemData();
        $status  = (string) $item->item_data->base_status[0]->attributes()['desc'];
        $duedate = $item->item_data->due_date ? Alma::parseDateStatic((string) $item->item_data->due_date) : null;
        if ($duedate && 'Item not in place' === $status) {
            $status = 'Checked Out';
        }

        $processType = (string) ($item->item_data->process_type ?? '');
        if (!empty($processType) && 'LOAN' !== $processType) {
            $status = $item->item_data->process_type;
        }

        $data->mmsId        = (int) $item->bib_data->mms_id;
        $data->holdingId    = (int) $item->holding_data->holding_id;
        $data->itemId       = (int) $item->item_data->pid;
        $data->availability = ((string) $item->item_data->base_status) === '1';
        $data->status       = $status;
        $data->location     = (string) $item->item_data->location;
        $data->callNumber   = (string) $item->holding_data->call_number;
        $data->callNumber2  = (string) $item->item_data->alternative_call_number;
        $data->duedate      = $duedate;
        $data->notes        = (string) $item->item_data->public_note;
        $data->description  = (string) $item->item_data->description;
        $data->policy       = (string) $item->item_data->policy;
        return $data;        
    }
    
    public int $mmsId;
    public int $holdingId;
    public int $itemId;
    public bool $availability;
    public string $status = '';
    public string $location = '';
    public string $callNumber = '';
    public string $callNumber2 = '';
    public ?string $duedate = null;
    public string $notes = '';
    public string $description = '';
    public string $policy = '';
    public string $url = '';

    /**
     * Generated by VuFind\ILS\Logic::getRequestDetails()
     * 
     * @var array<string>
     */
    public ?array $link = null;

    public function asVuFindArray(): array {
        return [
            'id'          => $this->mmsId,
            'holding_id'  => $this->holdingId,
            'item_id'     => $this->itemId,
            'location'    => $this->location,
            'callnumber'  => $this->callNumber,
            'description' => $this->description,
            'item_notes'  => $this->notes,
            'policy'      => $this->policy,
        ];
    }
}
