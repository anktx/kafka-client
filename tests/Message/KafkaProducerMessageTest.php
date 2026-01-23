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

        $this->assertSame('test-topic', $message->topic);
        $this->assertNull($message->body);
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

        $this->assertSame('test-topic', $message->topic);
        $this->assertSame('test body', $message->body);
        $this->assertSame(1, $message->partition);
        $this->assertSame('test-key', $message->key);
        $this->assertSame($headers, $message->headers);
        $this->assertSame(123456789, $message->timestampMs);
    }

    public function testDefaultTimestamp(): void
    {
        $message = new KafkaProducerMessage(topic: 'test-topic');

        $this->assertSame(0, $message->timestampMs);
    }
}
