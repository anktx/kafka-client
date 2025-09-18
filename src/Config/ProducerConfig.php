<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Config;

use Anktx\Kafka\Client\Config\Enum\CompressionType;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RdKafka\Conf;
use RdKafka\Producer;

final class ProducerConfig
{
    public function __construct(
        public readonly string $brokers,
        public readonly int $queueBufferingMaxKBytes = 20480,
        public readonly int $batchSize = 102400,
        public readonly int $lingerMs = 10,
        public readonly CompressionType $compressionType = CompressionType::snappy,
        public readonly bool $isDebug = false,
        public readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public function asKafkaConfig(): Conf
    {
        $conf = new Conf();

        $conf->setLogCb(function (Producer $producer, int $level, string $facility, string $message) {
            $this->logger->log($level, $message);
        });
        if ($this->isDebug) {
            $conf->set('debug', 'all');
        }

        $conf->set('bootstrap.servers', $this->brokers);
        $conf->set('compression.type', $this->compressionType->name);
        $conf->set('queue.buffering.max.kbytes', (string) $this->queueBufferingMaxKBytes);
        $conf->set('batch.size', (string) $this->batchSize);
        $conf->set('linger.ms', (string) $this->lingerMs);

        return $conf;
    }
}
