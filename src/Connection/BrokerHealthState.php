<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Connection;

/**
 * Отслеживает состояние соединения с брокерами Kafka.
 *
 * Регистрирует ошибки подключения, фиксируемые через error callback
 * librdkafka, и позволяет определить, как долго Kafka была недоступна.
 *
 * Восстановление соединения фиксируется при получении сообщения или
 * достижении конца партиции — это однозначно свидетельствует об успешном
 * обмене данными с брокером.
 *
 * Текущее время передаётся параметром, чтобы класс оставался чистым
 * конечным автоматом без скрытых зависимостей.
 *
 * Выделен в отдельный класс, потому что KafkaConsumer нельзя покрыть
 * unit-тестами (жёсткая зависимость от ext-rdkafka), а логика контроля
 * порога недоступности требует детерминированной проверки.
 *
 * @see https://github.com/confluentinc/librdkafka/wiki/Error-handling
 */
final class BrokerHealthState
{
    /**
     * Коды ошибок librdkafka, свидетельствующие о потере соединения.
     *
     * @var list<int>
     */
    private const array CONNECTION_ERROR_CODES = [
        \RD_KAFKA_RESP_ERR__ALL_BROKERS_DOWN,
        \RD_KAFKA_RESP_ERR__TRANSPORT,
        \RD_KAFKA_RESP_ERR__RESOLVE,
    ];
    private ?float $unavailableSince = null;

    /**
     * Определяет, свидетельствует ли код ошибки о потере соединения с брокерами.
     */
    public static function isConnectionError(int $errorCode): bool
    {
        return \in_array($errorCode, self::CONNECTION_ERROR_CODES, true);
    }

    /**
     * Фиксирует начало недоступности Kafka.
     *
     * Повторные вызовы не сбрасывают момент начала недоступности.
     *
     * @param float $now Текущее время в секундах (microtime(true))
     */
    public function markUnavailable(float $now): void
    {
        if ($this->unavailableSince === null) {
            $this->unavailableSince = $now;
        }
    }

    /**
     * Фиксирует восстановление соединения с брокерами.
     *
     * Вызывается при успешном получении сообщения или достижении конца
     * партиции — подтверждает, что обмен данными с брокером работает.
     */
    public function markAvailable(): void
    {
        $this->unavailableSince = null;
    }

    /**
     * Возвращает true, если в данный момент фиксируется недоступность Kafka.
     */
    public function isUnavailable(): bool
    {
        return $this->unavailableSince !== null;
    }

    /**
     * Возвращает длительность текущей недоступности в секундах.
     *
     * @param float $now Текущее время в секундах (microtime(true))
     *
     * @return float 0.0, если соединение не нарушено
     */
    public function unavailableDurationSec(float $now): float
    {
        if ($this->unavailableSince === null) {
            return 0.0;
        }

        return $now - $this->unavailableSince;
    }

    /**
     * Проверяет, превышен ли порог недоступности.
     *
     * @param float $now          Текущее время в секундах (microtime(true))
     * @param int   $thresholdSec Порог в секундах
     */
    public function isUnavailableFor(float $now, int $thresholdSec): bool
    {
        return $this->unavailableSince !== null
            && $this->unavailableDurationSec($now) >= $thresholdSec;
    }
}
