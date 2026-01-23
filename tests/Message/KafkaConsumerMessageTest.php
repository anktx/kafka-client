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

        $this->assertSame('test-topic', $message->topic);
        $this->assertNull($message->body);
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

        $this->assertSame('test-topic', $message->topic);
        $this->assertSame('test body', $message->body);
        $this->assertSame(1, $message->partition);
        $this->assertSame(100, $message->offset);
        $this->assertSame('test-key', $message->key);
        $this->assertSame($headers, $message->headers);
        $this->assertSame(123456789, $message->timestampMs);
    }
}
