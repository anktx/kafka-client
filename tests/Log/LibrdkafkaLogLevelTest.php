<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Tests\Log;

use Anktx\Kafka\Client\Log\LibrdkafkaLogLevel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

final class LibrdkafkaLogLevelTest extends TestCase
{
    #[DataProvider('provideToPsrLevelCases')]
    public function testToPsrLevel(int $severity, string $expectedLevel): void
    {
        $this->assertSame($expectedLevel, LibrdkafkaLogLevel::toPsrLevel($severity));
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

    public function testOutOfRangeSeverityFallsBackToError(): void
    {
        $this->assertSame(LogLevel::ERROR, LibrdkafkaLogLevel::toPsrLevel(8));
        $this->assertSame(LogLevel::ERROR, LibrdkafkaLogLevel::toPsrLevel(-1));
    }
}
