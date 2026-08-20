<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Tests\Exception;

use Anktx\Kafka\Client\Exception\Logic\ClientClosedException;
use Anktx\Kafka\Client\Exception\Logic\EmptySubscriptionsException;
use Anktx\Kafka\Client\Exception\Logic\InvalidTopicException;
use Anktx\Kafka\Client\Exception\Logic\LogicException;
use Anktx\Kafka\Client\Exception\Logic\NotSubscribedException;
use PHPUnit\Framework\TestCase;

final class LogicExceptionTest extends TestCase
{
    public function testNotSubscribedException(): void
    {
        $exception = new NotSubscribedException('Not subscribed');

        self::assertInstanceOf(LogicException::class, $exception);
        self::assertInstanceOf(NotSubscribedException::class, $exception);
        self::assertSame('Not subscribed', $exception->getMessage());
        self::assertSame(0, $exception->getCode());
    }

    public function testNotSubscribedExceptionWithCode(): void
    {
        $exception = new NotSubscribedException('Not subscribed', 100);

        self::assertSame(100, $exception->getCode());
    }

    public function testNotSubscribedExceptionIsLogicException(): void
    {
        $exception = new NotSubscribedException('Test');

        self::assertInstanceOf(\LogicException::class, $exception);
    }

    public function testNotSubscribedExceptionCreate(): void
    {
        $exception = NotSubscribedException::create();

        self::assertSame('Consumer is not subscribed to any topics', $exception->getMessage());
        self::assertInstanceOf(NotSubscribedException::class, $exception);
    }

    public function testNotSubscribedExceptionWithPrevious(): void
    {
        $previous = new \Exception('Previous error');
        $exception = new NotSubscribedException('Test', 0, $previous);

        self::assertSame($previous, $exception->getPrevious());
    }

    public function testClientClosedExceptionForMethod(): void
    {
        $exception = ClientClosedException::forMethod('consume');

        self::assertInstanceOf(LogicException::class, $exception);
        self::assertInstanceOf(ClientClosedException::class, $exception);
        self::assertSame('Cannot call consume(): the client is closed', $exception->getMessage());
    }

    public function testClientClosedExceptionIsLogicException(): void
    {
        self::assertInstanceOf(\LogicException::class, ClientClosedException::forMethod('flush'));
    }

    public function testEmptySubscriptionsExceptionCreate(): void
    {
        $exception = EmptySubscriptionsException::create();

        self::assertInstanceOf(LogicException::class, $exception);
        self::assertInstanceOf(EmptySubscriptionsException::class, $exception);
        self::assertSame('At least one subscription is required', $exception->getMessage());
    }

    public function testEmptySubscriptionsExceptionIsLogicException(): void
    {
        self::assertInstanceOf(\LogicException::class, new EmptySubscriptionsException('Test'));
    }

    public function testEmptySubscriptionsExceptionWithCode(): void
    {
        $exception = new EmptySubscriptionsException('No subscriptions', 100);

        self::assertSame(100, $exception->getCode());
    }

    public function testEmptySubscriptionsExceptionWithPrevious(): void
    {
        $previous = new \Exception('Previous error');
        $exception = new EmptySubscriptionsException('Test', 0, $previous);

        self::assertSame($previous, $exception->getPrevious());
    }

    public function testInvalidTopicExceptionEmptyName(): void
    {
        $exception = InvalidTopicException::emptyName();

        self::assertInstanceOf(LogicException::class, $exception);
        self::assertInstanceOf(InvalidTopicException::class, $exception);
        self::assertSame('Topic name must not be an empty string', $exception->getMessage());
    }

    public function testInvalidTopicExceptionIsLogicException(): void
    {
        self::assertInstanceOf(\LogicException::class, InvalidTopicException::emptyName());
    }
}
