<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Tests\ConsumeResult;

use Anktx\Kafka\Client\ConsumeResult\ConsumeResult;
use Anktx\Kafka\Client\KafkaConsumer;
use Anktx\Kafka\Client\KafkaMessageStream;
use PHPUnit\Framework\TestCase;

/**
 * Защита словаря результатов consume() от дрейфа.
 *
 * Список вариантов нигде не захардкожен: union в сигнатуре consume()
 * обязан совпадать с множеством реализаций ConsumeResult в src/ и с ветвями
 * match в KafkaMessageStream — обе стороны тест выводит из кода.
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

    public function testStreamMatchMirrorsConsumeUnion(): void
    {
        self::assertSame(
            self::consumeUnionTypes(),
            self::streamMatchArms(),
            'Ветви match в KafkaMessageStream::stream() разошлись с union consume(): '
            . 'новое звено union требует новой ветки match (и наоборот).',
        );
    }

    /**
     * Выбирает из исходника KafkaMessageStream классы ветвей `X::class =>`
     * (диспетчеризация результата consume() наблюдателю).
     *
     * @return list<string> FQCN ветвей по возрастанию
     */
    private static function streamMatchArms(): array
    {
        $file = (new \ReflectionClass(KafkaMessageStream::class))->getFileName();

        if ($file === false) {
            self::fail('KafkaMessageStream source file not found');
        }

        $source = file_get_contents($file);

        if ($source === false) {
            self::fail('KafkaMessageStream source file not readable');
        }

        $shortNameToFqcn = [];

        foreach (self::consumeUnionTypes() as $fqcn) {
            $shortNameToFqcn[self::shortName($fqcn)] = $fqcn;
        }

        $tokens = \PhpToken::tokenize($source);
        $fqcns = [];

        // `X::class` с именем класса в этом файле встречается только в ветвях
        // match диспетчера ($result::class — T_VARIABLE, не матчится).
        for ($i = 0, $count = \count($tokens); $i + 2 < $count; ++$i) {
            $isClassName = $tokens[$i]->is([\T_STRING, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED]);

            if (
                $isClassName
                && $tokens[$i + 1]->is(\T_DOUBLE_COLON)
                && $tokens[$i + 2]->is(\T_CLASS)
            ) {
                $shortName = self::shortName($tokens[$i]->text);
                $fqcns[] = $shortNameToFqcn[$shortName]
                    ?? self::fail(\sprintf('match-ветка "%s" вне union consume()', $tokens[$i]->text));
            }
        }

        sort($fqcns);

        return $fqcns;
    }

    private static function shortName(string $className): string
    {
        $backslashPosition = strrpos($className, '\\');

        return $backslashPosition === false
            ? $className
            : substr($className, $backslashPosition + 1);
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
