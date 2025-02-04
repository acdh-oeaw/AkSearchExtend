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

use VuFind\Exception\ILS as ILSException;

/**
 * Description of ILSHoldLogic
 *
 * @author zozlak
 */
class ILSHoldLogic extends \VuFind\ILS\Logic\Holds {

    /**
     * Public method for getting item holdings from the catalog and selecting which
     * holding method to call
     *
     * @param array<\aksearchExt\container\IlsHoldingId> $ids A list of ILS ids
     *   to be checked
     * @param mixed  $consortiumIds Not used, kept for signature compatibility
     * @param array  $options Optional options to pass on to getHolding()
     *
     * @return array A sorted results set
     */
    public function getHoldings($ids, $consortiumIds = null, $options = []): array {
        if (!isset($this->catalog)) {
            return [];
        }
        if ($consortiumIds !== null) {
            throw new ILSException("consortium ids not supported");
        }

        $result = $this->catalog->getHolding($ids, null, $options);

        // generate hold URLs
        $holdConfig = $this->catalog->checkFunction('Holds');
        foreach ($result['holdings'] as $holding) {
            foreach ($holding->items as $item) {
                $item->link = $this->getRequestDetails($item->asVuFindArray(), $holdConfig['HMACKeys'], 'Hold');
            }
            foreach ($holding->lkrItems as $item) {
                $item->link = $this->getRequestDetails($item->asVuFindArray(), $holdConfig['HMACKeys'], 'Hold');
            }
        }

        return $result;
    }
}
