<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Clock;

use Anktx\Kafka\Client\PollStrategy\TimeoutPollStrategy;
use Psr\Clock\ClockInterface;

/**
 * Системные часы PSR-20: время чтения из текущего часового пояса ОС.
 *
 * Дефолтная реализация для инъекции в компоненты, которым нужно детерминированное
 * время в тестах (например, {@see TimeoutPollStrategy});
 * в приложениях подставляется любая другая реализация Psr\Clock\ClockInterface.
 */
final class SystemClock implements ClockInterface
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }
}
