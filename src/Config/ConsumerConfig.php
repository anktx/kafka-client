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
        public ?int $sessionTimeoutMs = null,
        public ?int $reconnectBackoffMs = null,
        public ?int $reconnectBackoffMaxMs = null,
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

        if ($this->sessionTimeoutMs !== null && $this->sessionTimeoutMs <= 0) {
            throw InvalidConfigException::positiveInt('sessionTimeoutMs', $this->sessionTimeoutMs);
        }

        if ($this->reconnectBackoffMs !== null && $this->reconnectBackoffMs < 0) {
            throw InvalidConfigException::nonNegativeInt('reconnectBackoffMs', $this->reconnectBackoffMs);
        }

        if ($this->reconnectBackoffMaxMs !== null && $this->reconnectBackoffMaxMs < 0) {
            throw InvalidConfigException::nonNegativeInt('reconnectBackoffMaxMs', $this->reconnectBackoffMaxMs);
        }

        if ($this->reconnectBackoffMs !== null && $this->reconnectBackoffMaxMs !== null
            && $this->reconnectBackoffMaxMs < $this->reconnectBackoffMs
        ) {
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
            $this->configureReconnect($conf);
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
        if ($this->sessionTimeoutMs !== null) {
            $conf->set('session.timeout.ms', (string) $this->sessionTimeoutMs);
        }
    }

    private function configureReconnect(Conf $conf): void
    {
        if ($this->reconnectBackoffMs !== null) {
            $conf->set('reconnect.backoff.ms', (string) $this->reconnectBackoffMs);
        }

        if ($this->reconnectBackoffMaxMs !== null) {
            $conf->set('reconnect.backoff.max.ms', (string) $this->reconnectBackoffMaxMs);
        }

        $conf->set('socket.keepalive.enable', $this->socketKeepaliveEnable ? 'true' : 'false');
    }
}
