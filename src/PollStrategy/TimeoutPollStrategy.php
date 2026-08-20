<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\PollStrategy;

use Anktx\Kafka\Client\Clock\SystemClock;
use Anktx\Kafka\Client\Clock\UnixMilliseconds;
use Anktx\Kafka\Client\Exception\Logic\InvalidConfigException;
use Psr\Clock\ClockInterface;

final class TimeoutPollStrategy implements PollStrategy
{
    private ?int $lastPollMs = null;

    /**
     * @param int            $pollIntervalMs Минимальный интервал между опросами в миллисекундах
     * @param ClockInterface $clock          Источник времени (по умолчанию системные часы)
     *
     * @throws InvalidConfigException Если интервал отрицательный
     */
    public function __construct(
        public readonly int $pollIntervalMs,
        private readonly ClockInterface $clock = new SystemClock(),
    ) {
        if ($this->pollIntervalMs < 0) {
            throw InvalidConfigException::nonNegativeInt('pollIntervalMs', $this->pollIntervalMs);
        }
    }

    public function shouldPoll(): bool
    {
        return $this->lastPollMs === null
            || $this->nowMs() >= $this->lastPollMs + $this->pollIntervalMs;
    }

    public function markPolled(): void
    {
        $this->lastPollMs = $this->nowMs();
    }

    private function nowMs(): int
    {
        return UnixMilliseconds::of($this->clock->now());
    }
}
