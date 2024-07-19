<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client;

use Anktx\Kafka\Client\Config\ProducerConfig;
use Anktx\Kafka\Client\Exception\Kafka\KafkaConnectionException;
use Anktx\Kafka\Client\Exception\Kafka\KafkaProducerException;
use Anktx\Kafka\Client\Message\KafkaProducerMessage;
use Anktx\Kafka\Client\PollStrategy\NeverPoolStrategy;
use Anktx\Kafka\Client\PollStrategy\PollStrategy;
use RdKafka\Producer;
use RdKafka\ProducerTopic;

final class KafkaProducer
{
    private readonly Producer $producer;

    /**
     * @var ProducerTopic[]
     */
    private array $topics = [];

    public function __construct(
        ProducerConfig $config,
        private readonly PollStrategy $pollStrategy = new NeverPoolStrategy(),
    ) {
        $this->producer = new Producer($config->asKafkaConfig());
    }

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
        } catch (\RdKafka\Exception $e) {
            throw new KafkaProducerException($e->getMessage(), $e->getCode(), $e->getPrevious());
        }
    }

    public function flush(int $timeoutMs = 1000): void
    {
        $rst = $this->producer->flush($timeoutMs);

        if ($rst === RD_KAFKA_RESP_ERR_NO_ERROR) {
            return;
        }

        if ($rst === RD_KAFKA_RESP_ERR__TIMED_OUT) {
            throw new KafkaConnectionException('Flush timed out in ' . $timeoutMs . 'ms');
        }

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
