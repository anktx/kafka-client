<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Tests\ConsumeResult;

use Anktx\Kafka\Client\ConsumeResult\KafkaBrokersDown;
use PHPUnit\Framework\TestCase;

final class KafkaBrokersDownTest extends TestCase
{
    public function testIsMarkerObjectWithoutStaleBrokerData(): void
    {
        // Как и KafkaConsumeTimeout — маркер без полей: служебный Message
        // librdkafka для ALL_BROKERS_DOWN несёт мусорные partition/offset (-1),
        // а количество живых брокеров librdkafka через consume() не сообщает.
        $brokersDown = new KafkaBrokersDown();

        self::assertInstanceOf(KafkaBrokersDown::class, $brokersDown);
        self::assertSame([], (new \ReflectionClass(KafkaBrokersDown::class))->getProperties());
    }
}
