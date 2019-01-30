<?php

/**
 * PHP Service Bus Saga (Process Manager) implementation
 *
 * @author  Maksim Masiukevich <dev@async-php.com>
 * @license MIT
 * @license https://opensource.org/licenses/MIT
 */

declare(strict_types = 1);

namespace ServiceBus\Sagas\Configuration\Annotations;

use ServiceBus\AnnotationsReader\Annotation;
use ServiceBus\AnnotationsReader\AnnotationCollection;
use ServiceBus\AnnotationsReader\AnnotationsReader;
use ServiceBus\AnnotationsReader\DoctrineAnnotationsReader;
use ServiceBus\Common\MessageHandler\MessageHandler;
use ServiceBus\Common\Messages\Event;
use ServiceBus\Sagas\Configuration\Annotations\Exceptions\InvalidSagaEventListenerMethod;
use ServiceBus\Sagas\Configuration\EventListenerProcessorFactory;
use ServiceBus\Sagas\Configuration\Exceptions\InvalidSagaConfiguration;
use ServiceBus\Sagas\Configuration\SagaConfiguration;
use ServiceBus\Sagas\Configuration\SagaConfigurationLoader;
use ServiceBus\Sagas\Configuration\SagaListenerOptions;
use ServiceBus\Sagas\Configuration\SagaMetadata;
use function ServiceBus\Sagas\createEventListenerName;

/**
 * Annotation based saga configuration loader
 */
final class SagaAnnotationBasedConfigurationLoader implements SagaConfigurationLoader
{
    private const SUPPORTED_TYPES = [
        SagaHeader::class,
        SagaEventListener::class
    ];

    /**
     * @var AnnotationsReader
     */
    private $annotationReader;

    /**
     * @var EventListenerProcessorFactory
     */
    private $eventListenerProcessorFactory;

    /**
     * @param EventListenerProcessorFactory $eventListenerProcessorFactory
     * @param AnnotationsReader|null        $annotationReader
     *
     * @throws \ServiceBus\AnnotationsReader\Exceptions\ParserConfigurationError
     */
    public function __construct(
        EventListenerProcessorFactory $eventListenerProcessorFactory,
        ?AnnotationsReader $annotationReader = null
    )
    {
        $this->eventListenerProcessorFactory = $eventListenerProcessorFactory;
        $this->annotationReader              = $annotationReader ?? new DoctrineAnnotationsReader(null, ['psalm']);
    }

    /**
     * @inheritDoc
     */
    public function load(string $sagaClass): SagaConfiguration
    {
        try
        {
            $annotations = $this->annotationReader
                ->extract($sagaClass)
                ->filter(
                    static function(Annotation $annotation): ?Annotation
                    {
                        return true === \in_array(\get_class($annotation->annotationObject), self::SUPPORTED_TYPES, true)
                            ? $annotation
                            : null;
                    }
                );

            $sagaMetadata = self::createSagaMetadata(
                $sagaClass,
                self::searchSagaHeader($sagaClass, $annotations)
            );

            $handlersCollection = $this->collectSagaEventHandlers($annotations, $sagaMetadata);

            return SagaConfiguration::create($sagaMetadata, $handlersCollection);
        }
        catch(\Throwable $throwable)
        {
            throw new InvalidSagaConfiguration($throwable->getMessage(), (int) $throwable->getCode(), $throwable);
        }
    }

    /**
     * Collect a saga event handlers
     *
     * @param AnnotationCollection $annotationCollection
     * @param SagaMetadata         $sagaMetadata
     *
     * @return \SplObjectStorage<\ServiceBus\Common\MessageHandler\MessageHandler>
     *
     * @throws \ServiceBus\Sagas\Configuration\Annotations\Exceptions\InvalidSagaEventListenerMethod
     */
    private function collectSagaEventHandlers(AnnotationCollection $annotationCollection, SagaMetadata $sagaMetadata): \SplObjectStorage
    {
        $handlersCollection = new \SplObjectStorage();

        $methodAnnotations = $annotationCollection->filter(
            static function(Annotation $annotation): ?Annotation
            {
                return $annotation->annotationObject instanceof SagaEventListener ? $annotation : null;
            }
        );

        /** @var Annotation $methodAnnotation */
        foreach($methodAnnotations as $methodAnnotation)
        {
            $handlersCollection->attach(
                $this->createMessageHandler($methodAnnotation, $sagaMetadata)
            );
        }

        /** @var \SplObjectStorage<\ServiceBus\Common\MessageHandler\MessageHandler> $handlersCollection */

        return $handlersCollection;
    }

    /**
     * Create a saga event handler
     *
     * @param Annotation   $annotation
     * @param SagaMetadata $sagaMetadata
     *
     * @return MessageHandler
     *
     * @throws \ServiceBus\Sagas\Configuration\Annotations\Exceptions\InvalidSagaEventListenerMethod
     */
    private function createMessageHandler(Annotation $annotation, SagaMetadata $sagaMetadata): MessageHandler
    {
        /** @var SagaEventListener $listenerAnnotation */
        $listenerAnnotation = $annotation->annotationObject;

        $listenerOptions = true === $listenerAnnotation->hasContainingIdProperty()
            ? SagaListenerOptions::withCustomContainingIdentifierProperty(
                (string) $listenerAnnotation->containingIdProperty,
                $sagaMetadata
            )
            : SagaListenerOptions::withGlobalOptions($sagaMetadata);

        /** @var \ReflectionMethod $eventListenerReflectionMethod */
        $eventListenerReflectionMethod = $annotation->reflectionMethod;

        $eventClass         = $this->extractEventClass($eventListenerReflectionMethod);
        $expectedMethodName = createEventListenerName($eventClass);

        if($expectedMethodName === $eventListenerReflectionMethod->name)
        {
            /** @var \ReflectionMethod $reflectionMethod */
            $reflectionMethod = $annotation->reflectionMethod;

            $processor = $this->eventListenerProcessorFactory->createProcessor(
                $eventClass,
                $listenerOptions
            );

            /**
             * @var callable $processor
             * @var \Closure(\ServiceBus\Common\Messages\Message, \ServiceBus\Common\Context\ServiceBusContext):\Amp\Promise $closure
             */
            $closure = \Closure::fromCallable($processor);

            return MessageHandler::create($closure, $reflectionMethod, $listenerOptions);
        }

        throw new InvalidSagaEventListenerMethod(
            \sprintf(
                'Invalid method name of the event listener: "%s". Expected: %s',
                $eventListenerReflectionMethod->name,
                $expectedMethodName
            )
        );
    }

    /**
     * Search for an event class among method arguments
     *
     * @param \ReflectionMethod $reflectionMethod
     *
     * @return string
     *
     * @throws \ServiceBus\Sagas\Configuration\Annotations\Exceptions\InvalidSagaEventListenerMethod
     */
    private function extractEventClass(\ReflectionMethod $reflectionMethod): string
    {
        $reflectionParameters = $reflectionMethod->getParameters();

        if(1 === \count($reflectionParameters))
        {
            $firstArgumentClass = true === isset($reflectionParameters[0]) && null !== $reflectionParameters[0]->getClass()
                ? $reflectionParameters[0]->getClass()
                : null;

            if(null !== $firstArgumentClass && true === $firstArgumentClass->isSubclassOf(Event::class))
            {
                /** @var \ReflectionClass $reflectionClass */
                $reflectionClass = $reflectionParameters[0]->getClass();

                /**
                 * @noinspection OneTimeUseVariablesInspection
                 * @var          class-string<\ServiceBus\Common\Messages\Event> $eventClass
                 */
                $eventClass = $reflectionClass->getName();

                return $eventClass;
            }

            throw InvalidSagaEventListenerMethod::wrongEventArgument($reflectionMethod);
        }

        throw InvalidSagaEventListenerMethod::tooManyArguments($reflectionMethod);
    }

    /**
     * Collect metadata information
     *
     * @param string     $sagaClass
     * @param SagaHeader $sagaHeader
     *
     * @return SagaMetadata
     *
     * @throws \InvalidArgumentException
     */
    private static function createSagaMetadata(string $sagaClass, SagaHeader $sagaHeader): SagaMetadata
    {
        if(
            false === $sagaHeader->hasIdClass() ||
            false === \class_exists((string) $sagaHeader->idClass)
        )
        {
            throw new \InvalidArgumentException(
                \sprintf(
                    'In the meta data of the saga "%s", an incorrect value of the "idClass"', $sagaClass
                )
            );
        }

        return SagaMetadata::create(
            $sagaClass,
            (string) $sagaHeader->idClass,
            (string) $sagaHeader->containingIdProperty,
            (string) $sagaHeader->expireDateModifier
        );
    }

    /**
     * Search saga header information
     *
     * @param string               $sagaClass
     * @param AnnotationCollection $annotationCollection
     *
     * @return SagaHeader
     *
     * @throws \InvalidArgumentException
     */
    private static function searchSagaHeader(string $sagaClass, AnnotationCollection $annotationCollection): SagaHeader
    {
        /** @var \ServiceBus\AnnotationsReader\Annotation $annotation */
        foreach($annotationCollection->classLevelAnnotations() as $annotation)
        {
            $annotationObject = $annotation->annotationObject;

            if($annotationObject instanceof SagaHeader)
            {
                return $annotationObject;
            }
        }

        throw new \InvalidArgumentException(
            \sprintf(
                'Could not find class-level annotation "%s" in "%s"',
                SagaHeader::class,
                $sagaClass
            )
        );
    }
}