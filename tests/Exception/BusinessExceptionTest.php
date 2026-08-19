<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Tests\Exception;

use Anktx\Kafka\Client\Exception\Business\BusinessException;
use Anktx\Kafka\Client\Exception\Business\EmptySubscriptionsException;
use Anktx\Kafka\Client\Exception\Business\InvalidSubscriptionException;
use PHPUnit\Framework\TestCase;

final class BusinessExceptionTest extends TestCase
{
    public function testBusinessExceptionExtendsDomainException(): void
    {
        $exception = new class ('Test message') extends BusinessException {};

        self::assertInstanceOf(\DomainException::class, $exception);
        self::assertSame('Test message', $exception->getMessage());
    }

    public function testEmptySubscriptionsException(): void
    {
        $exception = new EmptySubscriptionsException('No subscriptions');

        self::assertInstanceOf(BusinessException::class, $exception);
        self::assertInstanceOf(EmptySubscriptionsException::class, $exception);
        self::assertSame('No subscriptions', $exception->getMessage());
    }

    public function testEmptySubscriptionsExceptionWithCode(): void
    {
        $exception = new EmptySubscriptionsException('No subscriptions', 100);

        self::assertSame(100, $exception->getCode());
    }

    public function testBusinessExceptionWithPrevious(): void
    {
        $previous = new \Exception('Previous error');
        $exception = new class ('Test message', 0, $previous) extends BusinessException {};

        self::assertSame($previous, $exception->getPrevious());
    }

    public function testEmptySubscriptionsExceptionIsDomainException(): void
    {
        $exception = new EmptySubscriptionsException('Test');

        self::assertInstanceOf(\DomainException::class, $exception);
    }

    public function testEmptySubscriptionsExceptionCreate(): void
    {
        $exception = EmptySubscriptionsException::create();

        self::assertInstanceOf(BusinessException::class, $exception);
        self::assertInstanceOf(EmptySubscriptionsException::class, $exception);
        self::assertSame('At least one subscription is required', $exception->getMessage());
    }

    public function testInvalidSubscriptionExceptionFactories(): void
    {
        self::assertInstanceOf(BusinessException::class, InvalidSubscriptionException::emptyTopic());
        self::assertSame(
            'Subscription topic must not be an empty string',
            InvalidSubscriptionException::emptyTopic()->getMessage(),
        );
    }

    public function testInvalidSubscriptionExceptionIsDomainException(): void
    {
        self::assertInstanceOf(\DomainException::class, InvalidSubscriptionException::emptyTopic());
    }
}
