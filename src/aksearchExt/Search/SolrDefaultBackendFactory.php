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
 * THE SOFTWARE.namespace aksearchExt\Search;
 */

namespace aksearchExt\Search;

class SolrDefaultBackendFactory extends \AkSearch\Search\Factory\SolrDefaultBackendFactory {

    /**
     * https://redmine.acdh.oeaw.ac.at/issues/24301
     * docs/search_special_chars.md
     *  
     * @return QueryBuilder
     */
    protected function createQueryBuilder() {
        $specs         = $this->loadSpecs();
        $defaultDismax = $this->getIndexConfig('default_dismax_handler', 'dismax');
        $builder       = new QueryBuilder($specs, $defaultDismax);

        // Configure builder:
        $builder->setLuceneHelper($this->createLuceneSyntaxHelper());

        return $builder;
    }
}
