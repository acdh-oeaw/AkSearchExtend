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

use SimpleXMLElement;
use aksearchExt\container\HoldingData;
use aksearchExt\container\IlsHoldingId;
use aksearchExt\container\ItemData;


class Alma extends \VuFind\ILS\Driver\Alma {

    public function hasHoldings($ids): bool {
        if (!is_array($ids)) {
            return false;
        }
        
        foreach ($ids as $id) {
            try {
                $holdings = $this->makeRequest('/bibs/' . rawurldecode($id->mmsId) . '/holdings');
                if (count($holdings->holding ?? []) > 0){
                    return true;
                }
            } catch (\VuFind\Exception\ILS $e) {
                
            }
        }
        return false;
    }

    /**
     * See docs/holdings.md
     * 
     * @param array<container\IlsHoldingId> $ids
     * @param string $patron
     * @param array $options
     * @return array
     */
    public function getHolding($ids, $patron = null, array $options = []) {
        //DEBUG: print_r($ids);
        //DEBUG: $options = ['page' => 1, 'itemLimit' => 3, 'offset' => 1];
        //DEBUG: print_r($options);

        $results = [
            'total'               => 0,
            'holdings'            => [],
            'electronic_holdings' => [],
        ];

        // get list of holdings and group them by library
        $holdingsOrderBy = $this->config['Holdings']['holdingsOrderBy'] ?? 'location';
        $libraries       = [];
        foreach ($ids as $id) {
            // https://redmine.acdh.oeaw.ac.at/issues/20277
            try {
                $holdings = $this->makeRequest('/bibs/' . rawurldecode($id->mmsId) . '/holdings');
            } catch (\VuFind\Exception\ILS $e) {
                if ($e->getCode() !== 400 || !preg_match('/Input parameters mmsId [0-9]+ is not valid/', $e->getMessage())) {
                    throw $e;
                }
            }
            foreach ($holdings->holding ?? [] as $rawHoldingData) {
                $holdingId                                   = clone $id;
                $holdingId->holdingId                        = (string) $rawHoldingData->holding_id;
                $holdingData                                 = new HoldingData($holdingId, (string) $rawHoldingData->$holdingsOrderBy);
                $this->fillHoldingData($holdingData);
                $libraries[(string) $rawHoldingData->library][$holdingId->holdingId] = $holdingData;
                // items
                if (empty($holdingId->mmsId)) {
                    continue;
                }

                $orderBy   = $this->config['Holdings']['itemsOrderBy'] ?? 'description,enum_a,enum_b';
                $direction = $this->config['Holdings']['itemsOrderByDirection'] ?? 'desc';
                $apiCall   = "/bibs/$holdingId->mmsId/holdings/$holdingId->holdingId/items?" .
                             "order_by=$orderBy&direction=$direction" .
                             "&expand=due_date&limit=10000";
                // ALMA items API is terribly broken
                // Not only it applies ordering after paging (sic!)
                // but also has some issues with applying the offset when no ordering is specified
                // This makes it impossible to use the /bibs/{mmsId}/holdings/all/items
                // and forces us into making a terribly slow per-holding requests
                $response  = $this->makeRequest($apiCall);
                if ($response instanceof \SimpleXMLElement) {
                    foreach ($response->item as $i) {
                        $holdingData = $libraries[(string) $i->item_data->library][(string) $i->holding_data->holding_id];
                        // filter LKR resources
                        $itemValues = [
                            (string) $i->item_data->barcode,
                            (string) $i->item_data->enumeration_a,
                            (string) $i->item_data->enumeration_b,
                        ];
                        if ($holdingId->lkr && count(array_intersect($holdingData->id->itemFilter, $itemValues)) === 0) {
                            continue;
                        }

                        $item = ItemData::fromAlma($i, $this);
                        if ($holdingId->lkr) {
                            $holdingData->lkrItems[] = $item;
                        } else {
                            $holdingData->items[] = $item;
                        }
                    }
                }
            }
        }
               
        $libsOrder = explode("\n", file_get_contents(__DIR__ . '/libsOrder.txt'));
        $libsOrder = array_combine($libsOrder, range(0, count($libsOrder) - 1));
        uksort($libraries, fn($a, $b) => ($libsOrder[$a] ?? 9999) <=> ($libsOrder[$b] ?? 9999));
        foreach ($libraries as &$i) {
            // non-lkr first, within (non)lkr group by the orderBy property
            usort($i, fn($a, $b) => $a->id->lkr !== $b->id->lkr ? $a->id->lkr <=> $b->id->lkr : $b->orderBy <=> $a->orderBy);
            $i = array_combine(array_map(fn($x) => (string) $x->id->holdingId, $i), $i);
        }
        unset($i);

        foreach ($libraries as $i) {
            foreach ($i as $j) {
                if ($j->id->lkr && count($j->lkrItems) + count($j->items) === 0) {
                    continue;
                }
                $results['holdings'][] = $j;
                $results['total'] += max(count($j->items), count($j->lkrItems), 1);
            }
        }

        // Electronic items are fetched from MARC in the record driver class
        // because Alma REST API doesn't return the URL
        //DEBUG: print_r($results);
        return $results;
    }

    private function fillHoldingData(HoldingData $holdingData): void {
        $id       = $holdingData->id;
        $request  = "/bibs/$id->mmsId/holdings/$id->holdingId";
        $response = $this->makeRequest($request);
        if ($response instanceof SimpleXMLElement) {
            $holdingData->readFromMarc($response);
        }
    }

    /**
     * Parse a date.
     *
     * @param string  $date     Date to parse
     * @param boolean $withTime Add time to return if available?
     *
     * @return string
     */
    public function parseDate($date, $withTime = false) {
        // Remove trailing Z from end of date
        // e.g. from Alma we get dates like 2012-07-13Z without time, which is wrong)
        if (strpos($date, 'T') === false && substr($date, -1) === 'Z') {
            $date = substr($date, 0, -1);
        }

        $compactDate = "/^[0-9]{8}$/"; // e. g. 20120725
        $euroName    = "/^[0-9]+\/[A-Za-z]{3}\/[0-9]{4}$/"; // e. g. 13/jan/2012
        $euro        = "/^[0-9]+\/[0-9]+\/[0-9]{4}$/"; // e. g. 13/7/2012
        $euroPad     = "/^[0-9]{1,2}\/[0-9]{1,2}\/[0-9]{2,4}$/"; // e. g. 13/07/2012
        $datestamp   = "/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/"; // e. g. 2012-07-13
        $timestamp   = "/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$/";
        $timestampMs = "/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}.[0-9]{3}Z$/";
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
                  ); */
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
    
    
    /**
     * We have to override the base method to fetch the values from our ALMA config.
     * 
     * https://redmine.acdh.oeaw.ac.at/issues/21372
     * @param type $patron
     * @return type
     */    
    public function getPickupLocations($patron)
    {
        // Variable for returning
        $filteredPul = null;

        // Get pickup locations from Alma
        $pul = parent::getPickupLocations($patron);

        // Get config "validPickupLocations" and check if it is set
        $validPulS = $this->config['Holds']['validPickupLocations'] ?? null ?: null;
        if ($validPulS) {
            // Convert config "validPickupLocations" to array
            $validPul = preg_split('/\s*,\s*/', $validPulS);

            // Filter valid pickup locations
            $filteredPul = array_filter($pul,
                function($p) use ($validPul) {
                    return in_array($p['locationID'], $validPul);
                }
            );
        }

        // Return result (resets the array keys with array_values)
        return ($filteredPul) ? array_values($filteredPul) : $pul;
    }
}

