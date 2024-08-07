<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client;

use Anktx\Kafka\Client\Config\ConsumerConfig;
use Anktx\Kafka\Client\Exception\Business\EmptySubscriptionsException;
use Anktx\Kafka\Client\Exception\Kafka\KafkaConnectionException;
use Anktx\Kafka\Client\Exception\Kafka\KafkaConsumerException;
use Anktx\Kafka\Client\Exception\Logic\PartitionEofException;
use Anktx\Kafka\Client\Exception\Logic\PartitionTimeoutException;
use Anktx\Kafka\Client\Message\KafkaConsumerMessage;
use Anktx\Kafka\Client\Subscription\SubscriptionList;
use RdKafka\Exception as RdKafkaException;
use RdKafka\TopicPartition;

final class KafkaConsumer
{
    private readonly \RdKafka\KafkaConsumer $consumer;

    /**
     * @throws KafkaConnectionException
     * @throws KafkaConsumerException
     */
    public function __construct(
        ConsumerConfig $config,
        int $timeoutMs = 5000,
    ) {
        $this->consumer = new \RdKafka\KafkaConsumer($config->asKafkaConfig());
        $this->assertBrokersAreAlive($timeoutMs);
    }

    /**
     * @throws EmptySubscriptionsException
     * @throws KafkaConsumerException
     */
    public function subscribe(SubscriptionList $subscriptionList): void
    {
        if ($subscriptionList->isEmpty()) {
            throw new EmptySubscriptionsException('At least one subscription is required');
        }

        try {
            $this->consumer->subscribe($subscriptionList->topicNames());
        } catch (RdKafkaException $e) {
            throw KafkaConsumerException::fromKafkaException($e);
        }

        try {
            $this->consumer->assign($this->commitedOffsets($subscriptionList)->asKafkaTopicPartitionArray());
        } catch (RdKafkaException $e) {
            throw KafkaConsumerException::fromKafkaException($e);
        }
    }

    /**
     * @throws PartitionEofException
     * @throws PartitionTimeoutException
     * @throws KafkaConsumerException
     */
    public function consume(int $timeoutMs = 1000): KafkaConsumerMessage
    {
        try {
            $message = $this->consumer->consume($timeoutMs);
        } catch (RdKafkaException $e) {
            throw KafkaConsumerException::fromKafkaException($e);
        }

        switch ($message->err) {
            case \RD_KAFKA_RESP_ERR_NO_ERROR:
                break;
            case \RD_KAFKA_RESP_ERR__PARTITION_EOF:
                throw PartitionEofException::create($message->errstr());
            case \RD_KAFKA_RESP_ERR__TIMED_OUT:
                throw PartitionTimeoutException::create($message->errstr());
            default:
                throw new KafkaConsumerException($message->errstr());
        }

        return new KafkaConsumerMessage(
            topic: $message->topic_name,
            body: $message->payload,
            partition: $message->partition,
            offset: $message->offset,
            key: $message->key,
            headers: $message->headers,
            timestampMs: $message->timestamp,
        );
    }

    /**
     * @throws KafkaConsumerException
     */
    public function commit(KafkaConsumerMessage $message): void
    {
        try {
            $this->consumer->commit([
                new TopicPartition($message->topic, $message->partition, $message->offset + 1),
            ]);
        } catch (RdKafkaException $e) {
            throw KafkaConsumerException::fromKafkaException($e);
        }
    }

    public function close(): void
    {
        $this->consumer->close();
    }

    private function commitedOffsets(SubscriptionList $subscriptionList, int $timeoutMs = 1000): SubscriptionList
    {
        return SubscriptionList::fromKafkaTopicPartition(
            ...$this->consumer->getCommittedOffsets(
                topic_partitions: $subscriptionList->asKafkaTopicPartitionArray(),
                timeout_ms: $timeoutMs,
            ));
    }

    /**
     * @throws KafkaConsumerException
     * @throws KafkaConnectionException
     */
    private function assertBrokersAreAlive(int $timeoutMs): void
    {
        try {
            $this->consumer->getMetadata(
                all_topics: true,
                only_topic: null,
                timeout_ms: $timeoutMs,
            );
        } catch (RdKafkaException $e) {
            throw match ($e->getCode()) {
                \RD_KAFKA_RESP_ERR__TRANSPORT => KafkaConnectionException::fromKafkaException($e),
                default => KafkaConsumerException::fromKafkaException($e),
            };
        }
    }
}
