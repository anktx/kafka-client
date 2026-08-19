<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Tests\ConsumeResult;

use Anktx\Kafka\Client\ConsumeResult\KafkaPartitionEof;
use PHPUnit\Framework\TestCase;

final class KafkaPartitionEofTest extends TestCase
{
    public function testCreate(): void
    {
        $eof = new KafkaPartitionEof(
            topic: 'test-topic',
            partition: 1,
            offset: 100,
        );

        self::assertSame('test-topic', $eof->topic);
        self::assertSame(1, $eof->partition);
        self::assertSame(100, $eof->offset);
    }
}
