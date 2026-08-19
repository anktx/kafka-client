<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Tests\Exception;

use Anktx\Kafka\Client\Exception\Logic\LogicException;
use Anktx\Kafka\Client\Exception\Logic\NotSubscribedException;
use PHPUnit\Framework\TestCase;

final class LogicExceptionTest extends TestCase
{
    public function testNotSubscribedException(): void
    {
        $exception = new NotSubscribedException('Not subscribed');

        $this->assertInstanceOf(LogicException::class, $exception);
        $this->assertInstanceOf(NotSubscribedException::class, $exception);
        $this->assertSame('Not subscribed', $exception->getMessage());
        $this->assertSame(0, $exception->getCode());
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
        $exception = NotSubscribedException::create();

        $this->assertSame('Consumer is not subscribed to any topics', $exception->getMessage());
        $this->assertInstanceOf(NotSubscribedException::class, $exception);
    }

    public function testNotSubscribedExceptionWithPrevious(): void
    {
        $previous = new \Exception('Previous error');
        $exception = new NotSubscribedException('Test', 0, $previous);

        $this->assertSame($previous, $exception->getPrevious());
    }
}
