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
 *
 * Проверка повторяется с паузой до суммарного таймаута RETRY_TIMEOUT_SEC,
 * чтобы не ловить ложный skip в момент, когда сервис-контейнер брокера
 * ещё поднимается. Результат мемоизируется: проверка выполняется один
 * раз на процесс, и недоступный брокер не задерживает остальные тесты.
 */
final class KafkaBroker
{
    private const string DEFAULT_BROKERS = 'localhost:9092';
    private const float CONNECT_TIMEOUT_SEC = 1.0;
    private const float RETRY_TIMEOUT_SEC = 5.0;
    private const int RETRY_DELAY_MICROSEC = 250_000;
    private static ?bool $brokersAvailable = null;

    /**
     * Возвращает адрес брокеров и гарантирует их доступность,
     * иначе помечает текущий тест skipped.
     */
    public static function requireBroker(): string
    {
        $brokers = self::brokers();

        if (self::$brokersAvailable === null) {
            self::$brokersAvailable = self::waitForBroker($brokers);
        }

        if (self::$brokersAvailable) {
            return $brokers;
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

    private static function waitForBroker(string $brokers): bool
    {
        $deadline = microtime(true) + self::RETRY_TIMEOUT_SEC;

        while (true) {
            foreach (self::endpoints($brokers) as [$host, $port]) {
                $socket = @fsockopen($host, $port, $errorCode, $errorMessage, self::CONNECT_TIMEOUT_SEC);

                if ($socket !== false) {
                    fclose($socket);

                    return true;
                }
            }

            if (microtime(true) >= $deadline) {
                return false;
            }

            usleep(self::RETRY_DELAY_MICROSEC);
        }
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
