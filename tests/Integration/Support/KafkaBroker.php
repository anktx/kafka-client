<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Tests\Integration\Support;

use PHPUnit\Framework\TestCase;

/**
 * Точка доступа интеграционных тестов к брокеру Kafka.
 *
 * Адрес брокеров читается из переменной окружения KAFKA_BROKERS
 * (формат как в metadata.broker.list, по умолчанию localhost:9092),
 * чтобы тесты работали и локально, и в CI против сервис-контейнера.
 *
 * requireBroker() проверяет TCP-доступность брокеров и помечает тест
 * skipped, если ни один не отвечает: интеграционные тесты не входят
 * в composer tests / qa и не должны падать только из-за отсутствия
 * окружения. В CI, где брокер поднимается сервис-контейнером, skip
 * означает сломанный job-сетап, а не зелёный прогон.
 */
final class KafkaBroker
{
    private const string DEFAULT_BROKERS = 'localhost:9092';
    private const float CONNECT_TIMEOUT_SEC = 1.0;

    /**
     * Возвращает адрес брокеров и гарантирует их доступность,
     * иначе помечает текущий тест skipped.
     */
    public static function requireBroker(): string
    {
        $brokers = self::brokers();

        foreach (self::endpoints($brokers) as [$host, $port]) {
            $socket = @fsockopen($host, $port, $errorCode, $errorMessage, self::CONNECT_TIMEOUT_SEC);

            if ($socket !== false) {
                fclose($socket);

                return $brokers;
            }
        }

        TestCase::markTestSkipped(\sprintf('Kafka broker is not available at "%s"', $brokers));
    }

    /**
     * Возвращает адрес брокеров из KAFKA_BROKERS или дефолт.
     */
    public static function brokers(): string
    {
        $brokers = getenv('KAFKA_BROKERS');

        return \is_string($brokers) && $brokers !== '' ? $brokers : self::DEFAULT_BROKERS;
    }

    /**
     * @return list<array{string, int}>
     */
    private static function endpoints(string $brokers): array
    {
        $endpoints = [];

        foreach (explode(',', $brokers) as $endpoint) {
            $endpoint = trim($endpoint);

            if ($endpoint === '') {
                continue;
            }

            [$host, $port] = array_pad(explode(':', $endpoint, 2), 2, '9092');

            $endpoints[] = [$host, (int) $port];
        }

        return $endpoints;
    }
}
