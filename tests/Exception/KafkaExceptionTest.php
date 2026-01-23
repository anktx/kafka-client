<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Tests\Exception;

use Anktx\Kafka\Client\Exception\Kafka\KafkaConnectionException;
use Anktx\Kafka\Client\Exception\Kafka\KafkaConsumerException;
use Anktx\Kafka\Client\Exception\Kafka\KafkaException;
use Anktx\Kafka\Client\Exception\Kafka\KafkaProducerException;
use PHPUnit\Framework\TestCase;
use RdKafka\Exception as RdKafkaException;

final class KafkaExceptionTest extends TestCase
{
    public function testKafkaExceptionConstructor(): void
    {
        $exception = new class ('Test message', 123, null) extends KafkaException {};

        $this->assertSame('Test message', $exception->getMessage());
        $this->assertSame(123, $exception->getCode());
    }

    public function testKafkaExceptionFromKafkaException(): void
    {
        $previous = new RdKafkaException('Original error', 456);
        $exception = new class extends KafkaException {};

        $result = $exception::fromKafkaException($previous);

        $this->assertSame('Original error', $result->getMessage());
        $this->assertSame(456, $result->getCode());
    }

    public function testKafkaExceptionFromKafkaExceptionWithPrevious(): void
    {
        $originalPrevious = new \Exception('Original previous');
        $rdKafkaException = new RdKafkaException('Kafka error', 789, $originalPrevious);

        $exception = new class extends KafkaException {};
        $result = $exception::fromKafkaException($rdKafkaException);

        $this->assertSame('Kafka error', $result->getMessage());
        $this->assertSame(789, $result->getCode());
        $this->assertSame($originalPrevious, $result->getPrevious());
    }

    public function testKafkaConsumerException(): void
    {
        $exception = KafkaConsumerException::fromKafkaException(
            new RdKafkaException('Consumer error', 100),
        );

        $this->assertInstanceOf(KafkaException::class, $exception);
        $this->assertInstanceOf(KafkaConsumerException::class, $exception);
        $this->assertSame('Consumer error', $exception->getMessage());
        $this->assertSame(100, $exception->getCode());
    }

    public function testKafkaProducerException(): void
    {
        $exception = KafkaProducerException::fromKafkaException(
            new RdKafkaException('Producer error', 200),
        );

        $this->assertInstanceOf(KafkaException::class, $exception);
        $this->assertInstanceOf(KafkaProducerException::class, $exception);
        $this->assertSame('Producer error', $exception->getMessage());
        $this->assertSame(200, $exception->getCode());
    }

    public function testKafkaConnectionException(): void
    {
        $exception = KafkaConnectionException::fromKafkaException(
            new RdKafkaException('Connection error', 300),
        );

        $this->assertInstanceOf(KafkaException::class, $exception);
        $this->assertInstanceOf(KafkaConnectionException::class, $exception);
        $this->assertSame('Connection error', $exception->getMessage());
        $this->assertSame(300, $exception->getCode());
    }

    public function testKafkaConsumerExceptionConstructorIsFinal(): void
    {
        $exception = new KafkaConsumerException('Test message');

        $this->assertSame('Test message', $exception->getMessage());
        $this->assertSame(0, $exception->getCode());
    }

    public function testKafkaProducerExceptionConstructorIsFinal(): void
    {
        $exception = new KafkaProducerException('Test message', 123);

        $this->assertSame('Test message', $exception->getMessage());
        $this->assertSame(123, $exception->getCode());
    }

    public function testKafkaConnectionExceptionConstructorIsFinal(): void
    {
        $exception = new KafkaConnectionException('Test message', 456);

        $this->assertSame('Test message', $exception->getMessage());
        $this->assertSame(456, $exception->getCode());
    }

    public function testKafkaConsumerExceptionCreate(): void
    {
        $exception = KafkaConsumerException::create('Kafka error message');

        $this->assertInstanceOf(KafkaException::class, $exception);
        $this->assertInstanceOf(KafkaConsumerException::class, $exception);
        $this->assertSame('Kafka error message', $exception->getMessage());
    }

    public function testKafkaConnectionExceptionFlushTimeout(): void
    {
        $exception = KafkaConnectionException::flushTimeout(5000);

        $this->assertInstanceOf(KafkaException::class, $exception);
        $this->assertInstanceOf(KafkaConnectionException::class, $exception);
        $this->assertSame('Flush timed out in 5000ms', $exception->getMessage());
    }

    public function testKafkaProducerExceptionFlushFailed(): void
    {
        $exception = KafkaProducerException::flushFailed(123);

        $this->assertInstanceOf(KafkaException::class, $exception);
        $this->assertInstanceOf(KafkaProducerException::class, $exception);
        $this->assertSame('Flush failed, error 123', $exception->getMessage());
    }
}
