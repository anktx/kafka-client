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
        public string $brokers,
        public int $queueBufferingMaxKBytes = 20480,
        public int $batchSize = 102400,
        public int $lingerMs = 10,
        public CompressionType $compressionType = CompressionType::Snappy,
        public bool $isDebug = false,
    ) {
        if ($this->brokers === '') {
            throw InvalidConfigException::emptyString('brokers');
        }

        Brokers::assertValid($this->brokers);

        if ($this->queueBufferingMaxKBytes <= 0) {
            throw InvalidConfigException::positiveInt('queueBufferingMaxKBytes', $this->queueBufferingMaxKBytes);
        }

        if ($this->batchSize <= 0) {
            throw InvalidConfigException::positiveInt('batchSize', $this->batchSize);
        }

        if ($this->lingerMs < 0) {
            throw InvalidConfigException::nonNegativeInt('lingerMs', $this->lingerMs);
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
            if ($this->isDebug) {
                $conf->set('debug', 'all');
            }

            $conf->set('bootstrap.servers', $this->brokers);
            $conf->set('compression.type', $this->compressionType->value);
            $conf->set('queue.buffering.max.kbytes', (string) $this->queueBufferingMaxKBytes);
            $conf->set('batch.size', (string) $this->batchSize);
            $conf->set('linger.ms', (string) $this->lingerMs);
        } catch (Exception $e) {
            throw InvalidConfigException::fromKafkaException($e);
        }

        return $conf;
    }
}
