<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Config;

use Anktx\Kafka\Client\Config\Enum\OffsetReset;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RdKafka\Conf;
use RdKafka\KafkaConsumer;

final readonly class ConsumerConfig
{
    public function __construct(
        public string $brokers,
        public string $groupId,
        public string $instanceId,
        public OffsetReset $offsetReset = OffsetReset::earliest,
        public ?int $autoCommitMs = null,
        public ?int $sessionTimeoutMs = null,
        public bool $isDebug = false,
        public LoggerInterface $logger = new NullLogger(),
    ) {}

    public function asKafkaConfig(): Conf
    {
        $conf = new Conf();

        $this->configureLogging($conf);
        $this->configureDebug($conf);
        $this->configureEssentials($conf);
        $this->configureCommit($conf);
        $this->configureTimeouts($conf);

        return $conf;
    }

    private function configureLogging(Conf $conf): void
    {
        $conf->setLogCb(function (KafkaConsumer $consumer, int $level, string $facility, string $message) {
            $this->logger->log($level, $message, ['facility' => $facility]);
        });
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
        $conf->set('group.instance.id', $this->instanceId);
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
        if ($this->sessionTimeoutMs) {
            $conf->set('session.timeout.ms', (string) $this->sessionTimeoutMs);
        }
        // Можно добавить heartbeat.interval.ms, max.poll.interval.ms и т.д.
    }
}
