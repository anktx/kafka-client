<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Exception\Business;

final class TopicHasNoPartitionException extends BusinessException
{
    public static function create(string $topic): self
    {
        return new self('Topic "' . $topic . '" has no partition');
    }
}
