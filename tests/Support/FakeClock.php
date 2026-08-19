<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Tests\Support;

use Psr\Clock\ClockInterface;

/**
 * Управляемые часы для детерминированных тестов времени: стартуют с эпохи
 * Unix (t = 0), время двигается только явными вызовами advanceMs().
 */
final class FakeClock implements ClockInterface
{
    private int $timestampMs = 0;

    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('@0')
            ->modify(\sprintf('+%d milliseconds', $this->timestampMs))
        ;
    }

    public function advanceMs(int $milliseconds): void
    {
        $this->timestampMs += $milliseconds;
    }
}
