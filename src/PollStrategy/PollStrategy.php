<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\PollStrategy;

interface PollStrategy
{
    public function shouldPoll(): bool;
}
