<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Log;

use Anktx\Kafka\Client\Exception\Logic\InvalidLogLevelException;
use Psr\Log\LogLevel;

/**
 * Маппинг syslog-severity librdkafka (0–7) в строковые уровни PSR-3.
 *
 * Log-callback librdkafka передаёт уровень как int, а PSR-3 требует
 * строковый уровень из {@see LogLevel}. Выход за диапазон 0–7 — баг
 * расширения или привязки, замалчивать его фолбэком нельзя: типизированный
 * отказ вместо молчаливой подстановки 'error'.
 */
final class RdKafkaLogLevel
{
    /**
     * @var list<string> PSR-3 уровни, индексированные по syslog severity 0–7
     */
    private const array PSR_LEVELS = [
        LogLevel::EMERGENCY,
        LogLevel::ALERT,
        LogLevel::CRITICAL,
        LogLevel::ERROR,
        LogLevel::WARNING,
        LogLevel::NOTICE,
        LogLevel::INFO,
        LogLevel::DEBUG,
    ];

    // Неинстанцируемый static-helper: пустой приватный конструктор не имеет
    // наблюдаемого поведения, исключён из line-coverage гейта.
    /** @codeCoverageIgnore */
    private function __construct() {}

    public static function toPsrLevel(int $severity): string
    {
        return self::PSR_LEVELS[$severity]
            ?? throw InvalidLogLevelException::unknownSeverity($severity);
    }
}
