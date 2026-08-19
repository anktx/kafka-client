<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\PollStrategy;

final class TimeoutPollStrategy implements PollStrategy
{
    private int $lastPollTimestamp;

    public function __construct(
        public readonly int $pollIntervalSec,
    ) {
        if ($this->pollIntervalSec < 0) {
            throw new \InvalidArgumentException('Poll interval must be non-negative');
        }

        $this->lastPollTimestamp = 0;
    }

    public function shouldPoll(): bool
    {
        $timestamp = time();

        $result = $timestamp >= $this->lastPollTimestamp + $this->pollIntervalSec;

        if ($result === true) {
            $this->lastPollTimestamp = $timestamp;
        }

        return $result;
    }
}
