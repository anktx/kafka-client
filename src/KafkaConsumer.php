<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client;

use Anktx\Kafka\Client\Config\ConsumerConfig;
use Anktx\Kafka\Client\Exception\Business\EmptySubscriptionsException;
use Anktx\Kafka\Client\Exception\Kafka\KafkaConnectionException;
use Anktx\Kafka\Client\Exception\Kafka\KafkaConsumerException;
use Anktx\Kafka\Client\Exception\Kafka\KafkaPartitionEofException;
use Anktx\Kafka\Client\Exception\Kafka\KafkaPartitionTimeoutException;
use Anktx\Kafka\Client\Message\KafkaConsumerMessage;
use Anktx\Kafka\Client\Subscription\SubscriptionList;
use RdKafka\TopicPartition;

final class KafkaConsumer
{
    private readonly \RdKafka\KafkaConsumer $consumer;

    public function __construct(
        ConsumerConfig $config,
        int $timeoutMs = 5000,
    ) {
        $this->consumer = new \RdKafka\KafkaConsumer($config->asKafkaConfig());
        $this->assertBrokersAreAlive($timeoutMs);
    }

    public function subscribe(SubscriptionList $subscriptionList): void
    {
        if ($subscriptionList->isEmpty()) {
            throw new EmptySubscriptionsException('At least one subscription is required');
        }

        $this->consumer->subscribe($subscriptionList->topicNames());

        $this->consumer->assign($this->commitedOffsets($subscriptionList)->asKafkaTopicPartitionArray());
    }

    public function consume(int $timeoutMs = 1000): KafkaConsumerMessage
    {
        $message = $this->consumer->consume($timeoutMs);

        switch ($message->err) {
            case RD_KAFKA_RESP_ERR_NO_ERROR:
                break;
            case RD_KAFKA_RESP_ERR__PARTITION_EOF:
                throw KafkaPartitionEofException::create($message);
            case RD_KAFKA_RESP_ERR__TIMED_OUT:
                throw KafkaPartitionTimeoutException::create($message);
            default:
                throw KafkaConsumerException::create($message);
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

    public function commit(KafkaConsumerMessage $message): void
    {
        $this->consumer->commit([
            new TopicPartition($message->topic, $message->partition, $message->offset + 1),
        ]);
    }

    private function commitedOffsets(SubscriptionList $subscriptionList, int $timeoutMs = 1000): SubscriptionList
    {
        return SubscriptionList::create(
            ...$this->consumer->getCommittedOffsets(
                topic_partitions: $subscriptionList->asKafkaTopicPartitionArray(),
                timeout_ms: $timeoutMs,
            ));
    }

    private function assertBrokersAreAlive(int $timeoutMs): void
    {
        try {
            $this->consumer->getMetadata(
                all_topics: true,
                only_topic: null,
                timeout_ms: $timeoutMs,
            );
        } catch (\RdKafka\Exception $e) {
            throw ($e->getCode() === RD_KAFKA_RESP_ERR__TRANSPORT) ? new KafkaConnectionException($e->getMessage(), $e->getCode(), $e->getPrevious()) : $e;
        }
    }
}
