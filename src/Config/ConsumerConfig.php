<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Config;

use Anktx\Kafka\Client\Config\Enum\OffsetReset;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class ConsumerConfig
{
    public function __construct(
        public readonly string $brokers,
        public readonly string $groupId,
        public readonly string $instanceId,
        public readonly OffsetReset $offsetReset = OffsetReset::earliest,
        public readonly ?int $autoCommitMs = null,
        public readonly ?int $sessionTimeoutMs = null,
        public readonly bool $isDebug = false,
        public readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function asKafkaConfig(): \RdKafka\Conf
    {
        $conf = new \RdKafka\Conf();

        $conf->setLogCb(function (\RdKafka\KafkaConsumer $consumer, int $level, string $facility, string $message) {
            $this->logger->log($level, $message);
        });
        if ($this->isDebug) {
            $conf->set('debug', 'all');
        }

        $conf->set('metadata.broker.list', $this->brokers);
        $conf->set('group.id', $this->groupId);
        $conf->set('group.instance.id', $this->instanceId);
        $conf->set('auto.offset.reset', $this->offsetReset->name);
        $conf->set('enable.partition.eof', 'true');
        if ($this->sessionTimeoutMs) {
            $conf->set('session.timeout.ms', (string) $this->sessionTimeoutMs);
        }
        if ($this->autoCommitMs) {
            $conf->set('auto.commit.interval.ms', (string) $this->autoCommitMs);
        } else {
            $conf->set('enable.auto.commit', 'false'); // default: true
        }

        return $conf;
    }
}
