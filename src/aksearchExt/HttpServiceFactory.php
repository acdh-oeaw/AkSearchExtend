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

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

/**
 * Description of VuFindHttp\HttpService
 *
 * @author zozlak
 */
class HttpServiceFactory implements FactoryInterface {
    /**
     * Create an object
     *
     * @param ContainerInterface $container     Service manager
     * @param string             $requestedName Service being created
     * @param null|array         $options       Extra options (optional)
     *
     * @return object
     */
    public function __invoke(ContainerInterface $container, $requestedName, array $options = null) {
        if (!empty($options)) {
            throw new \Exception('Unexpected options passed to factory.');
        }
        $config = $container->get(\VuFind\Config\PluginManager::class)->get('config');
        $options = [];
        if (isset($config->Proxy->host)) {
            $options['proxy_host'] = $config->Proxy->host;
            if (isset($config->Proxy->port)) {
                $options['proxy_port'] = $config->Proxy->port;
            }
            if (isset($config->Proxy->type)) {
                $options['proxy_type'] = $config->Proxy->type;
            }
        } else {
            // The app's outbound proxy is often supplied by the runtime
            // environment instead of config.ini (e.g. in container setups).
            $proxyUrl = getenv('HTTPS_PROXY') ?: getenv('https_proxy')
                ?: getenv('HTTP_PROXY') ?: getenv('http_proxy');
            if ($proxyUrl !== false && $proxyUrl !== '') {
                if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $proxyUrl)) {
                    $proxyUrl = 'http://' . $proxyUrl;
                }
                $proxy = parse_url($proxyUrl);
                if ($proxy === false || empty($proxy['host'])) {
                    throw new \RuntimeException('Invalid HTTP proxy URL in environment.');
                }
                $options['proxy_host'] = $proxy['host'];
                $options['proxy_port'] = $proxy['port']
                    ?? (strtolower($proxy['scheme'] ?? 'http') === 'https' ? 443 : 80);
                if (isset($proxy['user'])) {
                    $options['proxy_user'] = rawurldecode($proxy['user']);
                }
                if (isset($proxy['pass'])) {
                    $options['proxy_pass'] = rawurldecode($proxy['pass']);
                }
            }
        }
        $defaults = isset($config->Http) ? $config->Http->toArray() : [];
        $extra = [];
        $noProxy = $config->Proxy->no_proxy ?? '';
        if (!$noProxy) {
            $noProxy = getenv('NO_PROXY') ?: getenv('no_proxy') ?: '';
        }
        if ($noProxy !== '') {
            $extra['localAddressesRegEx'] = '@^(' . str_replace(',', '|', $noProxy) . ')@';
        }
        return new $requestedName($options, $defaults, $extra);
    }
}
