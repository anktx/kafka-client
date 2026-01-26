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

/**
 * Kafka Consumer для чтения сообщений из Kafka.
 *
 * Потребитель работает в группе (consumer group), что позволяет распределять
 * нагрузку между несколькими экземплярами консьюмера.
 *
 * @see https://github.com/edenhill/librdkafka/blob/master/README.md
 */
final class KafkaConsumer
{
    private readonly \RdKafka\KafkaConsumer $consumer;
    private readonly LoggerInterface $logger;
    private bool $isSubscribed = false;

    /**
     * Создаёт новый экземпляр Kafka Consumer.
     *
     * При создании проверяется доступность брокеров Kafka.
     *
     * @param ConsumerConfig $config    Конфигурация консьюмера
     * @param int            $timeoutMs Таймаут проверки соединения с брокерами (по умолчанию 5000 мс)
     *
     * @throws KafkaConnectionException Если не удалось подключиться к брокерам
     * @throws KafkaConsumerException   Если произошла ошибка при создании консьюмера
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
     * Подписывается на топики для потребления сообщений.
     *
     * Метод автоматически восстанавливает ранее закоммиченные смещения (offsets).
     * При необходимости можно подписаться на конкретные партиции.
     *
     * @param TopicSubscriptionList $subscriptionList Список подписок на топики/партиции
     *
     * @throws EmptySubscriptionsException Если список подписок пуст
     * @throws KafkaConsumerException      Если не удалось подписаться на топики
     */
    public function subscribe(TopicSubscriptionList $subscriptionList): void
    {
        if ($subscriptionList->isEmpty()) {
            throw EmptySubscriptionsException::create();
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
     * Отписывается от всех топиков.
     *
     * @throws KafkaConsumerException Если не удалось отписаться
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
     * Читает одно сообщение из Kafka.
     *
     * Метод блокирует выполнение до получения сообщения или истечения таймаута.
     * В зависимости от результата может вернуть:
     * - {@see KafkaConsumerMessage} - успешно полученное сообщение
     * - {@see KafkaConsumeTimeout} - таймаут (нет новых сообщений)
     * - {@see KafkaPartitionEof} - достигнут конец партиции
     *
     * @param int $timeoutMs Таймаут ожидания в миллисекундах (по умолчанию 1000 мс)
     *
     * @return KafkaConsumerMessage|KafkaConsumeTimeout|KafkaPartitionEof Результат потребления
     *
     * @throws NotSubscribedException Если консьюмер не подписан на топики
     * @throws KafkaConsumerException Если произошла ошибка при чтении
     */
    public function consume(int $timeoutMs = 1000): KafkaConsumerMessage|KafkaConsumeTimeout|KafkaPartitionEof
    {
        if (!$this->isSubscribed) {
            $this->logger->warning('Attempted to consume without subscription');

            throw NotSubscribedException::create();
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
                partition: $message->partition,
                offset: $message->offset,
            ),

            default => throw KafkaConsumerException::create($message->errstr()),
        };

        return $result;
    }

    /**
     * Читает сообщение и обрабатывает результат через pattern matching.
     *
     * Удобный метод для обработки результатов {@see consume()} через callback'и.
     *
     * @param \Closure(KafkaConsumerMessage): mixed $onMessage Callback для обработки сообщения
     * @param \Closure(KafkaConsumeTimeout): mixed  $onTimeout Callback для обработки таймаута
     * @param \Closure(KafkaPartitionEof): mixed    $onEof     Callback для обработки конца партиции
     * @param int                                   $timeoutMs Таймаут ожидания в миллисекундах (по умолчанию 1000 мс)
     *
     * @return mixed Значение, возвращённое выполненным callback'ом
     *
     * @throws NotSubscribedException Если консьюмер не подписан на топики
     * @throws KafkaConsumerException Если произошла ошибка при чтении
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
     * Подтверждает успешную обработку сообщения.
     *
     * Фиксирует смещение (offset) в Kafka, после которого сообщение не будет повторно доставлено.
     * Вызывайте этот метод после успешной обработки сообщения.
     *
     * @param KafkaConsumerMessage $message Сообщение для коммита
     *
     * @throws KafkaConsumerException Если не закоммитить сообщение
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

    /**
     * Закрывает консьюмер и освобождает ресурсы.
     *
     * Рекомендуется вызывать перед завершением работы приложения.
     */
    public function close(): void
    {
        $this->logger->info('Closing KafkaConsumer');

        $this->consumer->close();

        $this->logger->info('KafkaConsumer closed');
    }

    /**
     * Получает закоммиченные смещения для списка подписок.
     *
     * @param TopicSubscriptionList $subscriptionList Список подписок
     * @param int                   $timeoutMs        Таймаут ожидания (по умолчанию 1000 мс)
     *
     * @return TopicSubscriptionList Список подписок с сохранёнными смещениями
     */
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
     * Проверяет доступность брокеров Kafka.
     *
     * @param int $timeoutMs Таймаут ожидания (по умолчанию из конструктора)
     *
     * @throws KafkaConnectionException Если не удалось подключиться к брокерам
     * @throws KafkaConsumerException   Если произошла ошибка при получении метаданных
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
