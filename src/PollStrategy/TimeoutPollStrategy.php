<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\PollStrategy;

use Anktx\Kafka\Client\Exception\Kafka\InvalidConfigException;

final class TimeoutPollStrategy implements PollStrategy
{
    private int $lastPollTimestamp;

    /**
     * @param int $pollIntervalSec Минимальный интервал между опросами в секундах
     *
     * @throws InvalidConfigException Если интервал отрицательный
     */
    public function __construct(
        public readonly int $pollIntervalSec,
    ) {
        if ($this->pollIntervalSec < 0) {
            throw InvalidConfigException::nonNegativeInt('pollIntervalSec', $this->pollIntervalSec);
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
