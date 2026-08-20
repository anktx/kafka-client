<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\StreamObserver;

use Anktx\Kafka\Client\Clock\SystemClock;
use Anktx\Kafka\Client\Clock\UnixMilliseconds;
use Anktx\Kafka\Client\ConsumeResult\KafkaBrokersDown;
use Anktx\Kafka\Client\ConsumeResult\KafkaConsumeTimeout;
use Anktx\Kafka\Client\ConsumeResult\KafkaPartitionEof;
use Anktx\Kafka\Client\Exception\Kafka\KafkaBrokersDownException;
use Anktx\Kafka\Client\Exception\Logic\InvalidConfigException;
use Anktx\Kafka\Client\KafkaMessage\KafkaConsumerMessage;
use Psr\Clock\ClockInterface;

/**
 * Fail-fast реакция на «вечную» потерю всех брокеров: прерывает поток,
 * когда брокеры недоступны непрерывно дольше бюджета maxBrokersDownMs.
 *
 * Сценарий — сетевая проблема, которую не исправить реконнектом
 * librdkafka изнутри процесса: пусть воркер упадёт с
 * {@see KafkaBrokersDownException}, и супервизор пересоздаёт процесс
 * (свежие DNS-резолвы, сетевые интерфейсы, sidecar-прокси).
 *
 * Семантика бюджета — wall-clock от первого подряд идущего
 * {@see KafkaBrokersDown}:
 * - сообщение и EOF — доказательства живого соединения — сбрасывают окно;
 * - {@see KafkaConsumeTimeout} игнорируется: не доказывает ни потери
 *   (тишина в топике неотличима), ни восстановления.
 */
final class BrokersDownBudgetStreamObserver implements StreamObserver
{
    private ?int $downSinceMs = null;

    /**
     * @param int            $maxBrokersDownMs Бюджет непрерывной потери всех брокеров в миллисекундах
     * @param ClockInterface $clock            Источник времени (по умолчанию системные часы)
     *
     * @throws InvalidConfigException Если maxBrokersDownMs не положителен
     */
    public function __construct(
        public readonly int $maxBrokersDownMs,
        private readonly ClockInterface $clock = new SystemClock(),
    ) {
        if ($this->maxBrokersDownMs < 1) {
            throw InvalidConfigException::positiveInt('maxBrokersDownMs', $this->maxBrokersDownMs);
        }
    }

    public function onMessage(KafkaConsumerMessage $message): void
    {
        $this->downSinceMs = null;
    }

    public function onTimeout(KafkaConsumeTimeout $timeout): void {}

    public function onBrokersDown(KafkaBrokersDown $brokersDown): void
    {
        $nowMs = UnixMilliseconds::of($this->clock->now());

        if ($this->downSinceMs === null) {
            $this->downSinceMs = $nowMs;
        }

        $downForMs = $nowMs - $this->downSinceMs;

        if ($downForMs >= $this->maxBrokersDownMs) {
            throw KafkaBrokersDownException::brokersDownFor($downForMs, $this->maxBrokersDownMs);
        }
    }

    public function onEof(KafkaPartitionEof $eof): void
    {
        $this->downSinceMs = null;
    }
}
