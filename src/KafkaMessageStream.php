<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client;

use Anktx\Kafka\Client\ConsumeResult\ConsumeResult;
use Anktx\Kafka\Client\ConsumeResult\KafkaConsumeTimeout;
use Anktx\Kafka\Client\ConsumeResult\KafkaPartitionEof;
use Anktx\Kafka\Client\Exception\Kafka\KafkaConsumerException;
use Anktx\Kafka\Client\Exception\Logic\ClientClosedException;
use Anktx\Kafka\Client\Exception\Logic\InvalidConfigException;
use Anktx\Kafka\Client\Exception\Logic\NotSubscribedException;
use Anktx\Kafka\Client\KafkaMessage\KafkaConsumerMessage;
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
     * @param KafkaConsumerInterface $consumer      Консьюмер Kafka
     * @param int                    $pollTimeoutMs Таймаут опроса в миллисекундах (по умолчанию 1000 мс)
     * @param StreamObserver         $observer      Реакция на результаты consume() (по умолчанию — молчаливая)
     *
     * @throws InvalidConfigException Если таймаут опроса отрицательный
     */
    public function __construct(
        private KafkaConsumerInterface $consumer,
        private int $pollTimeoutMs = self::DEFAULT_POLL_TIMEOUT_MS,
        private StreamObserver $observer = new SilentStreamObserver(),
    ) {
        if ($this->pollTimeoutMs < 0) {
            throw InvalidConfigException::nonNegativeInt('pollTimeoutMs', $this->pollTimeoutMs);
        }
    }

    /**
     * Возвращает генератор, который выдаёт только реальные сообщения.
     *
     * Каждый результат consume() сначала передаётся наблюдателю
     * {@see StreamObserver} — хуками onMessage/onTimeout/onEof; исключение
     * из хука прерывает генератор. Так нештатные ситуации получают fail-fast
     * реакцию, а управление остаётся у вызывающего кода.
     *
     * Наблюдатель по умолчанию {@see SilentStreamObserver} поглощает всё:
     * генератор бесконечен (для остановки нужно прервать цикл), потерю
     * брокеров переживает молча — из consume() обрыв виден только как серия
     * таймаутов, и поток самовосстанавливается, когда librdkafka
     * переподключится в фоновых потоках. Отличать «тишину в топике» от
     * сетевой проблемы для метрик/watchdog'а — задача наблюдателя: бюджет
     * тишины собирается по onTimeout и сбрасывается по onMessage.
     *
     * @return \Generator<int, KafkaConsumerMessage> Генератор сообщений
     *
     * @throws ClientClosedException  Если консьюмер закрыт через close()
     * @throws KafkaConsumerException Если произошла ошибка при чтении; любое
     *                                исключение из хука наблюдателя прерывает генератор
     * @throws NotSubscribedException Если консьюмер не подписан на топики
     */
    public function stream(): \Generator
    {
        while (true) {
            $result = $this->consumer->consume(timeoutMs: $this->pollTimeoutMs);

            $this->dispatchToObserver($result);

            if ($result instanceof KafkaConsumerMessage) {
                yield $result;
            }
        }
    }

    /**
     * Диспетчеризирует результат consume() хукам наблюдателя.
     *
     * @throws KafkaConsumerException Если результат вне известного union consume()
     */
    private function dispatchToObserver(ConsumeResult $result): void
    {
        // Ветви обязаны покрывать union consume() (соответствие зафиксировано
        // рефлексионным тестом ConsumeResultTest); default превращает забытое
        // при расширении union звено в типизированный отказ вместо
        // \UnhandledMatchError.
        match ($result::class) {
            KafkaConsumerMessage::class => $this->observer->onMessage($result),
            KafkaConsumeTimeout::class => $this->observer->onTimeout($result),
            KafkaPartitionEof::class => $this->observer->onEof($result),
            default => throw KafkaConsumerException::create(\sprintf(
                'Unexpected ConsumeResult implementation: %s',
                $result::class,
            )),
        };
    }
}
