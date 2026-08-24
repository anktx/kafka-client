<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Config;

use Anktx\Kafka\Client\Config\Enum\OffsetReset;
use Anktx\Kafka\Client\Exception\Logic\InvalidConfigException;
use RdKafka\Conf;
use RdKafka\Exception;

final readonly class ConsumerConfig
{
    public function __construct(
        public Brokers $brokers,
        public string $groupId,
        public ?string $instanceId = null,
        public OffsetReset $offsetReset = OffsetReset::Earliest,
        public ?int $autoCommitMs = null,
        public int $sessionTimeoutMs = 30000,
        public int $heartbeatIntervalMs = 3000,
        public int $maxPollIntervalMs = 300000,
        public int $connectionsMaxIdleMs = 540000,
        public int $reconnectBackoffMs = 100,
        public int $reconnectBackoffMaxMs = 10000,
        public bool $socketKeepaliveEnable = true,
        public bool $isDebug = false,
    ) {
        if ($this->groupId === '') {
            throw InvalidConfigException::emptyString('groupId');
        }

        if ($this->instanceId !== null && $this->instanceId === '') {
            throw InvalidConfigException::emptyString('instanceId');
        }

        if ($this->autoCommitMs !== null && $this->autoCommitMs < 0) {
            throw InvalidConfigException::nonNegativeInt('autoCommitMs', $this->autoCommitMs);
        }

        if ($this->sessionTimeoutMs <= 0) {
            throw InvalidConfigException::positiveInt('sessionTimeoutMs', $this->sessionTimeoutMs);
        }

        if ($this->heartbeatIntervalMs <= 0) {
            throw InvalidConfigException::positiveInt('heartbeatIntervalMs', $this->heartbeatIntervalMs);
        }

        if ($this->maxPollIntervalMs <= 0) {
            throw InvalidConfigException::positiveInt('maxPollIntervalMs', $this->maxPollIntervalMs);
        }

        if ($this->connectionsMaxIdleMs < 0) {
            throw InvalidConfigException::nonNegativeInt('connectionsMaxIdleMs', $this->connectionsMaxIdleMs);
        }

        if (3 * $this->heartbeatIntervalMs > $this->sessionTimeoutMs) {
            throw InvalidConfigException::heartbeatSessionRange($this->heartbeatIntervalMs, $this->sessionTimeoutMs);
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
            $this->configureCommit($conf);
            $this->configureTimeouts($conf);
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
        $conf->set('metadata.broker.list', $this->brokers->value);
        $conf->set('group.id', $this->groupId);

        if ($this->instanceId !== null) {
            $conf->set('group.instance.id', $this->instanceId);
        }

        $conf->set('auto.offset.reset', $this->offsetReset->value);
        $conf->set('enable.partition.eof', 'true');
    }

    private function configureCommit(Conf $conf): void
    {
        if ($this->autoCommitMs !== null) {
            $conf->set('enable.auto.commit', 'true');
            $conf->set('auto.commit.interval.ms', (string) $this->autoCommitMs);
        } else {
            $conf->set('enable.auto.commit', 'false');
        }
    }

    private function configureTimeouts(Conf $conf): void
    {
        $conf->set('session.timeout.ms', (string) $this->sessionTimeoutMs);
        $conf->set('heartbeat.interval.ms', (string) $this->heartbeatIntervalMs);
        $conf->set('max.poll.interval.ms', (string) $this->maxPollIntervalMs);
    }

    private function configureConnection(Conf $conf): void
    {
        $conf->set('connections.max.idle.ms', (string) $this->connectionsMaxIdleMs);
        $conf->set('reconnect.backoff.ms', (string) $this->reconnectBackoffMs);
        $conf->set('reconnect.backoff.max.ms', (string) $this->reconnectBackoffMaxMs);
        $conf->set('socket.keepalive.enable', $this->socketKeepaliveEnable ? 'true' : 'false');
    }
}
