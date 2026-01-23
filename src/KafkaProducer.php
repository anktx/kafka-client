<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client;

use Anktx\Kafka\Client\Config\ProducerConfig;
use Anktx\Kafka\Client\Exception\Kafka\KafkaConnectionException;
use Anktx\Kafka\Client\Exception\Kafka\KafkaProducerException;
use Anktx\Kafka\Client\KafkaMessage\KafkaProducerMessage;
use Anktx\Kafka\Client\PollStrategy\NeverPoolStrategy;
use Anktx\Kafka\Client\PollStrategy\PollStrategy;
use Psr\Log\LoggerInterface;
use RdKafka\Exception;
use RdKafka\Producer;
use RdKafka\ProducerTopic;

final class KafkaProducer
{
    private readonly Producer $producer;
    private readonly LoggerInterface $logger;

    /**
     * @var ProducerTopic[]
     */
    private array $topics = [];

    public function __construct(
        ProducerConfig $config,
        private readonly PollStrategy $pollStrategy = new NeverPoolStrategy(),
    ) {
        $this->logger = $config->logger;
        $this->producer = new Producer($config->asKafkaConfig());

        $this->logger->info('KafkaProducer created', [
            'brokers' => $config->brokers,
            'compression' => $config->compressionType->name,
            'poll_strategy' => $pollStrategy::class,
        ]);
    }

    /**
     * @throws KafkaProducerException
     */
    public function produce(KafkaProducerMessage $message): void
    {
        if ($this->pollStrategy->shouldPoll()) {
            while ($this->producer->getOutQLen() > 0) {
                $this->producer->poll(0);
            }
        }

        $topic = $this->topic($message->topic);

        try {
            $topic->producev(
                partition: $message->partition,
                msgflags: 0,
                payload: $message->body,
                key: $message->key,
                headers: $message->headers,
                timestamp_ms: $message->timestampMs,
            );
        } catch (Exception $e) {
            $this->logger->error('Failed to produce message', [
                'topic' => $message->topic,
                'partition' => $message->partition,
                'key' => $message->key,
                'error' => $e->getMessage(),
                'error_code' => $e->getCode(),
            ]);

            throw KafkaProducerException::fromKafkaException($e);
        }
    }

    /**
     * @throws KafkaConnectionException
     * @throws KafkaProducerException
     */
    public function flush(int $timeoutMs = 1000): void
    {
        $rst = $this->producer->flush($timeoutMs);

        if ($rst === \RD_KAFKA_RESP_ERR_NO_ERROR) {
            $this->logger->info('Producer flushed successfully', [
                'timeout_ms' => $timeoutMs,
            ]);

            return;
        }

        if ($rst === \RD_KAFKA_RESP_ERR__TIMED_OUT) {
            $this->logger->warning('Flush timed out', [
                'timeout_ms' => $timeoutMs,
                'error_code' => $rst,
            ]);

            throw new KafkaConnectionException('Flush timed out in ' . $timeoutMs . 'ms');
        }

        $this->logger->error('Flush failed', [
            'timeout_ms' => $timeoutMs,
            'error_code' => $rst,
        ]);

        throw new KafkaProducerException('Flush failed, error ' . $rst);
    }

    private function topic(string $name): ProducerTopic
    {
        if (! isset($this->topics[$name])) {
            $this->topics[$name] = $this->producer->newTopic($name);
        }

        return $this->topics[$name];
    }
}
