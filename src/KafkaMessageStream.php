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
use Anktx\Kafka\Client\StreamObserver\BrokersDownBudgetStreamObserver;
use Anktx\Kafka\Client\StreamObserver\SilentStreamObserver;
use Anktx\Kafka\Client\StreamObserver\StreamObserver;

/**
 * Обёртка для консьюмера, предоставляющая поток сообщений через Generator.
 *
 * Упрощает потребление сообщений, фильтруя таймауты и EOF.
 * Возвращает только реальные сообщения через итератор.
 *
 * @example
 * ```php
 * $stream = new KafkaMessageStream(
 *     $consumer,
 *     new BrokersDownBudgetStreamObserver(maxBrokersDownMs: 30_000),
 * );
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
     * @param KafkaConsumer  $consumer      Консьюмер Kafka
     * @param int            $pollTimeoutMs Таймаут опроса в миллисекундах (по умолчанию 1000 мс)
     * @param StreamObserver $observer      Реакция на результаты consume() (по умолчанию — молчаливая)
     */
    public function __construct(
        private KafkaConsumer $consumer,
        private int $pollTimeoutMs = self::DEFAULT_POLL_TIMEOUT_MS,
        private StreamObserver $observer = new SilentStreamObserver(),
    ) {}

    /**
     * Возвращает генератор, который выдаёт только реальные сообщения.
     *
     * Каждый результат consume() сначала передаётся наблюдателю
     * {@see StreamObserver} — хуками onMessage/onTimeout/onBrokersDown/onEof,
     * зеркалящими колбэки {@see KafkaConsumer::consumeMatch()}; исключение
     * из хука прерывает генератор. Так нештатные ситуации (например,
     * «вечная» потеря брокеров — {@see BrokersDownBudgetStreamObserver})
     * получают fail-fast реакцию, а управление — вызывающий код.
     *
     * Наблюдатель по умолчанию {@see SilentStreamObserver} поглощает всё:
     * генератор бесконечен (для остановки нужно прервать цикл), полную
     * потерю брокеров переживает молча и самовосстанавливается, когда
     * librdkafka переподключится в фоновых потоках. Различать потерю
     * брокеров и таймаут для метрик/watchdog'а используйте consume()/
     * consumeMatch() напрямую.
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
            $result = $this->consumer->consume(timeoutMs: $this->pollTimeoutMs);

            match ($result::class) {
                KafkaConsumerMessage::class => $this->observer->onMessage($result),
                KafkaConsumeTimeout::class => $this->observer->onTimeout($result),
                KafkaBrokersDown::class => $this->observer->onBrokersDown($result),
                KafkaPartitionEof::class => $this->observer->onEof($result),
            };

            if ($result instanceof KafkaConsumerMessage) {
                yield $result;
            }
        }
    }
}
