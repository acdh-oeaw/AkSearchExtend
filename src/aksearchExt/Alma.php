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

class Alma extends \VuFind\ILS\Driver\Alma {

    /**
     * See docs/holdings.md
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
        $orderBy = $this->config['Holdings']['orderBy'] ?? 'library,location,enum_a,enum_b';
        $itemsPath = '/bibs/' . rawurlencode($id) . '/holdings/ALL/items?'
            . $apiPagingParams
            . '&order_by=' . urlencode($orderBy) . '&direction=desc'
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

                // merge fields manually created by original VuFind code with all
                // item.item_data fields provided by the Alma REST API
                $data    = [
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
                $rawData = [];
                foreach ((array) $item->item_data as $k => $v) {
                    $rawData[$k] = (string) $v;
                }
                $results['holdings'][] = array_merge($rawData, $data);
            }
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
     /**
     * Parse a date.
     *
     * @param string  $date     Date to parse
     * @param boolean $withTime Add time to return if available?
     *
     * @return string
     */
    public function parseDate($date, $withTime = false)
    {
        // Remove trailing Z from end of date
        // e.g. from Alma we get dates like 2012-07-13Z without time, which is wrong)
        if (strpos($date, 'T') === false && substr($date, -1) === 'Z') {
            $date = substr($date, 0, -1);
        }

        $compactDate = "/^[0-9]{8}$/"; // e. g. 20120725
        $euroName = "/^[0-9]+\/[A-Za-z]{3}\/[0-9]{4}$/"; // e. g. 13/jan/2012
        $euro = "/^[0-9]+\/[0-9]+\/[0-9]{4}$/"; // e. g. 13/7/2012
        $euroPad = "/^[0-9]{1,2}\/[0-9]{1,2}\/[0-9]{2,4}$/"; // e. g. 13/07/2012
        $datestamp = "/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/"; // e. g. 2012-07-13
        $timestamp = "/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$/";
        $timestampMs
            = "/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}.[0-9]{3}Z$/";
        // e. g. 2017-07-09T18:00:00

        if ($date == null || $date == '') {
            return '';
        } elseif (preg_match($compactDate, $date) === 1) {
            return $this->dateConverter->convertToDisplayDate('Ynd', $date);
        } elseif (preg_match($euroName, $date) === 1) {
            return $this->dateConverter->convertToDisplayDate('d/M/Y', $date);
        } elseif (preg_match($euro, $date) === 1) {
            return $this->dateConverter->convertToDisplayDate('d/m/Y', $date);
        } elseif (preg_match($euroPad, $date) === 1) {
            return $this->dateConverter->convertToDisplayDate('d/m/y', $date);
        } elseif (preg_match($datestamp, $date) === 1) {
            return $this->dateConverter->convertToDisplayDate('Y-m-d', $date);
        } elseif (preg_match($timestamp, $date) === 1) {
            if ($withTime) {
                $timestamp = new \DateTimeImmutable($date);
                return $timestamp->format('Y-m-d H:i:s');
                /*
                return $this->dateConverter->convertToDisplayDateAndTime(
                    'Y-m-d\TH:i:sT',
                    $date
                );*/
            } else {
                return $this->dateConverter->convertToDisplayDate(
                    'Y-m-d',
                    substr($date, 0, 10)
                );
            }
        } elseif (preg_match($timestampMs, $date) === 1) {
            if ($withTime) {
                return $this->dateConverter->convertToDisplayDateAndTime(
                    'Y-m-d\TH:i:s#???T',
                    $date
                );
            } else {
                return $this->dateConverter->convertToDisplayDate(
                    'Y-m-d',
                    substr($date, 0, 10)
                );
            }
        } else {
            throw new \Exception("Invalid date: $date");
        }
    }

   
}
