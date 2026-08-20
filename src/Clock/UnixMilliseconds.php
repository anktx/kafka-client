<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Clock;

/**
 * Конвертация PSR-20 времени в unix-миллисекунды.
 *
 * Единая точка формата 'Uv' для всех потребителей ClockInterface:
 * TimeoutPollStrategy и BrokersDownBudgetStreamObserver считают
 * интервалы в одних и тех же единицах.
 */
final class UnixMilliseconds
{
    // Неинстанцируемый static-helper: пустой приватный конструктор не имеет
    // наблюдаемого поведения, исключён из line-coverage гейта.
    /** @codeCoverageIgnore */
    private function __construct() {}

    public static function of(\DateTimeImmutable $time): int
    {
        return (int) $time->format('Uv');
    }
}
