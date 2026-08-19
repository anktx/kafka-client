<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Tests\Support;

use Psr\Log\LoggerInterface;

/**
 * Простейший PSR-3 logger-spy: собирает все записи в массив для assert'ов
 * в unit-тестах. Используется там, где нужно проверять контекст логирования,
 * а не только факт вызова.
 */
final class InMemoryLogger implements LoggerInterface
{
    /**
     * @var list<array{level: mixed, message: string, context: array<mixed>}>
     */
    public array $records = [];

    /**
     * @param mixed        $level
     * @param array<mixed> $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }

    /**
     * @param array<mixed> $context
     */
    public function emergency(string|\Stringable $message, array $context = []): void
    {
        $this->log('emergency', $message, $context);
    }

    /**
     * @param array<mixed> $context
     */
    public function alert(string|\Stringable $message, array $context = []): void
    {
        $this->log('alert', $message, $context);
    }

    /**
     * @param array<mixed> $context
     */
    public function critical(string|\Stringable $message, array $context = []): void
    {
        $this->log('critical', $message, $context);
    }

    /**
     * @param array<mixed> $context
     */
    public function error(string|\Stringable $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    /**
     * @param array<mixed> $context
     */
    public function warning(string|\Stringable $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    /**
     * @param array<mixed> $context
     */
    public function notice(string|\Stringable $message, array $context = []): void
    {
        $this->log('notice', $message, $context);
    }

    /**
     * @param array<mixed> $context
     */
    public function info(string|\Stringable $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    /**
     * @param array<mixed> $context
     */
    public function debug(string|\Stringable $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    /**
     * @return list<array{level: mixed, message: string, context: array<mixed>}>
     */
    public function findByMessage(string $message): array
    {
        return array_values(
            array_filter($this->records, static fn(array $r): bool => $r['message'] === $message),
        );
    }
}
