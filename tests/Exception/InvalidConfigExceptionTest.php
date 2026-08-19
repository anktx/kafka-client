<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Tests\Exception;

use Anktx\Kafka\Client\Exception\KafkaClientException;
use Anktx\Kafka\Client\Exception\Logic\InvalidConfigException;
use Anktx\Kafka\Client\Exception\Logic\LogicException;
use PHPUnit\Framework\TestCase;
use RdKafka\Exception;

final class InvalidConfigExceptionTest extends TestCase
{
    public function testIsLogicException(): void
    {
        $exception = new InvalidConfigException('Test message');

        self::assertInstanceOf(LogicException::class, $exception);
        self::assertInstanceOf(\LogicException::class, $exception);
        self::assertInstanceOf(KafkaClientException::class, $exception);
        self::assertSame('Test message', $exception->getMessage());
    }

    public function testFromKafkaExceptionCopiesMessageAndCode(): void
    {
        $exception = InvalidConfigException::fromKafkaException(
            new Exception('Outside allowed range', -1),
        );

        self::assertInstanceOf(InvalidConfigException::class, $exception);
        self::assertSame('Outside allowed range', $exception->getMessage());
        self::assertSame(-1, $exception->getCode());
    }

    public function testFromKafkaExceptionChainsPrevious(): void
    {
        $rdKafkaException = new Exception('Kafka error', 789);

        $exception = InvalidConfigException::fromKafkaException($rdKafkaException);

        self::assertSame($rdKafkaException, $exception->getPrevious());
    }
}
