<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Tests\Exception;

use Anktx\Kafka\Client\Exception\Kafka\KafkaConsumerException;
use Anktx\Kafka\Client\Exception\KafkaClientException;
use Anktx\Kafka\Client\Exception\Logic\EmptySubscriptionsException;
use Anktx\Kafka\Client\Exception\Logic\InvalidConfigException;
use PHPUnit\Framework\TestCase;

/**
 * Тесты маркерного интерфейса {@see KafkaClientException}.
 *
 * Маркер — единая точка поимки «всё, что кидает библиотека»: его обязаны
 * реализовать оба семейства иерархии. Тест сканирует src/Exception целиком,
 * чтобы новое исключение нельзя было добавить мимо маркера.
 */
final class KafkaClientExceptionTest extends TestCase
{
    public function testEveryLibraryExceptionImplementsMarker(): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                __DIR__ . '/../../src/Exception',
                \FilesystemIterator::SKIP_DOTS,
            ),
        );

        foreach ($files as $file) {
            \assert($file instanceof \SplFileInfo);
            require_once $file->getPathname();
        }

        $classes = array_filter(
            get_declared_classes(),
            static fn(string $class): bool => str_starts_with($class, 'Anktx\Kafka\Client\Exception\\'),
        );

        self::assertNotEmpty($classes);

        foreach ($classes as $class) {
            self::assertTrue(
                is_a($class, KafkaClientException::class, true),
                \sprintf('%s must implement KafkaClientException', $class),
            );
        }
    }

    public function testMarkerCatchesBothBranches(): void
    {
        try {
            throw EmptySubscriptionsException::create();
        } catch (KafkaClientException $e) {
            self::assertInstanceOf(EmptySubscriptionsException::class, $e);
        }

        try {
            throw InvalidConfigException::emptyString('brokers');
        } catch (KafkaClientException $e) {
            self::assertInstanceOf(InvalidConfigException::class, $e);
        }

        try {
            throw KafkaConsumerException::create('boom');
        } catch (KafkaClientException $e) {
            self::assertInstanceOf(KafkaConsumerException::class, $e);
        }
    }
}
