<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client;

use Anktx\Kafka\Client\ConsumeResult\KafkaBrokersDown;
use Anktx\Kafka\Client\ConsumeResult\KafkaConsumeTimeout;
use Anktx\Kafka\Client\ConsumeResult\KafkaPartitionEof;
use Anktx\Kafka\Client\Exception\Kafka\KafkaConsumerException;
use Anktx\Kafka\Client\Exception\Logic\ClientClosedException;
use Anktx\Kafka\Client\Exception\Logic\NotSubscribedException;
use Anktx\Kafka\Client\KafkaMessage\KafkaConsumerMessage;

/**
 * Обёртка для консьюмера, предоставляющая поток сообщений через Generator.
 *
 * Упрощает потребление сообщений, фильтруя таймауты и EOF.
 * Возвращает только реальные сообщения через итератор.
 *
 * @example
 * ```php
 * $stream = new KafkaMessageStream($consumer);
 * foreach ($stream->stream() as $message) {
 *     // Обработка сообщения
 *     $consumer->commit($message);
 * }
 * ```
 */
final readonly class KafkaMessageStream
{
    private const int DEFAULT_POLL_TIMEOUT_MS = 1000;

    /**
     * Создаёт новый поток сообщений.
     *
     * @param KafkaConsumer $consumer      Консьюмер Kafka
     * @param int           $pollTimeoutMs Таймаут опроса в миллисекундах (по умолчанию 1000 мс)
     */
    public function __construct(
        private KafkaConsumer $consumer,
        private int $pollTimeoutMs = self::DEFAULT_POLL_TIMEOUT_MS,
    ) {}

    /**
     * Возвращает генератор, который выдаёт только реальные сообщения.
     *
     * Метод фильтрует служебные результаты:
     * - {@see KafkaConsumeTimeout} игнорируется
     * - {@see KafkaBrokersDown} игнорируется
     * - {@see KafkaPartitionEof} игнорируется
     * - {@see KafkaConsumerMessage} возвращается через yield
     *
     * Генератор бесконечен - для остановки нужно прервать цикл.
     *
     * Полная потеря связи с брокерами не прерывает генератор:
     * {@see KafkaConsumer::consume()} различает её (KafkaBrokersDown) и
     * таймаут, но stream() намеренно фильтрует оба случая одинаково —
     * продолжает опрос (не дольше pollTimeoutMs на итерацию) и
     * самовосстанавливается, когда librdkafka переподключится в фоновых
     * потоках. Различать их для метрик и watchdog'а используйте consume()/
     * consumeMatch() напрямую; «вечное» отсутствие брокеров через поток
     * не наблюдаемо — fail-fast контроль (внешний таймаут итераций,
     * health-check) выполняйте на уровне приложения.
     *
     * @return \Generator<int, KafkaConsumerMessage> Генератор сообщений
     *
     * @throws ClientClosedException  Если консьюмер закрыт через close()
     * @throws KafkaConsumerException Если произошла ошибка при чтении
     * @throws NotSubscribedException Если консьюмер не подписан на топики
     */
    public function stream(): \Generator
    {
        while (true) {
            $message = $this->consumer->consumeMatch(
                onMessage: static fn(KafkaConsumerMessage $msg): KafkaConsumerMessage => $msg,
                onTimeout: static fn(): null => null,
                onBrokersDown: static fn(): null => null,
                onEof: static fn(): null => null,
                timeoutMs: $this->pollTimeoutMs,
            );

            if ($message !== null) {
                yield $message;
            }
        }
    }
}
