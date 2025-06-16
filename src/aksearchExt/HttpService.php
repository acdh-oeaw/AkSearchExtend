<?php

/*
 * The MIT License
 *
 * Copyright 2025 zozlak.
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


/**
 * Description of VuFindHttp\HttpService
 *
 * @author zozlak
 */
class HttpService extends \VuFindHttp\HttpService {

    /**
     * Constructor.
     *
     * @param array $proxyConfig Proxy configuration
     * @param array $defaults    Default HTTP options
     * @param array $config      Other configuration
     *
     * @return void
     */
    public function __construct(array $proxyConfig = [],
        array $defaults = [], array $config = []
    ) {
        // rewrite no proxy settings from main config array to
        // how the \VuFindHttp\HttpService expects it to be passed to it
        if (isset($proxyConfig['no_proxy'])) {
            $config['localAddressesRegEx'] = '@^(' . str_replace(',', '|', $proxyConfig['no_proxy']) . ')@';
            unset($proxyConfig['no_proxy']);
        }
        parent::__construct($proxyConfig, $defaults, $config);
    }
}
