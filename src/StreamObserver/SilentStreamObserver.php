<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\StreamObserver;

use Anktx\Kafka\Client\ConsumeResult\KafkaBrokersDown;
use Anktx\Kafka\Client\ConsumeResult\KafkaConsumeTimeout;
use Anktx\Kafka\Client\ConsumeResult\KafkaPartitionEof;
use Anktx\Kafka\Client\KafkaMessage\KafkaConsumerMessage;

/**
 * Молчаливая реакция «никогда не прерывать» — дефолтный наблюдатель
 * потока сообщений.
 *
 * Поглощает все результаты: полная потеря брокеров переживается тихо,
 * librdkafka переподключается в фоновых потоках, и поток
 * самовосстанавливается, когда связь вернётся.
 *
 * Класс намеренно не final: это база для Null Object-наблюдателей —
 * наследуйтесь и переопределяйте только нужные хуки, остальные
 * остаются молчаливыми.
 */
class SilentStreamObserver implements StreamObserver
{
    public function onMessage(KafkaConsumerMessage $message): void {}

    public function onTimeout(KafkaConsumeTimeout $timeout): void {}

    public function onBrokersDown(KafkaBrokersDown $brokersDown): void {}

    public function onEof(KafkaPartitionEof $eof): void {}
}
