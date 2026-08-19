<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Config;

use Anktx\Kafka\Client\Config\Enum\OffsetReset;
use RdKafka\Conf;

final readonly class ConsumerConfig
{
    public function __construct(
        public string $brokers,
        public string $groupId,
        public ?string $instanceId = null,
        public OffsetReset $offsetReset = OffsetReset::earliest,
        public ?int $autoCommitMs = null,
        public ?int $sessionTimeoutMs = null,
        public ?int $reconnectBackoffMs = null,
        public ?int $reconnectBackoffMaxMs = null,
        public bool $socketKeepaliveEnable = true,
        public bool $isDebug = false,
    ) {}

    public function asKafkaConfig(): Conf
    {
        $conf = new Conf();

        $this->configureDebug($conf);
        $this->configureEssentials($conf);
        $this->configureCommit($conf);
        $this->configureTimeouts($conf);
        $this->configureReconnect($conf);

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
        $conf->set('metadata.broker.list', $this->brokers);
        $conf->set('group.id', $this->groupId);

        if ($this->instanceId !== null) {
            $conf->set('group.instance.id', $this->instanceId);
        }

        $conf->set('auto.offset.reset', $this->offsetReset->name);
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
