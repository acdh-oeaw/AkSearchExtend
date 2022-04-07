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

use Interop\Container\ContainerInterface;

/**
 * ILSAwareDelegatorFactory using aksearchExt\ILSHoldLogic as the hold logic
 * See the "Design considerations" chapter in docs/holdings.md 
 *
 * @author zozlak
 */
class IlsAwareDelegatorFactory extends \VuFind\RecordDriver\IlsAwareDelegatorFactory {

    /**
     * Invokes this factory.
     *
     * @param ContainerInterface $container Service container
     * @param string             $name      Service name
     * @param callable           $callback  Service callback
     * @param array|null         $options   Service options
     *
     * @return AbstractBase
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function __invoke(ContainerInterface $container, $name,
                             callable $callback, array $options = null
    ) {
        $driver = call_user_func($callback);

        // Attach the ILS if at least one backend supports it:
        $ilsBackends = $this->getIlsBackends($container);
        if (!empty($ilsBackends) && $container->has(\VuFind\ILS\Connection::class)) {
            $driver->attachILS(
                $container->get(\VuFind\ILS\Connection::class),
                $container->get(\aksearchExt\ILSHoldLogic::class),
                $container->get(\VuFind\ILS\Logic\TitleHolds::class)
            );
            $driver->setIlsBackends($ilsBackends);
        }

        return $driver;
    }
}
