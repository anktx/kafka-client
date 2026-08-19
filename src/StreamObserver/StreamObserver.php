<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\StreamObserver;

use Anktx\Kafka\Client\ConsumeResult\KafkaBrokersDown;
use Anktx\Kafka\Client\ConsumeResult\KafkaConsumeTimeout;
use Anktx\Kafka\Client\ConsumeResult\KafkaPartitionEof;
use Anktx\Kafka\Client\KafkaConsumer;
use Anktx\Kafka\Client\KafkaMessage\KafkaConsumerMessage;
use Anktx\Kafka\Client\KafkaMessageStream;

/**
 * Реакция на результаты consume() в потоке сообщений
 * {@see KafkaMessageStream}.
 *
 * Хуки зеркалят колбэки {@see KafkaConsumer::consumeMatch()}
 * и вызываются по каждому результату до выдачи сообщения наружу:
 * обычный возврат — опрос продолжается, исключение — прерывает генератор
 * и становится наблюдаемым для воркера и супервизора (restart-политика
 * Docker, restartPolicy Kubernetes).
 *
 * Хук {@see StreamObserver::onMessage()} включён намеренно: сообщение —
 * доказательство живого соединения, по нему политики сбрасывают свои
 * окна (например, бюджет потери брокеров), а метрики считают throughput.
 *
 * Реализация должна быть быстрой и синхронной: хуки вызываются на каждый
 * результат и не должны блокировать опрос.
 */
interface StreamObserver
{
    /** Получено сообщение; оно же будет выдано через генератор после возврата хука. */
    public function onMessage(KafkaConsumerMessage $message): void;

    /** За окно опроса не пришло сообщений (тишина в топике или сетевая проблема). */
    public function onTimeout(KafkaConsumeTimeout $timeout): void;

    /** Полная потеря соединения со всеми брокерами: librdkafka переподключается в фоне. */
    public function onBrokersDown(KafkaBrokersDown $brokersDown): void;

    /** Достигнут конец партиции. */
    public function onEof(KafkaPartitionEof $eof): void;
}
