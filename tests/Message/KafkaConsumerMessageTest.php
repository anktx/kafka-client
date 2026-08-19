<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Tests\Message;

use Anktx\Kafka\Client\Exception\Logic\InvalidMessageException;
use Anktx\Kafka\Client\KafkaMessage\KafkaConsumerMessage;
use PHPUnit\Framework\TestCase;

final class KafkaConsumerMessageTest extends TestCase
{
    public function testCreate(): void
    {
        $message = new KafkaConsumerMessage(topic: 'test-topic', partition: 1, offset: 100);

        self::assertSame('test-topic', $message->topic);
        self::assertSame(1, $message->partition);
        self::assertSame(100, $message->offset);
        self::assertNull($message->body);
        self::assertNull($message->key);
        self::assertNull($message->headers);
        self::assertNull($message->timestampMs);
    }

    public function testCreateWithAllParameters(): void
    {
        $headers = ['content-type' => 'application/json', 'retry-count' => 3];
        $message = new KafkaConsumerMessage(
            topic: 'test-topic',
            partition: 1,
            offset: 100,
            body: 'test body',
            key: 'test-key',
            headers: $headers,
            timestampMs: 123456789,
        );

        self::assertSame('test-topic', $message->topic);
        self::assertSame(1, $message->partition);
        self::assertSame(100, $message->offset);
        self::assertSame('test body', $message->body);
        self::assertSame('test-key', $message->key);
        self::assertSame($headers, $message->headers);
        self::assertSame(123456789, $message->timestampMs);
    }

    public function testAcceptsZeroOffsetAndTimestamp(): void
    {
        $message = new KafkaConsumerMessage(topic: 'test-topic', partition: 0, offset: 0, timestampMs: 0);

        self::assertSame(0, $message->partition);
        self::assertSame(0, $message->offset);
        self::assertSame(0, $message->timestampMs);
    }

    public function testRejectsEmptyTopic(): void
    {
        $this->expectException(InvalidMessageException::class);
        $this->expectExceptionMessage('Message property "topic" must not be an empty string');

        new KafkaConsumerMessage(topic: '', partition: 1, offset: 100);
    }

    public function testRejectsNegativePartition(): void
    {
        $this->expectException(InvalidMessageException::class);
        $this->expectExceptionMessage('Message property "partition" must not be negative, -1 given');

        new KafkaConsumerMessage(topic: 'test-topic', partition: -1, offset: 100);
    }

    public function testRejectsNegativeOffset(): void
    {
        $this->expectException(InvalidMessageException::class);
        $this->expectExceptionMessage('Message property "offset" must not be negative, -1 given');

        new KafkaConsumerMessage(topic: 'test-topic', partition: 1, offset: -1);
    }

    public function testRejectsNegativeTimestamp(): void
    {
        $this->expectException(InvalidMessageException::class);
        $this->expectExceptionMessage('Message property "timestampMs" must not be negative, -1 given');

        new KafkaConsumerMessage(topic: 'test-topic', partition: 1, offset: 100, timestampMs: -1);
    }
}
