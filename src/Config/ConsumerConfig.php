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

        $conf->setLogCb(function (KafkaConsumer $consumer, int $level, string $facility, string $message) {
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
