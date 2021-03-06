<?php

/**
 * Saga pattern implementation.
 *
 * @author  Maksim Masiukevich <dev@async-php.com>
 * @license MIT
 * @license https://opensource.org/licenses/MIT
 */

declare(strict_types = 1);

namespace ServiceBus\Sagas\Configuration;

/**
 *
 */
interface EventListenerProcessorFactory
{
    /**
     * Create handler for event.
     */
    public function createProcessor(string $event, SagaListenerOptions $listenerOptions): EventProcessor;
}
