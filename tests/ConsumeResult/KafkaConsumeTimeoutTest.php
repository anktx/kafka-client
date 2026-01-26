<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Tests\ConsumeResult;

use Anktx\Kafka\Client\ConsumeResult\KafkaConsumeTimeout;
use PHPUnit\Framework\TestCase;

final class KafkaConsumeTimeoutTest extends TestCase
{
    public function testCreate(): void
    {
        $timeout = new KafkaConsumeTimeout(
            partition: 1,
            offset: 100,
        );

        $this->assertSame(1, $timeout->partition);
        $this->assertSame(100, $timeout->offset);
    }
}
