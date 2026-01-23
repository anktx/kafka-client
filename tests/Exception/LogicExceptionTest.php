<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Tests\Exception;

use Anktx\Kafka\Client\Exception\Logic\LogicException;
use Anktx\Kafka\Client\Exception\Logic\NotSubscribedException;
use PHPUnit\Framework\TestCase;

final class LogicExceptionTest extends TestCase
{
    public function testLogicExceptionExtendsLogicException(): void
    {
        $exception = new class ('Test message') extends LogicException {};

        $this->assertInstanceOf(\LogicException::class, $exception);
        $this->assertSame('Test message', $exception->getMessage());
    }

    public function testLogicExceptionConstructorIsFinal(): void
    {
        $exception = new class ('Test message', 123) extends LogicException {};

        $this->assertSame('Test message', $exception->getMessage());
        $this->assertSame(123, $exception->getCode());
    }

    public function testLogicExceptionCreate(): void
    {
        $exception = new class extends LogicException {};

        $result = $exception::create('Test message');

        $this->assertSame('Test message', $result->getMessage());
        $this->assertSame(0, $result->getCode());
    }

    public function testLogicExceptionCreateReturnsCorrectType(): void
    {
        $exception = new class extends LogicException {};

        $result = $exception::create('Test');

        $this->assertInstanceOf(\get_class($exception), $result);
    }

    public function testNotSubscribedException(): void
    {
        $exception = new NotSubscribedException('Not subscribed');

        $this->assertInstanceOf(LogicException::class, $exception);
        $this->assertInstanceOf(NotSubscribedException::class, $exception);
        $this->assertSame('Not subscribed', $exception->getMessage());
    }

    public function testNotSubscribedExceptionWithCode(): void
    {
        $exception = new NotSubscribedException('Not subscribed', 100);

        $this->assertSame(100, $exception->getCode());
    }

    public function testNotSubscribedExceptionIsLogicException(): void
    {
        $exception = new NotSubscribedException('Test');

        $this->assertInstanceOf(\LogicException::class, $exception);
    }

    public function testNotSubscribedExceptionCreate(): void
    {
        $exception = NotSubscribedException::create('Not subscribed');

        $this->assertSame('Not subscribed', $exception->getMessage());
        $this->assertInstanceOf(NotSubscribedException::class, $exception);
    }

    public function testLogicExceptionWithPrevious(): void
    {
        $previous = new \Exception('Previous error');
        $exception = new class ('Test message', 0, $previous) extends LogicException {};

        $this->assertSame($previous, $exception->getPrevious());
    }

    public function testNotSubscribedExceptionWithPrevious(): void
    {
        $previous = new \Exception('Previous error');
        $exception = new NotSubscribedException('Test', 0, $previous);

        $this->assertSame($previous, $exception->getPrevious());
    }
}
