<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\PollStrategy;

final class TimeoutPollStrategy implements PollStrategy
{
    private int $lastPollTimestamp;

    public function __construct(
        public readonly int $pollIntervalSec,
    ) {
    }

    public function shouldPoll(): bool
    {
        $timestamp = (int) date('U');

        $rst = $timestamp > $this->lastPollTimestamp + $this->pollIntervalSec;

        if ($rst === true) {
            $this->lastPollTimestamp = $timestamp;
        }

        return $rst;
    }
}
