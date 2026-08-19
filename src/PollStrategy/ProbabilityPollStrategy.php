<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\PollStrategy;

use Random\Randomizer;

final class ProbabilityPollStrategy implements PollStrategy
{
    private const int PRECISION = 10000;

    public function __construct(
        public readonly float $probability,
        private readonly Randomizer $randomizer = new Randomizer(),
    ) {
        if ($this->probability < 0 || $this->probability > 1) {
            throw new \InvalidArgumentException('Probability must be between 0 and 1');
        }
    }

    public function shouldPoll(): bool
    {
        return $this->randomizer->getInt(0, self::PRECISION - 1) < $this->probability * self::PRECISION;
    }
}
