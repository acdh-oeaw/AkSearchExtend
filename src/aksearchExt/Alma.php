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
            foreach ($holdings->holding ?? [] as $holdingData) {
                $holdingId                                   = clone $id;
                $holdingId->holdingId                        = (string) $holdingData->holding_id;
                $libraries[(string) $holdingData->library][] = new HoldingData($holdingId, (string) $holdingData->$holdingsOrderBy);
            }
        }
        ksort($libraries);
        foreach ($libraries as &$i) {
            // non-lkr first, within (non)lkr group by the orderBy property
            usort($i, fn($a, $b) => $a->id->lkr !== $b->id->lkr ? $a->id->lkr <=> $b->id->lkr : $b->orderBy <=> $a->orderBy);
        }
        unset($i);
        print_r($libraries);

        // get all items library by library and holding by holding
        $orderBy   = $this->config['Holdings']['itemsOrderBy'] ?? 'description,enum_a,enum_b';
        $direction = $this->config['Holdings']['itemsOrderByDirection'] ?? 'desc';
        $limit     = (int) ($options['itemLimit'] ?? 0);
        $offset    = (int) ($options['offset'] ?? 0);
        foreach ($libraries as $holdings) {
            foreach ($holdings as $holdingData) {
                /* @var HoldingData $holdingData */
                $holdingId = $holdingData->id;
                // we don't use limit and offset as the Alma REST API applies
                // them before the order_by (sic!)
                $apiCall   = "/bibs/$holdingId->mmsId/holdings/$holdingId->holdingId/items?" .
                    "order_by=$orderBy&direction=$direction" .
                    "&expand=due_date&limit=10000";
                $response  = $this->makeRequest($apiCall);

                if (!($response instanceof \SimpleXMLElement)) {
                    continue;
                }
                /* @var SimpleXMLElement $response */
                if ($holdingId->lkr) {
                    $this->filterLkr($response, $holdingId);
                    $itemsCount = (int) $response->attributes()->total_record_count;
                } else {
                    // handle also non-itemized holdings
                    $itemsCount = max(1, (int) $response->attributes()->total_record_count);
                }
                $end              = min($offset + $limit, count($response->item));
                $start            = min($end, $offset);
                //print_r(["/bibs/$holdingId->mmsId/holdings/$holdingId->holdingId/items", 'itemsCount' => $itemsCount, 'start'      => $start, 'end'        => $end,                    'limit'      => $limit, 'offset'     => $offset]);
                $results['total'] += $itemsCount;

                if ($offset < $itemsCount && $limit > 0) {
                    $this->fillHoldingData($holdingData);
                    for ($i = $start; $i < $end; $i++) {
                        $item = ItemData::fromAlma($response->item[$i]);
                        if ($holdingId->lkr) {
                            $holdingData->lkrItems[] = $item;
                        } else {
                            $holdingData->items[] = $item;
                        }
                    }
                    $results['holdings'][] = $holdingData;
                    $limit                 = max(0, $limit - max(1, $end - $start));
                }

                $offset = max(0, $offset - $itemsCount);
            }
        }

        // Electronic items are fetched from MARC in the record driver class
        // because Alma REST API doesn't return the URL
        //DEBUG: print_r($results);
        return $results;
    }

    /**
     * Filters Alma items REST API response leaving only items belonging to
     * a given LKR.
     * 
     * @param SimpleXMLElement $response
     * @param IlsHoldingId $id
     * @return void
     */
    private function filterLkr(SimpleXMLElement $response, IlsHoldingId $id): void {
        $toRemove = [];
        $n        = 0;
        foreach ($response->item as $item) {
            $itemValues = [
                (string) $item->item_data->barcode,
                (string) $item->item_data->enumeration_a,
                (string) $item->item_data->enumeration_b,
            ];
            if (count(array_intersect($id->itemFilter, $itemValues)) === 0) {
                $toRemove[] = $n;
            }
            $n++;
        }
        $response->attributes()->total_record_count = ((int) $response->attributes()->total_record_count) - count($toRemove);
        while (count($toRemove) > 0) {
            unset($response->item[array_pop($toRemove)]);
        }
    }

    private function fillHoldingData(HoldingData $holdingData): void {
        $id       = $holdingData->id;
        $request  = "/bibs/$id->mmsId/holdings/$id->holdingId";
        $response = $this->makeRequest($request);
        if ($response instanceof SimpleXMLElement) {
            $holdingData->readFromMarc($response);
        }
    }

    public function parseDate($date, $withTime = false) {
        return self::parseDateStatic($date, $withTime);
    }

    /**
     * Parse a date.
     *
     * @param string  $date     Date to parse
     * @param boolean $withTime Add time to return if available?
     *
     * @return string
     */
    static public function parseDateStatic($date, $withTime = false) {
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
}
