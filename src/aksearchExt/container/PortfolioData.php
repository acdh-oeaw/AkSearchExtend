<?php

/*
 * The MIT License
 *
 * Copyright 2024 zozlak.
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

/**
 * A container for the portofolio data description used by the holding data
 *
 * @author zozlak
 */
class PortfolioData {

    /**
     * Creates the PortfolioData object representing an e-holding item from 
     * the MARC AVE field (already converted to a PHP object)
     * 
     * @param object $ave
     * @return self
     */
    static public function fromAve(object $ave): self {
        $data       = new PortfolioData();
        $data->avek = $ave->k ?? '';
        $data->aveA = $ave->A ?? '';
        $data->aveB = $ave->B ?? '';
        $data->aveC = $ave->C ?? '';
        $data->aveD = $ave->D ?? '';
        $data->aveE = $ave->E ?? '';
        $data->aveF = $ave->F ?? '';
        $data->aveG = $ave->G ?? '';
        $data->aveH = $ave->H ?? '';
        $data->aveI = $ave->I ?? '';
        $data->aveJ = $ave->J ?? '';
        $data->aveK = $ave->K ?? '';
        $data->aveL = $ave->L ?? '';
        return $data;
    }

    public string $avek = '';
    public string $aveA = '';
    public string $aveB = '';
    public string $aveC = '';
    public string $aveD = '';
    public string $aveE = '';
    public string $aveF = '';
    public string $aveG = '';
    public string $aveH = '';
    public string $aveI = '';
    public string $aveJ = '';
    public string $aveK = '';
    public string $aveL = '';

    public function merge(PortfolioData | null $current): self {
        if ($current === null) {
            return $this;
        }
        foreach (get_object_vars($this) as $k => $v) {
            if (empty($current->$k)) {
                $current->$k = $v;
            }
        }
        return $current;
    }
}
