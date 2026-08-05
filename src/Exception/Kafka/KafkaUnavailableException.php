<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Exception\Kafka;

use Anktx\Kafka\Client\ConsumeResult\KafkaConsumeTimeout;
use Anktx\Kafka\Client\KafkaConsumer;

/**
 * Бросается, когда Kafka недоступна дольше заданного порога.
 *
 * Внимание: начиная с версии 0.7.0 это исключение больше НЕ выбрасывается из
 * {@see KafkaConsumer::consume()}. Раньше проверка порога недоступности стояла
 * перед librdkafka consume() и блокировала rebalance-протокол (JoinGroup/SyncGroup),
 * что приводило к необратимому зависанию consumer-group. Теперь consume()
 * делегирует переподключение librdkafka и возвращает {@see KafkaConsumeTimeout}
 * при RD_KAFKA_RESP_ERR__ALL_BROKERS_DOWN. Класс сохранён для обратной совместимости.
 *
 * librdkafka автоматически пытается переподключиться в фоновом режиме,
 * поэтому consume() может бесконечно возвращать RD_KAFKA_RESP_ERR__TIMED_OUT
 * даже при полной потере связи с брокерами.
 */
final class KafkaUnavailableException extends KafkaException
{
    public static function create(int $thresholdSec, float $actualSec): self
    {
        return new self(\sprintf(
            'Kafka has been unavailable for %.1f seconds (threshold: %d seconds)',
            $actualSec,
            $thresholdSec,
        ));
    }
}
