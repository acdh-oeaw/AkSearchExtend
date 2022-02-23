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

namespace aksearchExt;

class Alma extends \Vufind\ILS\Driver\Alma {

    /**
     * Assures all holding data provided by the Alma REST API are returned.
     * 
     * Unfortunately there's no other way to do it than to override the whole
     * getHolding() method by copy-pasting the original implementation and 
     * changing two lines in it.
     * 
     * https://redmine.acdh.oeaw.ac.at/issues/14550
     * https://redmine.acdh.oeaw.ac.at/issues/19566
     * 
     * @param type $id
     * @param type $patron
     * @param array $options
     * @return type
     */
    public function getHolding($id, $patron = null, array $options = []) {
        // Prepare result array with default values. If no API result can be received
        // these will be returned.
        $results['total']    = 0;
        $results['holdings'] = [];

        // Correct copy count in case of paging
        $copyCount = $options['offset'] ?? 0;

        // Paging parameters for paginated API call. The "limit" tells the API how
        // many items the call should return at once (e. g. 10). The "offset" defines
        // the range (e. g. get items 30 to 40). With these parameters we are able to
        // use a paginator for paging through many items.
        $apiPagingParams = '';
        if ($options['itemLimit'] ?? null) {
            $apiPagingParams = 'limit=' . urlencode($options['itemLimit'])
                . '&offset=' . urlencode($options['offset'] ?? 0);
        }

        // The path for the API call. We call "ALL" available items, but not at once
        // as a pagination mechanism is used. If paging params are not set for some
        // reason, the first 10 items are called which is the default API behaviour.
        $itemsPath = '/bibs/' . rawurlencode($id) . '/holdings/ALL/items?'
            . $apiPagingParams
            . '&order_by=library,location,enum_a,enum_b&direction=desc'
            . '&expand=due_date';

        if ($items = $this->makeRequest($itemsPath)) {
            // Get the total number of items returned from the API call and set it to
            // a class variable. It is then used in VuFind\RecordTab\HoldingsILS for
            // the items paginator.
            $results['total'] = (int) $items->attributes()->total_record_count;

            foreach ($items->item as $item) {
                $number    = ++$copyCount;
                $holdingId = (string) $item->holding_data->holding_id;
                $itemId    = (string) $item->item_data->pid;
                $barcode   = (string) $item->item_data->barcode;
                $status    = (string) $item->item_data->base_status[0]
                        ->attributes()['desc'];
                $duedate   = $item->item_data->due_date ? $this->parseDate((string) $item->item_data->due_date) : null;
                if ($duedate && 'Item not in place' === $status) {
                    $status = 'Checked Out';
                }

                $itemNotes = !empty($item->item_data->public_note) ? [(string) $item->item_data->public_note] : null;
            }

            $itemNotes = !empty($item->item_data->public_note) ? [(string) $item->item_data->public_note] : null;

            $processType = (string) ($item->item_data->process_type ?? '');
            if ($processType && 'LOAN' !== $processType) {
                $status = $this->getTranslatableStatusString(
                    $item->item_data->process_type
                );
            }

            $description = null;
            if (!empty($item->item_data->description)) {
                $number      = (string) $item->item_data->description;
                $description = (string) $item->item_data->description;
            }

            // AkSearchExt-specific code - merge the VuFind fields with all the
            // holding data provided by the Alma REST API
            $data                  = [
                'id'           => $id,
                'source'       => 'Solr',
                'availability' => $this->getAvailabilityFromItem($item),
                'status'       => $status,
                'location'     => $this->getItemLocation($item),
                'reserve'      => 'N', // TODO: support reserve status
                'callnumber'   => $this->getTranslatableString(
                    $item->holding_data->call_number
                ),
                'duedate'      => $duedate,
                'returnDate'   => false, // TODO: support recent returns
                'number'       => $number,
                'barcode'      => empty($barcode) ? 'n/a' : $barcode,
                'item_notes'   => $itemNotes ?? null,
                'item_id'      => $itemId,
                'holding_id'   => $holdingId,
                'holdtype'     => 'auto',
                'addLink'      => $patron ? 'check' : false,
                // For Alma title-level hold requests
                'description'  => $description ?? null
            ];
            $results['holdings'][] = array_merge($item->item_data, $data);
        }

        // Fetch also digital and/or electronic inventory if configured
        $types = $this->getInventoryTypes();
        if (in_array('d_avail', $types) || in_array('e_avail', $types)) {
            // No need for physical items
            $key = array_search('p_avail', $types);
            if (false !== $key) {
                unset($types[$key]);
            }
            $statuses   = $this->getStatusesForInventoryTypes((array) $id, $types);
            $electronic = [];
            foreach ($statuses as $record) {
                foreach ($record as $status) {
                    $electronic[] = $status;
                }
            }
            $results['electronic_holdings'] = $electronic;
        }

        return $results;
    }
}
