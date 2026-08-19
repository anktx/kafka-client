<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Tests\StreamObserver;

use Anktx\Kafka\Client\ConsumeResult\KafkaBrokersDown;
use Anktx\Kafka\Client\ConsumeResult\KafkaConsumeTimeout;
use Anktx\Kafka\Client\ConsumeResult\KafkaPartitionEof;
use Anktx\Kafka\Client\Exception\Kafka\KafkaBrokersDownException;
use Anktx\Kafka\Client\Exception\Logic\InvalidConfigException;
use Anktx\Kafka\Client\KafkaMessage\KafkaConsumerMessage;
use Anktx\Kafka\Client\StreamObserver\BrokersDownBudgetStreamObserver;
use Anktx\Kafka\Client\Tests\Support\FakeClock;
use PHPUnit\Framework\TestCase;

/**
 * Юнит-тесты fail-fast бюджета потери брокеров: чистые хуки с FakeClock,
 * без RdKafka (политика не знает о транспорте). Интеграция со стримом —
 * в KafkaMessageStreamTest.
 *
 * Шаг времени — 1000 мс на событие. Контракт: граница бюджета
 * включительна, окно открывается первым подряд идущим KafkaBrokersDown,
 * сообщение и EOF сбрасывают его, таймаут — ни сбрасывает, ни бросает.
 */
final class BrokersDownBudgetStreamObserverTest extends TestCase
{
    public function testThrowsWhenDownReachesBudgetExactly(): void
    {
        // 6 событий нарастающим t=1000..6000: downFor 0,1000,…,5000;
        // на шестом (5000 >= 5000) — исключение, граница включительна.
        $clock = new FakeClock();
        $observer = new BrokersDownBudgetStreamObserver(5_000, $clock);

        try {
            foreach (range(1, 6) as $i) {
                $clock->advanceMs(1000);
                $observer->onBrokersDown(new KafkaBrokersDown());
            }
            self::fail('Expected KafkaBrokersDownException');
        } catch (KafkaBrokersDownException $e) {
            self::assertSame('All Kafka brokers are down for 5000ms (max allowed 5000ms)', $e->getMessage());
            self::assertSame(\RD_KAFKA_RESP_ERR__ALL_BROKERS_DOWN, $e->getCode());
        }
    }

    public function testBudgetWindowIsResetByMessage(): void
    {
        // Даун t=1000..3000 (downFor до 2000), сообщение в t=4000
        // переоткрывает окно: следующий даун живёт в нём до t=10000,
        // где downFor=5000 — «for 5000ms», а не «for 9000ms» старого окна.
        $clock = new FakeClock();
        $observer = new BrokersDownBudgetStreamObserver(5_000, $clock);

        try {
            foreach ([1, 2, 3] as $i) {
                $clock->advanceMs(1000);
                $observer->onBrokersDown(new KafkaBrokersDown());
            }

            $clock->advanceMs(1000);
            $observer->onMessage(self::message('reset'));

            foreach (range(1, 6) as $i) {
                $clock->advanceMs(1000);
                $observer->onBrokersDown(new KafkaBrokersDown());
            }
            self::fail('Expected KafkaBrokersDownException');
        } catch (KafkaBrokersDownException $e) {
            self::assertSame('All Kafka brokers are down for 5000ms (max allowed 5000ms)', $e->getMessage());
        }
    }

    public function testBudgetWindowIsResetByEof(): void
    {
        // Как и сообщением: EOF — доказательство живого соединения.
        $clock = new FakeClock();
        $observer = new BrokersDownBudgetStreamObserver(5_000, $clock);

        try {
            foreach ([1, 2, 3] as $i) {
                $clock->advanceMs(1000);
                $observer->onBrokersDown(new KafkaBrokersDown());
            }

            $clock->advanceMs(1000);
            $observer->onEof(new KafkaPartitionEof(topic: 'test-topic', partition: 1, offset: 7));

            foreach (range(1, 6) as $i) {
                $clock->advanceMs(1000);
                $observer->onBrokersDown(new KafkaBrokersDown());
            }
            self::fail('Expected KafkaBrokersDownException');
        } catch (KafkaBrokersDownException $e) {
            self::assertSame('All Kafka brokers are down for 5000ms (max allowed 5000ms)', $e->getMessage());
        }
    }

    public function testTimeoutsNeitherResetBudgetNorThrow(): void
    {
        // Даун в t=1000 открывает окно; таймауты до t=20000 не бросают
        // и не сбрасывают его: даун в t=21000 падает с downFor=20000
        // (сброшенное окно дало бы downFor=0 без исключения вовсе).
        $clock = new FakeClock();
        $observer = new BrokersDownBudgetStreamObserver(5_000, $clock);

        try {
            $clock->advanceMs(1000);
            $observer->onBrokersDown(new KafkaBrokersDown());

            foreach (range(1, 19) as $i) {
                $clock->advanceMs(1000);
                $observer->onTimeout(new KafkaConsumeTimeout());
            }

            $clock->advanceMs(1000);
            $observer->onBrokersDown(new KafkaBrokersDown());
            self::fail('Expected KafkaBrokersDownException');
        } catch (KafkaBrokersDownException $e) {
            self::assertSame('All Kafka brokers are down for 20000ms (max allowed 5000ms)', $e->getMessage());
        }
    }

    public function testFirstDownDoesNotThrowImmediatelyWithMinimalBudget(): void
    {
        // Бюджет 1 мс: первый даун (downFor=0 < 1) переживается,
        // второй (downFor=1000 >= 1) падает.
        $clock = new FakeClock();
        $observer = new BrokersDownBudgetStreamObserver(1, $clock);

        $clock->advanceMs(1000);
        $observer->onBrokersDown(new KafkaBrokersDown());

        $clock->advanceMs(1000);

        $this->expectException(KafkaBrokersDownException::class);
        $this->expectExceptionMessage('All Kafka brokers are down for 1000ms (max allowed 1ms)');

        $observer->onBrokersDown(new KafkaBrokersDown());
    }

    public function testRejectsNonPositiveBudget(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('maxBrokersDownMs');

        new BrokersDownBudgetStreamObserver(0, new FakeClock());
    }

    private static function message(string $body): KafkaConsumerMessage
    {
        return new KafkaConsumerMessage(
            topic: 'test-topic',
            partition: 2,
            offset: 10,
            body: $body,
            key: null,
            headers: [],
            timestampMs: 111,
        );
    }
}
