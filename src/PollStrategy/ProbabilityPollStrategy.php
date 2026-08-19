<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\PollStrategy;

use Random\Randomizer;

final readonly class ProbabilityPollStrategy implements PollStrategy
{
    public function __construct(
        public float $probability,
        private Randomizer $randomizer = new Randomizer(),
    ) {
        if ($this->probability < 0 || $this->probability > 1) {
            throw new \InvalidArgumentException('Probability must be between 0 and 1');
        }
    }

    public function shouldPoll(): bool
    {
        return $this->randomizer->getFloat(0, 1) < $this->probability;
    }
}
