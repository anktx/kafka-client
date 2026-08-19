<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Tests\Exception;

use Anktx\Kafka\Client\Exception\Kafka\KafkaConsumerException;
use Anktx\Kafka\Client\Exception\Kafka\KafkaException;
use Anktx\Kafka\Client\Exception\Kafka\KafkaFlushTimeoutException;
use Anktx\Kafka\Client\Exception\Kafka\KafkaProducerException;
use PHPUnit\Framework\TestCase;

final class KafkaExceptionTest extends TestCase
{
    public function testKafkaExceptionConstructor(): void
    {
        $exception = new class ('Test message', 123, null) extends KafkaException {};

        self::assertSame('Test message', $exception->getMessage());
        self::assertSame(123, $exception->getCode());
    }

    public function testKafkaExceptionFromKafkaException(): void
    {
        $previous = new \RdKafka\Exception('Original error', 456);
        $exception = new class extends KafkaException {};

        $result = $exception::fromKafkaException($previous);

        self::assertSame('Original error', $result->getMessage());
        self::assertSame(456, $result->getCode());
    }

    public function testKafkaExceptionFromKafkaExceptionWithPrevious(): void
    {
        $originalPrevious = new \Exception('Original previous');
        $rdKafkaException = new \RdKafka\Exception('Kafka error', 789, $originalPrevious);

        $exception = new class extends KafkaException {};
        $result = $exception::fromKafkaException($rdKafkaException);

        self::assertSame('Kafka error', $result->getMessage());
        self::assertSame(789, $result->getCode());

        $previous = $result->getPrevious();
        self::assertSame($rdKafkaException, $previous);
        self::assertSame($originalPrevious, $previous->getPrevious());
    }

    public function testKafkaConsumerException(): void
    {
        $exception = KafkaConsumerException::fromKafkaException(
            new \RdKafka\Exception('Consumer error', 100),
        );

        self::assertInstanceOf(KafkaException::class, $exception);
        self::assertInstanceOf(KafkaConsumerException::class, $exception);
        self::assertSame('Consumer error', $exception->getMessage());
        self::assertSame(100, $exception->getCode());
    }

    public function testKafkaProducerException(): void
    {
        $exception = KafkaProducerException::fromKafkaException(
            new \RdKafka\Exception('Producer error', 200),
        );

        self::assertInstanceOf(KafkaException::class, $exception);
        self::assertInstanceOf(KafkaProducerException::class, $exception);
        self::assertSame('Producer error', $exception->getMessage());
        self::assertSame(200, $exception->getCode());
    }

    public function testKafkaFlushTimeoutException(): void
    {
        $exception = KafkaFlushTimeoutException::fromKafkaException(
            new \RdKafka\Exception('Flush timeout error', 300),
        );

        self::assertInstanceOf(KafkaException::class, $exception);
        self::assertInstanceOf(KafkaFlushTimeoutException::class, $exception);
        self::assertSame('Flush timeout error', $exception->getMessage());
        self::assertSame(300, $exception->getCode());
    }

    public function testKafkaConsumerExceptionConstructor(): void
    {
        $exception = new KafkaConsumerException('Test message');

        self::assertSame('Test message', $exception->getMessage());
        self::assertSame(0, $exception->getCode());
    }

    public function testKafkaProducerExceptionConstructor(): void
    {
        $exception = new KafkaProducerException('Test message', 123);

        self::assertSame('Test message', $exception->getMessage());
        self::assertSame(123, $exception->getCode());
    }

    public function testKafkaFlushTimeoutExceptionConstructor(): void
    {
        $exception = new KafkaFlushTimeoutException('Test message', 456);

        self::assertSame('Test message', $exception->getMessage());
        self::assertSame(456, $exception->getCode());
    }

    public function testKafkaConsumerExceptionCreate(): void
    {
        $exception = KafkaConsumerException::create('Kafka error message');

        self::assertInstanceOf(KafkaException::class, $exception);
        self::assertInstanceOf(KafkaConsumerException::class, $exception);
        self::assertSame('Kafka error message', $exception->getMessage());
        self::assertSame(0, $exception->getCode());
    }

    public function testKafkaFlushTimeoutExceptionFlushTimeout(): void
    {
        $exception = KafkaFlushTimeoutException::flushTimeout(5000);

        self::assertInstanceOf(KafkaException::class, $exception);
        self::assertInstanceOf(KafkaFlushTimeoutException::class, $exception);
        self::assertSame('Flush timed out in 5000ms', $exception->getMessage());
        self::assertSame(\RD_KAFKA_RESP_ERR__TIMED_OUT, $exception->getCode());
    }

    public function testKafkaProducerExceptionFlushFailed(): void
    {
        // Реальный код ошибки вместо литерального 123: текст для неизвестного
        // librdkafka кода ('Err-123?') — деталь реализации librdkafka и меняется
        // между версиями. Проверяется формат, а не вывод err2str().
        $errorCode = \RD_KAFKA_RESP_ERR__TRANSPORT;

        $exception = KafkaProducerException::flushFailed($errorCode);

        self::assertInstanceOf(KafkaException::class, $exception);
        self::assertInstanceOf(KafkaProducerException::class, $exception);
        self::assertSame(
            \sprintf('Flush failed: %s (%d)', rd_kafka_err2str($errorCode), $errorCode),
            $exception->getMessage(),
        );
        self::assertSame($errorCode, $exception->getCode());
    }
}
