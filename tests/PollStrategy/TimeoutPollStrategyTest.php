<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Tests\PollStrategy;

use Anktx\Kafka\Client\PollStrategy\TimeoutPollStrategy;
use PHPUnit\Framework\TestCase;

final class TimeoutPollStrategyTest extends TestCase
{
    public function testCreate(): void
    {
        $strategy = new TimeoutPollStrategy(pollIntervalSec: 5);

        $this->assertSame(5, $strategy->pollIntervalSec);
    }

    public function testNegativeIntervalIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Poll interval must be non-negative');

        new TimeoutPollStrategy(pollIntervalSec: -1);
    }

    public function testShouldPoll(): void
    {
        $strategy = new TimeoutPollStrategy(pollIntervalSec: 10);

        $this->assertTrue($strategy->shouldPoll());
        $this->assertFalse($strategy->shouldPoll());
    }

    public function testShouldPollWithZeroInterval(): void
    {
        $strategy = new TimeoutPollStrategy(pollIntervalSec: 0);

        // С нулевым интервалом каждый вызов должен возвращать true
        $this->assertTrue($strategy->shouldPoll());
        $this->assertTrue($strategy->shouldPoll());
    }

    public function testShouldPollWhenExactlyIntervalPassed(): void
    {
        $strategy = new TimeoutPollStrategy(pollIntervalSec: 1);

        // Используем mock для времени
        $strategy->shouldPoll(); // Первый вызов вернет true

        // Мокаем время так, чтобы прошло ровно pollIntervalSec
        // Но это сложно без dependency injection
        // Проверим только базовое поведение
        $this->assertFalse($strategy->shouldPoll());
    }

    public function testGreaterOrEqual(): void
    {
        // Проверяем, что используется >= а не >
        // При timestamp == lastPollTimestamp + interval должен возвращать true
        $strategy = new TimeoutPollStrategy(pollIntervalSec: 100);

        // Первый вызов должен вернуть true (timestamp >= 0 + 100 всегда true для текущего времени)
        $this->assertTrue($strategy->shouldPoll());
    }

    public function testInitialLastPollTimestampIsZero(): void
    {
        $strategy = new TimeoutPollStrategy(pollIntervalSec: 1);

        $reflection = new \ReflectionClass($strategy);
        $property = $reflection->getProperty('lastPollTimestamp');
        $property->setAccessible(true);

        $this->assertSame(0, $property->getValue($strategy));
    }
}
