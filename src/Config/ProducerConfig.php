<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Config;

use Anktx\Kafka\Client\Config\Enum\CompressionType;
use Anktx\Kafka\Client\Exception\Logic\InvalidConfigException;
use RdKafka\Conf;
use RdKafka\Exception;

final readonly class ProducerConfig
{
    public function __construct(
        public Brokers $brokers,
        public int $queueBufferingMaxKBytes = 20480,
        public int $batchSize = 102400,
        public int $lingerMs = 10,
        public CompressionType $compressionType = CompressionType::Snappy,
        public bool $enableIdempotence = true,
        public int $messageTimeoutMs = 120000,
        public int $connectionsMaxIdleMs = 180000,
        public int $reconnectBackoffMs = 100,
        public int $reconnectBackoffMaxMs = 10000,
        public bool $socketKeepaliveEnable = true,
        public bool $isDebug = false,
    ) {
        if ($this->queueBufferingMaxKBytes <= 0) {
            throw InvalidConfigException::positiveInt('queueBufferingMaxKBytes', $this->queueBufferingMaxKBytes);
        }

        if ($this->batchSize <= 0) {
            throw InvalidConfigException::positiveInt('batchSize', $this->batchSize);
        }

        if ($this->lingerMs < 0) {
            throw InvalidConfigException::nonNegativeInt('lingerMs', $this->lingerMs);
        }

        if ($this->messageTimeoutMs <= 0) {
            throw InvalidConfigException::positiveInt('messageTimeoutMs', $this->messageTimeoutMs);
        }

        if ($this->connectionsMaxIdleMs < 0) {
            throw InvalidConfigException::nonNegativeInt('connectionsMaxIdleMs', $this->connectionsMaxIdleMs);
        }

        if ($this->reconnectBackoffMs < 0) {
            throw InvalidConfigException::nonNegativeInt('reconnectBackoffMs', $this->reconnectBackoffMs);
        }

        if ($this->reconnectBackoffMaxMs < 0) {
            throw InvalidConfigException::nonNegativeInt('reconnectBackoffMaxMs', $this->reconnectBackoffMaxMs);
        }

        if ($this->reconnectBackoffMaxMs < $this->reconnectBackoffMs) {
            throw InvalidConfigException::backoffRange($this->reconnectBackoffMs, $this->reconnectBackoffMaxMs);
        }
    }

    /**
     * Собирает нативную конфигурацию RdKafka из параметров объекта.
     *
     * @throws InvalidConfigException Если librdkafka отклонил значение параметра
     *                                (например, вне допустимого диапазона)
     */
    public function asKafkaConfig(): Conf
    {
        $conf = new Conf();

        try {
            $this->configureDebug($conf);
            $this->configureEssentials($conf);
            $this->configureBatching($conf);
            $this->configureDelivery($conf);
            $this->configureConnection($conf);
        } catch (Exception $e) {
            throw InvalidConfigException::fromKafkaException($e);
        }

        return $conf;
    }

    private function configureDebug(Conf $conf): void
    {
        if ($this->isDebug) {
            $conf->set('debug', 'all');
        }
    }

    private function configureEssentials(Conf $conf): void
    {
        $conf->set('bootstrap.servers', $this->brokers->value);
        $conf->set('compression.type', $this->compressionType->value);
    }

    private function configureBatching(Conf $conf): void
    {
        $conf->set('queue.buffering.max.kbytes', (string) $this->queueBufferingMaxKBytes);
        $conf->set('batch.size', (string) $this->batchSize);
        $conf->set('linger.ms', (string) $this->lingerMs);
    }

    private function configureDelivery(Conf $conf): void
    {
        $conf->set('enable.idempotence', $this->enableIdempotence ? 'true' : 'false');
        $conf->set('message.timeout.ms', (string) $this->messageTimeoutMs);
    }

    private function configureConnection(Conf $conf): void
    {
        $conf->set('connections.max.idle.ms', (string) $this->connectionsMaxIdleMs);
        $conf->set('reconnect.backoff.ms', (string) $this->reconnectBackoffMs);
        $conf->set('reconnect.backoff.max.ms', (string) $this->reconnectBackoffMaxMs);
        $conf->set('socket.keepalive.enable', $this->socketKeepaliveEnable ? 'true' : 'false');
    }
}
