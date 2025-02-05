<?php

/*
 * The MIT License
 *
 * Copyright 2025 Austrian Centre for Digital Humanities.
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

namespace aksearchExt\Search;

class FacetCache extends \AkSearch\Search\Solr\FacetCache {

    /**
     * Applies custom sorting to the insitutions facet
     * 
     *
     * @param string $context Context of list to retrieve ('Advanced' or 'HomePage')
     * @param array|null $activeSearchTab Active search tab if there is one or null
     *
     * @return array
     */
    public function getList($context = 'Advanced', $activeSearchTab = null) {
        $list = parent::getList($context, $activeSearchTab);

        if ($context === 'Advanced') {
            foreach ($list as $key => &$facet) {
                if ($key === 'institution') {
                    $libsOrder = explode("\n", file_get_contents(__DIR__ . '/../libsOrder.txt'));
                    $libsOrder = array_combine($libsOrder, range(0, count($libsOrder) - 1));
                    uksort($facet['list'], fn($a, $b) => ($libsOrder[$a['value']] ?? 9999) <=> ($libsOrder[$b['value']] ?? 9999));
                }
            }
            unset($facet);
        }
    }
}
