<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client;

use Anktx\Kafka\Client\Config\ConsumerConfig;
use Anktx\Kafka\Client\ConsumeResult\KafkaConsumeTimeout;
use Anktx\Kafka\Client\ConsumeResult\KafkaPartitionEof;
use Anktx\Kafka\Client\Exception\Business\EmptySubscriptionsException;
use Anktx\Kafka\Client\Exception\Kafka\KafkaConnectionException;
use Anktx\Kafka\Client\Exception\Kafka\KafkaConsumerException;
use Anktx\Kafka\Client\Exception\Logic\NotSubscribedException;
use Anktx\Kafka\Client\KafkaMessage\KafkaConsumerMessage;
use Anktx\Kafka\Client\TopicSubscription\TopicSubscriptionList;
use Psr\Log\LoggerInterface;
use RdKafka\Exception as RdKafkaException;
use RdKafka\TopicPartition;

final class KafkaConsumer
{
    private readonly \RdKafka\KafkaConsumer $consumer;
    private readonly LoggerInterface $logger;
    private bool $isSubscribed = false;

    /**
     * @throws KafkaConnectionException
     * @throws KafkaConsumerException
     */
    public function __construct(
        ConsumerConfig $config,
        int $timeoutMs = 5000,
    ) {
        $this->logger = $config->logger;
        $this->consumer = new \RdKafka\KafkaConsumer($config->asKafkaConfig());

        $this->logger->info('KafkaConsumer created', [
            'brokers' => $config->brokers,
            'group_id' => $config->groupId,
            'instance_id' => $config->instanceId,
            'offset_reset' => $config->offsetReset->name,
            'auto_commit_ms' => $config->autoCommitMs,
            'session_timeout_ms' => $config->sessionTimeoutMs,
        ]);

        $this->assertBrokersAreAlive($timeoutMs);
    }

    /**
     * @throws EmptySubscriptionsException
     * @throws KafkaConsumerException
     */
    public function subscribe(TopicSubscriptionList $subscriptionList): void
    {
        if ($subscriptionList->isEmpty()) {
            throw new EmptySubscriptionsException('At least one subscription is required');
        }

        try {
            $this->consumer->subscribe($subscriptionList->topicNames());
        } catch (RdKafkaException $e) {
            $this->logger->error('Failed to subscribe to topics', [
                'topics' => $subscriptionList->topicNames(),
                'subscriptions' => array_map(static fn($s) => [
                    'topic' => $s->topic,
                    'partition' => $s->partition,
                ], $subscriptionList->items),
                'error' => $e->getMessage(),
            ]);

            throw KafkaConsumerException::fromKafkaException($e);
        }

        try {
            $this->consumer->assign($this->commitedOffsets($subscriptionList)->asKafkaTopicPartitionArray());
        } catch (RdKafkaException $e) {
            $this->logger->error('Failed to assign offsets', [
                'topics' => $subscriptionList->topicNames(),
                'error' => $e->getMessage(),
            ]);

            throw KafkaConsumerException::fromKafkaException($e);
        }

        $this->isSubscribed = true;

        $this->logger->info('Subscribed to topics', [
            'topics' => $subscriptionList->topicNames(),
            'subscriptions_count' => \count($subscriptionList->items),
        ]);
    }

    /**
     * @throws KafkaConsumerException
     */
    public function unsubscribe(): void
    {
        try {
            $this->consumer->unsubscribe();
        } catch (RdKafkaException $e) {
            $this->logger->error('Failed to unsubscribe', [
                'error' => $e->getMessage(),
            ]);

            throw KafkaConsumerException::fromKafkaException($e);
        }

        $this->isSubscribed = false;

        $this->logger->info('Unsubscribed from all topics');
    }

    /**
     * @throws NotSubscribedException
     * @throws KafkaConsumerException
     */
    public function consume(int $timeoutMs = 1000): KafkaConsumerMessage|KafkaConsumeTimeout|KafkaPartitionEof
    {
        if (!$this->isSubscribed) {
            $this->logger->warning('Attempted to consume without subscription');

            throw new NotSubscribedException();
        }

        try {
            $message = $this->consumer->consume($timeoutMs);
        } catch (RdKafkaException $e) {
            $this->logger->error('Failed to consume message', [
                'timeout_ms' => $timeoutMs,
                'error' => $e->getMessage(),
            ]);

            throw KafkaConsumerException::fromKafkaException($e);
        }

        $result = match ($message->err) {
            \RD_KAFKA_RESP_ERR_NO_ERROR => new KafkaConsumerMessage(
                topic: $message->topic_name,
                body: $message->payload,
                partition: $message->partition,
                offset: $message->offset,
                key: $message->key,
                headers: $message->headers,
                timestampMs: $message->timestamp,
            ),

            \RD_KAFKA_RESP_ERR__PARTITION_EOF => new KafkaPartitionEof(
                topic: $message->topic_name,
                partition: $message->partition,
                offset: $message->offset,
            ),

            \RD_KAFKA_RESP_ERR__TIMED_OUT => new KafkaConsumeTimeout(
                topic: $message->topic_name,
                partition: $message->partition,
                offset: $message->offset,
            ),

            default => throw new KafkaConsumerException($message->errstr()),
        };

        return $result;
    }

    /**
     * @param \Closure(KafkaConsumerMessage): mixed $onMessage
     * @param \Closure(KafkaConsumeTimeout): mixed  $onTimeout
     * @param \Closure(KafkaPartitionEof): mixed    $onEof
     *
     * @return mixed Возвращает значение из выполненного callback'а
     *
     * @throws NotSubscribedException
     * @throws KafkaConsumerException
     */
    public function consumeMatch(
        \Closure $onMessage,
        \Closure $onTimeout,
        \Closure $onEof,
        int $timeoutMs = 1000,
    ): mixed {
        $result = $this->consume($timeoutMs);

        return match ($result::class) {
            KafkaConsumerMessage::class => $onMessage($result),
            KafkaConsumeTimeout::class => $onTimeout($result),
            KafkaPartitionEof::class => $onEof($result),
        };
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
            $this->logger->error('Failed to commit message', [
                'topic' => $message->topic,
                'partition' => $message->partition,
                'offset' => $message->offset,
                'error' => $e->getMessage(),
            ]);

            throw KafkaConsumerException::fromKafkaException($e);
        }
    }

    public function close(): void
    {
        $this->logger->info('Closing KafkaConsumer');

        $this->consumer->close();

        $this->logger->info('KafkaConsumer closed');
    }

    private function commitedOffsets(TopicSubscriptionList $subscriptionList, int $timeoutMs = 1000): TopicSubscriptionList
    {
        return TopicSubscriptionList::fromKafkaTopicPartition(
            ...$this->consumer->getCommittedOffsets(
                topic_partitions: $subscriptionList->asKafkaTopicPartitionArray(),
                timeout_ms: $timeoutMs,
            ),
        );
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
