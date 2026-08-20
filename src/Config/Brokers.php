<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Config;

use Anktx\Kafka\Client\Exception\Logic\InvalidConfigException;

/**
 * Список брокеров для metadata.broker.list / bootstrap.servers как
 * value object: `host[:port][,host[:port]...]`, где host — DNS-имя,
 * IPv4 или IPv6 в квадратных скобках.
 *
 * Валидация в конструкторе даёт осмысленный отказ на этапе конфигурации
 * вместо молчаливой «недоступности брокеров» от librdkafka на первом же
 * сетевом вызове (опечатка вроде `kafka:9092,` иначе превращается в часы
 * отладки): тип Brokers гарантирует валидный список без конвенции
 * «не забыть вызвать валидатор» в каждом конфиге.
 */
final readonly class Brokers
{
    private const string ENTRY_PATTERN = '/^(?:[a-zA-Z0-9._-]+|\[[0-9a-fA-F:]+\])(?::([0-9]{1,5}))?$/';
    private const int MAX_PORT = 65535;

    /**
     * @throws InvalidConfigException Если список пуст, запись не матчит
     *                                `host[:port]` или порт вне диапазона
     */
    public function __construct(
        public string $value,
    ) {
        if ($this->value === '') {
            throw InvalidConfigException::emptyString('brokers');
        }

        // Порт захватывается как [0-9]{1,5}: это всегда numeric-string,
        // PHP сравнивает её с int численно — приведение типа не требуется.
        foreach (\explode(',', $this->value) as $entry) {
            if (\preg_match(self::ENTRY_PATTERN, $entry, $matches) !== 1
                || (isset($matches[1]) && $matches[1] > self::MAX_PORT)
            ) {
                throw InvalidConfigException::brokers($this->value);
            }
        }
    }
}
