<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Tests\Clock;

use Anktx\Kafka\Client\Clock\SystemClock;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

/**
 * Юнит-тесты {@see SystemClock}: системные часы PSR-20 возвращают текущее
 * время ОС. Допуск на границы — секунды до/после вызова, детерминированность
 * здесь невозможна по определению реализации.
 */
final class SystemClockTest extends TestCase
{
    public function testNowReturnsCurrentSystemTime(): void
    {
        $clock = new SystemClock();
        self::assertInstanceOf(ClockInterface::class, $clock);

        $before = time();
        $now = $clock->now();
        $after = time();

        self::assertInstanceOf(\DateTimeImmutable::class, $now);
        self::assertGreaterThanOrEqual($before, $now->getTimestamp());
        self::assertLessThanOrEqual($after, $now->getTimestamp());
    }
}
