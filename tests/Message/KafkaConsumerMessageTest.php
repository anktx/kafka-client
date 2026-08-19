<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Tests\Message;

use Anktx\Kafka\Client\KafkaMessage\KafkaConsumerMessage;
use PHPUnit\Framework\TestCase;

final class KafkaConsumerMessageTest extends TestCase
{
    public function testCreate(): void
    {
        $message = new KafkaConsumerMessage(topic: 'test-topic');

        self::assertSame('test-topic', $message->topic);
        self::assertNull($message->body);
    }

    public function testCreateWithAllParameters(): void
    {
        $headers = ['content-type' => 'application/json'];
        $message = new KafkaConsumerMessage(
            topic: 'test-topic',
            body: 'test body',
            partition: 1,
            offset: 100,
            key: 'test-key',
            headers: $headers,
            timestampMs: 123456789,
        );

        self::assertSame('test-topic', $message->topic);
        self::assertSame('test body', $message->body);
        self::assertSame(1, $message->partition);
        self::assertSame(100, $message->offset);
        self::assertSame('test-key', $message->key);
        self::assertSame($headers, $message->headers);
        self::assertSame(123456789, $message->timestampMs);
    }
}
