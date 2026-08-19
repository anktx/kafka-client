<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Tests\ConsumeResult;

use Anktx\Kafka\Client\ConsumeResult\ConsumeResult;
use Anktx\Kafka\Client\KafkaConsumer;
use PHPUnit\Framework\TestCase;

/**
 * Защита словаря результатов consume() от дрейфа.
 *
 * Список вариантов нигде не захардкожен: union в сигнатуре consume()
 * обязан совпадать с множеством реализаций ConsumeResult в src/ —
 * обе стороны тест выводит из кода.
 */
final class ConsumeResultTest extends TestCase
{
    public function testConsumeUnionMirrorsConsumeResultImplementations(): void
    {
        self::assertSame(
            self::consumeResultImplementations(),
            self::consumeUnionTypes(),
            'Union типа возврата consume() и реализации ConsumeResult из src/ разошлись: '
            . 'каждый вариант union обязан реализовывать ConsumeResult, '
            . 'а каждая реализация — присутствовать в union.',
        );
    }

    /**
     * @return list<string>
     */
    private static function consumeUnionTypes(): array
    {
        $type = (new \ReflectionMethod(KafkaConsumer::class, 'consume'))->getReturnType();

        if ($type === null) {
            self::fail('consume() must declare a return type');
        }

        $names = [];

        foreach ($type instanceof \ReflectionUnionType ? $type->getTypes() : [$type] as $member) {
            if (!$member instanceof \ReflectionNamedType) {
                self::fail('consume() return type must be a union of classes');
            }

            $names[] = $member->getName();
        }

        sort($names);

        return $names;
    }

    /**
     * @return list<string>
     */
    private static function consumeResultImplementations(): array
    {
        $sourceDir = realpath(__DIR__ . '/../../src');

        if ($sourceDir === false) {
            self::fail('src/ directory not found');
        }

        self::loadAllSourceClasses($sourceDir);

        $implementations = [];

        foreach (get_declared_classes() as $class) {
            $reflection = new \ReflectionClass($class);
            $file = $reflection->getFileName();

            if (
                $file !== false
                && str_starts_with((string) realpath($file), $sourceDir . \DIRECTORY_SEPARATOR)
                && $reflection->implementsInterface(ConsumeResult::class)
            ) {
                $implementations[] = $class;
            }
        }

        sort($implementations);

        return $implementations;
    }

    private static function loadAllSourceClasses(string $sourceDir): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceDir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                require_once $file->getPathname();
            }
        }
    }
}
