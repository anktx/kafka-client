<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Tests\ConsumeResult;

use Anktx\Kafka\Client\ConsumeResult\KafkaConsumeTimeout;
use PHPUnit\Framework\TestCase;

final class KafkaConsumeTimeoutTest extends TestCase
{
    public function testIsMarkerObjectWithoutStaleBrokerData(): void
    {
        // Раньше объект нёс partition/offset из служебного Message librdkafka:
        // для таймаута это мусор (-1/-1001). Контракт — маркер без полей.
        $timeout = new KafkaConsumeTimeout();

        self::assertInstanceOf(KafkaConsumeTimeout::class, $timeout);
        self::assertSame([], (new \ReflectionClass(KafkaConsumeTimeout::class))->getProperties());
    }
}
