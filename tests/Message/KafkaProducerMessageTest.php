<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Tests\Message;

use Anktx\Kafka\Client\KafkaMessage\KafkaProducerMessage;
use PHPUnit\Framework\TestCase;

final class KafkaProducerMessageTest extends TestCase
{
    public function testCreate(): void
    {
        $message = new KafkaProducerMessage(topic: 'test-topic');

        self::assertSame('test-topic', $message->topic);
        self::assertNull($message->body);
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

    public function testDefaultTimestamp(): void
    {
        $message = new KafkaProducerMessage(topic: 'test-topic');

        self::assertSame(0, $message->timestampMs);
    }
}
