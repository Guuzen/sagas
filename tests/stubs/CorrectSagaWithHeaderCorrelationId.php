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

use function ServiceBus\Common\uuid;
use ServiceBus\Sagas\Configuration\Annotations\SagaEventListener;
use ServiceBus\Sagas\Configuration\Annotations\SagaHeader;
use ServiceBus\Sagas\Saga;

/**
 * @SagaHeader(
 *     idClass="ServiceBus\Sagas\Tests\stubs\TestSagaId",
 *     containingIdSource="headers",
 *     containingIdProperty="saga-correlation-id",
 *     expireDateModifier="+1 year"
 * )
 */
final class CorrectSagaWithHeaderCorrelationId extends Saga
{
    /**
     * @var string|null
     */
    private $value = null;

    /**
     * {@inheritdoc}
     */
    public function start(object $command): void
    {
    }

    /**
     * @throws \Throwable
     */
    public function doSomething(): void
    {
        $this->fire(new EmptyCommand());
    }

    /**
     * @throws \Throwable
     */
    public function doSomethingElse(): void
    {
        $this->raise(new EventWithKey(uuid()));
    }

    /**
     * @throws \Throwable
     */
    public function closeWithSuccessStatus(): void
    {
        $this->makeCompleted();
    }

    public function value(): ?string
    {
        return $this->value;
    }

    public function changeValue(string $newValue): void
    {
        $this->value = $newValue;
    }

    /**
     * @noinspection PhpUnusedPrivateMethodInspection
     *
     * @throws \Throwable
     */
    private function onSomeFirstEvent(): void
    {
        $this->makeFailed('test reason');
    }

    /**
     * @noinspection PhpUnusedPrivateMethodInspection
     *
     * @throws \Throwable
     */
    private function onEventWithKey(EventWithKey $event): void
    {
        $this->raise(new SecondEventWithKey($event->key));
    }

    /**
     * @noinspection PhpUnusedPrivateMethodInspection
     */
    private function onSecondEventWithKey(SecondEventWithKey $event): void
    {
    }

    /**
     * @noinspection PhpUnusedPrivateMethodInspection
     *
     * @SagaEventListener()
     */
    private function onEmptyEvent(EmptyEvent $event): void
    {
    }
}
