<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Tests\Log;

use Anktx\Kafka\Client\Exception\Logic\InvalidLogLevelException;
use Anktx\Kafka\Client\Log\RdKafkaLogLevel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

final class RdKafkaLogLevelTest extends TestCase
{
    #[DataProvider('provideToPsrLevelCases')]
    public function testToPsrLevel(int $severity, string $expectedLevel): void
    {
        self::assertSame($expectedLevel, RdKafkaLogLevel::toPsrLevel($severity));
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function provideToPsrLevelCases(): iterable
    {
        yield 'emergency (0)' => [0, LogLevel::EMERGENCY];

        yield 'alert (1)' => [1, LogLevel::ALERT];

        yield 'critical (2)' => [2, LogLevel::CRITICAL];

        yield 'error (3)' => [3, LogLevel::ERROR];

        yield 'warning (4)' => [4, LogLevel::WARNING];

        yield 'notice (5)' => [5, LogLevel::NOTICE];

        yield 'info (6)' => [6, LogLevel::INFO];

        yield 'debug (7)' => [7, LogLevel::DEBUG];
    }

    public function testSeverityAboveRangeThrows(): void
    {
        // Выход за 0–7 — баг расширения или привязки: типизированный отказ
        // вместо молчаливого фолбэка на 'error'.
        $this->expectException(InvalidLogLevelException::class);
        $this->expectExceptionMessage('Unknown librdkafka log severity 8, expected 0-7');

        RdKafkaLogLevel::toPsrLevel(8);
    }

    public function testNegativeSeverityThrows(): void
    {
        $this->expectException(InvalidLogLevelException::class);
        $this->expectExceptionMessage('Unknown librdkafka log severity -1, expected 0-7');

        RdKafkaLogLevel::toPsrLevel(-1);
    }
}
