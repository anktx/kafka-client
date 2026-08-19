<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\PollStrategy;

final class ProbabilityPollStrategy implements PollStrategy
{
    private const int PRECISION = 10000;

    public function __construct(
        public readonly float $probability,
    ) {
        if ($this->probability < 0 || $this->probability > 1) {
            throw new \InvalidArgumentException('Probability must be between 0 and 1');
        }
    }

    public function shouldPoll(): bool
    {
        return random_int(0, self::PRECISION - 1) < $this->probability * self::PRECISION;
    }
}
