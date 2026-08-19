<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\PollStrategy;

use Anktx\Kafka\Client\Exception\Logic\InvalidConfigException;
use Random\Randomizer;

final readonly class ProbabilityPollStrategy implements PollStrategy
{
    /**
     * @param float $probability Вероятность опроса очереди (0..1)
     *
     * @throws InvalidConfigException Если вероятность вне диапазона [0, 1]
     */
    public function __construct(
        public float $probability,
        private Randomizer $randomizer = new Randomizer(),
    ) {
        if ($this->probability < 0 || $this->probability > 1) {
            throw InvalidConfigException::probability('probability', $this->probability);
        }
    }

    public function shouldPoll(): bool
    {
        return $this->randomizer->getFloat(0, 1) < $this->probability;
    }
}
