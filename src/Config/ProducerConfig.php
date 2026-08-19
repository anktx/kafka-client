<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Config;

use Anktx\Kafka\Client\Config\Enum\CompressionType;
use RdKafka\Conf;

final readonly class ProducerConfig
{
    public function __construct(
        public string $brokers,
        public int $queueBufferingMaxKBytes = 20480,
        public int $batchSize = 102400,
        public int $lingerMs = 10,
        public CompressionType $compressionType = CompressionType::snappy,
        public bool $isDebug = false,
    ) {}

    public function asKafkaConfig(): Conf
    {
        $conf = new Conf();

        if ($this->isDebug) {
            $conf->set('debug', 'all');
        }

        $conf->set('bootstrap.servers', $this->brokers);
        $conf->set('compression.type', $this->compressionType->value);
        $conf->set('queue.buffering.max.kbytes', (string) $this->queueBufferingMaxKBytes);
        $conf->set('batch.size', (string) $this->batchSize);
        $conf->set('linger.ms', (string) $this->lingerMs);

        return $conf;
    }
}
