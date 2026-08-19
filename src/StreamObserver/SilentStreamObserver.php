<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\StreamObserver;

use Anktx\Kafka\Client\ConsumeResult\KafkaBrokersDown;
use Anktx\Kafka\Client\ConsumeResult\KafkaConsumeTimeout;
use Anktx\Kafka\Client\ConsumeResult\KafkaPartitionEof;
use Anktx\Kafka\Client\KafkaMessage\KafkaConsumerMessage;
use Anktx\Kafka\Client\KafkaMessageStream;

/**
 * Молчаливая реакция «никогда не прерывать» — дефолт
 * {@see KafkaMessageStream}.
 *
 * Поглощает все результаты: полная потеря брокеров переживается тихо,
 * librdkafka переподключается в фоновых потоках, и поток
 * самовосстанавливается, когда связь вернётся.
 */
final class SilentStreamObserver implements StreamObserver
{
    public function onMessage(KafkaConsumerMessage $message): void {}

    public function onTimeout(KafkaConsumeTimeout $timeout): void {}

    public function onBrokersDown(KafkaBrokersDown $brokersDown): void {}

    public function onEof(KafkaPartitionEof $eof): void {}
}
