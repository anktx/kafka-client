<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\PollStrategy;

final class ProbabilityPollStrategy implements PollStrategy
{
    public function __construct(
        public readonly float $probability,
    ) {
        if ($this->probability < 0 || $this->probability > 1) {
            throw new \InvalidArgumentException('Probability must be between 0 and 1');
        }
    }

    public function shouldPoll(): bool
    {
        return mt_rand(0, 10000) < $this->probability * 10000;
    }
}
