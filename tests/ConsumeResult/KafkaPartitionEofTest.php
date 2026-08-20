<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Tests\ConsumeResult;

use Anktx\Kafka\Client\ConsumeResult\KafkaPartitionEof;
use Anktx\Kafka\Client\Topic\Topic;
use PHPUnit\Framework\TestCase;

final class KafkaPartitionEofTest extends TestCase
{
    public function testCreate(): void
    {
        $eof = new KafkaPartitionEof(
            topic: new Topic('test-topic'),
            partition: 1,
            offset: 100,
        );

        self::assertSame('test-topic', $eof->topic->name);
        self::assertSame(1, $eof->partition);
        self::assertSame(100, $eof->offset);
    }
}
