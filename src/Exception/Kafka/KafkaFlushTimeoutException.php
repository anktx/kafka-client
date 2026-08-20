<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Exception\Kafka;

final class KafkaFlushTimeoutException extends KafkaException
{
    /**
     * $outQueueLen — размер недренжированной локальной очереди на момент
     * таймаута: показывает, сколько сообщений осталось непризнанными
     * (точно так же, как контекст warning-лога flush()).
     */
    public static function flushTimeout(int $timeoutMs, int $outQueueLen): self
    {
        return new self(
            \sprintf(
                'Flush timed out in %dms: %d message(s) still in local queue',
                $timeoutMs,
                $outQueueLen,
            ),
            \RD_KAFKA_RESP_ERR__TIMED_OUT,
        );
    }
}
