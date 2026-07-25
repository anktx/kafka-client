<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Exception\Kafka;

/**
 * Бросается, когда Kafka недоступна дольше заданного порога.
 *
 * librdkafka автоматически пытается переподключиться в фоновом режиме,
 * поэтому consume() может бесконечно возвращать RD_KAFKA_RESP_ERR__TIMED_OUT
 * даже при полной потере связи с брокерами. Данное исключение позволяет
 * приложению отличить длительную недоступность Kafka от штатного отсутствия
 * сообщений и завершить работу для перезапуска (например, в Kubernetes).
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
