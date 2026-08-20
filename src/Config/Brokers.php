<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Config;

use Anktx\Kafka\Client\Exception\Logic\InvalidConfigException;

/**
 * Валидация списка брокеров для metadata.broker.list / bootstrap.servers:
 * `host[:port][,host[:port]...]`, где host — DNS-имя, IPv4 или IPv6
 * в квадратных скобках.
 *
 * Даёт осмысленный отказ в конструкторе конфига вместо молчаливой
 * «недоступности брокеров» от librdkafka на первом же сетевом вызове
 * (опечатка вроде `kafka:9092,` иначе превращается в часы отладки).
 */
final class Brokers
{
    private const string ENTRY_PATTERN = '/^(?:[a-zA-Z0-9._-]+|\[[0-9a-fA-F:]+\])(?::([0-9]{1,5}))?$/';
    private const int MAX_PORT = 65535;

    // Неинстанцируемый static-helper: пустой приватный конструктор не имеет
    // наблюдаемого поведения, исключён из line-coverage гейта.
    /** @codeCoverageIgnore */
    private function __construct() {}

    public static function assertValid(string $brokers): void
    {
        // Порт захватывается как [0-9]{1,5}: это всегда numeric-string,
        // PHP сравнивает её с int численно — приведение типа не требуется.
        foreach (\explode(',', $brokers) as $entry) {
            if (\preg_match(self::ENTRY_PATTERN, $entry, $matches) !== 1
                || (isset($matches[1]) && $matches[1] > self::MAX_PORT)
            ) {
                throw InvalidConfigException::brokers($brokers);
            }
        }
    }
}
