<?php

/**
 * Saga pattern implementation.
 *
 * @author  Maksim Masiukevich <dev@async-php.com>
 * @license MIT
 * @license https://opensource.org/licenses/MIT
 */

declare(strict_types = 1);

namespace ServiceBus\Sagas\Tests\stubs;

use ServiceBus\Sagas\Configuration\Annotations\SagaHeader;
use ServiceBus\Sagas\Saga;

/**
 * @SagaHeader(
 *     idClass="ServiceBus\Sagas\Tests\stubs\TestSagaId",
 *     containingIdProperty="requestId",
 *     expireDateModifier="+1 year"
 * )
 */
final class CorrectSagaWithoutListeners extends Saga
{
    /**
     * {@inheritdoc}
     */
    public function start(object $command): void
    {
    }
}
