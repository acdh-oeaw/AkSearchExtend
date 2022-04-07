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
use VuFind\ILS\Connection as ILSConnection;

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
     * @param null  $consortiumIds Not used, kept for signature compatibility
     * @param array  $options Optional options to pass on to getHolding()
     *
     * @return array A sorted results set
     */
    public function getHoldings($ids, $consortiumIds = null, $options = []) {
        if (!$this->catalog) {
            return [];
        }
        if ($consortiumIds !== null) {
            throw new ILSException("consortium ids not supported");
        }

        $config = $this->catalog->checkFunction('Holds', ['id' => $ids]);//TODO - check what it does
        $result = $this->catalog->getHolding($ids, null, $options);
        $mode   = $this->catalog->getHoldsMode();

        /*
        if ($mode == "disabled") {
            $holdings = $this->standardHoldings($result);
        } elseif ($mode == "driver") {
            $holdings = $this->driverHoldings($result, $config, true);
        } else {
            $holdings = $this->generateHoldings($result, $mode, $config);
        }

        $holdings = $this->processStorageRetrievalRequests($holdings, $id, null, true);
        $holdings = $this->processILLRequests($holdings, $id, null, true);

        $result['blocks']   = false;
        $result['holdings'] = $this->formatHoldings($holdings);
        */
        
        return $result;
    }
}
