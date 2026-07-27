<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Tests\Support;

use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;

/**
 * Простейший PSR-3 logger-spy: собирает все записи в массив для assert'ов
 * в unit-тестах. Используется там, где нужно проверять контекст логирования,
 * а не только факт вызова.
 */
final class InMemoryLogger implements LoggerInterface
{
    use LoggerTrait;

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
     * @return list<array{level: mixed, message: string, context: array<mixed>}>
     */
    public function findByMessage(string $message): array
    {
        return array_values(
            array_filter($this->records, static fn(array $r): bool => $r['message'] === $message),
        );
    }
}
