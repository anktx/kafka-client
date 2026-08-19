<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Tests\Message;

use Anktx\Kafka\Client\Exception\Logic\InvalidMessageException;
use Anktx\Kafka\Client\KafkaMessage\KafkaProducerMessage;
use PHPUnit\Framework\TestCase;

final class KafkaProducerMessageTest extends TestCase
{
    public function testCreate(): void
    {
        $message = new KafkaProducerMessage(topic: 'test-topic');

        self::assertSame('test-topic', $message->topic);
        self::assertNull($message->body);
        self::assertSame(\RD_KAFKA_PARTITION_UA, $message->partition);
        self::assertNull($message->key);
        self::assertNull($message->headers);
        self::assertSame(0, $message->timestampMs);
    }

    public function testCreateWithAllParameters(): void
    {
        $headers = ['content-type' => 'application/json'];
        $message = new KafkaProducerMessage(
            topic: 'test-topic',
            body: 'test body',
            partition: 1,
            key: 'test-key',
            headers: $headers,
            timestampMs: 123456789,
        );

        self::assertSame('test-topic', $message->topic);
        self::assertSame('test body', $message->body);
        self::assertSame(1, $message->partition);
        self::assertSame('test-key', $message->key);
        self::assertSame($headers, $message->headers);
        self::assertSame(123456789, $message->timestampMs);
    }

    public function testRejectsEmptyTopic(): void
    {
        $this->expectException(InvalidMessageException::class);
        $this->expectExceptionMessage('Message property "topic" must not be an empty string');

        new KafkaProducerMessage(topic: '');
    }

    public function testRejectsPartitionBelowUnassigned(): void
    {
        $this->expectException(InvalidMessageException::class);
        $this->expectExceptionMessage(
            'Message property "partition" must not be less than RD_KAFKA_PARTITION_UA (-1), -2 given',
        );

        new KafkaProducerMessage(topic: 'test-topic', partition: -2);
    }

    public function testRejectsNegativeTimestamp(): void
    {
        $this->expectException(InvalidMessageException::class);
        $this->expectExceptionMessage('Message property "timestampMs" must not be negative, -1 given');

        new KafkaProducerMessage(topic: 'test-topic', timestampMs: -1);
    }
}
