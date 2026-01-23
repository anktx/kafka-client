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

        $this->assertSame('test-topic', $eof->topic);
        $this->assertSame(1, $eof->partition);
        $this->assertSame(100, $eof->offset);
    }
}
