<?php

/**
 * Saga pattern implementation.
 *
 * @author  Maksim Masiukevich <dev@async-php.com>
 * @license MIT
 * @license https://opensource.org/licenses/MIT
 */

declare(strict_types = 1);

namespace ServiceBus\Sagas\Store\Exceptions;

/**
 *
 */
final class SagasStoreInteractionFailed extends \RuntimeException
{
    /**
     * @param \Throwable $throwable
     *
     * @return self
     */
    public static function fromThrowable(\Throwable $throwable): self
    {
        return new self($throwable->getMessage(), (int) $throwable->getCode(), $throwable);
    }
}
