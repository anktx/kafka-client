<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Tests\PollStrategy;

use Anktx\Kafka\Client\Exception\Logic\InvalidConfigException;
use Anktx\Kafka\Client\PollStrategy\TimeoutPollStrategy;
use Anktx\Kafka\Client\Tests\Support\FakeClock;
use PHPUnit\Framework\TestCase;

/**
 * Границы интервала проверяются детерминированно на FakeClock: интервал
 * отсчитывается от markPolled(), ровно pollIntervalMs — уже пора (>=),
 * на 1 мс меньше — ещё рано.
 */
final class TimeoutPollStrategyTest extends TestCase
{
    public function testCreate(): void
    {
        $strategy = new TimeoutPollStrategy(pollIntervalMs: 5000);

        self::assertSame(5000, $strategy->pollIntervalMs);
    }

    public function testNegativeIntervalIsRejected(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Config parameter "pollIntervalMs" must not be negative, -1 given');

        new TimeoutPollStrategy(pollIntervalMs: -1);
    }

    public function testShouldPollBeforeFirstPoll(): void
    {
        $strategy = new TimeoutPollStrategy(pollIntervalMs: 5000, clock: new FakeClock());

        self::assertTrue($strategy->shouldPoll());
    }

    public function testShouldPollIsPureQuery(): void
    {
        $clock = new FakeClock();
        $strategy = new TimeoutPollStrategy(pollIntervalMs: 1000, clock: $clock);

        $strategy->markPolled();

        // Повторные вызовы без markPolled() не меняют ответ — нет скрытого состояния
        self::assertFalse($strategy->shouldPoll());
        self::assertFalse($strategy->shouldPoll());

        $clock->advanceMs(999);
        self::assertFalse($strategy->shouldPoll());
    }

    public function testShouldPollOneMillisecondBeforeBoundary(): void
    {
        $clock = new FakeClock();
        $strategy = new TimeoutPollStrategy(pollIntervalMs: 5000, clock: $clock);

        $strategy->markPolled();
        $clock->advanceMs(4999);

        self::assertFalse($strategy->shouldPoll());
    }

    public function testShouldPollExactlyAtBoundary(): void
    {
        $clock = new FakeClock();
        $strategy = new TimeoutPollStrategy(pollIntervalMs: 5000, clock: $clock);

        $strategy->markPolled();
        $clock->advanceMs(5000);

        // Ровно интервал после опроса — уже пора (сравнение >=, а не >)
        self::assertTrue($strategy->shouldPoll());
    }

    public function testMarkPolledRestartsInterval(): void
    {
        $clock = new FakeClock();
        $strategy = new TimeoutPollStrategy(pollIntervalMs: 5000, clock: $clock);

        $strategy->markPolled();
        $clock->advanceMs(5000);
        self::assertTrue($strategy->shouldPoll());

        // Факт опроса в t=5000: интервал отсчитывается заново
        $strategy->markPolled();
        self::assertFalse($strategy->shouldPoll());

        $clock->advanceMs(4999);
        self::assertFalse($strategy->shouldPoll());

        $clock->advanceMs(1);
        self::assertTrue($strategy->shouldPoll());
    }

    public function testShouldPollAfterPollAtEpochZero(): void
    {
        $strategy = new TimeoutPollStrategy(pollIntervalMs: 5000, clock: new FakeClock());

        // Опрос в t=0 фиксирует отметку 0, а не «никогда» (null-сентинел начального состояния)
        $strategy->markPolled();

        self::assertFalse($strategy->shouldPoll());
    }

    public function testZeroIntervalAlwaysPolls(): void
    {
        $clock = new FakeClock();
        $strategy = new TimeoutPollStrategy(pollIntervalMs: 0, clock: $clock);

        $strategy->markPolled();

        self::assertTrue($strategy->shouldPoll());

        $clock->advanceMs(1);
        self::assertTrue($strategy->shouldPoll());
    }
}
