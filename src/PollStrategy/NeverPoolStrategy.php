<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\PollStrategy;

final class NeverPoolStrategy implements PollStrategy
{
    public function shouldPoll(): bool
    {
        return false;
    }
}
