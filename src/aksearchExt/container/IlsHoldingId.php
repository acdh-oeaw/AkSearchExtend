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

/**
 * Container for all data required to fetch and filter Alma holding items
 * (including LKR items)
 *
 * @author zozlak
 */
class IlsHoldingId {

    /**
     * 
     * @var int
     */
    public int $mmsId;

    /**
     * 
     * @var int|null
     */
    public ?int $holdingId;

    /**
     * 
     * @var array<string>
     */
    public array $itemFilter;
    public bool $lkr;

    public function __construct(int $mmsId, ?int $holdingId = null,
                                array $itemFilter = [], bool $lkr = false) {
        $this->mmsId      = $mmsId;
        $this->holdingId  = $holdingId;
        $this->itemFilter = $itemFilter;
        $this->lkr        = $lkr;
    }
}
