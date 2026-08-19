<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client;

use Anktx\Kafka\Client\Config\ConsumerConfig;
use Anktx\Kafka\Client\ConsumeResult\KafkaConsumeTimeout;
use Anktx\Kafka\Client\ConsumeResult\KafkaPartitionEof;
use Anktx\Kafka\Client\Exception\Business\EmptySubscriptionsException;
use Anktx\Kafka\Client\Exception\Kafka\KafkaConsumerException;
use Anktx\Kafka\Client\Exception\Logic\InvalidMessageException;
use Anktx\Kafka\Client\Exception\Logic\NotSubscribedException;
use Anktx\Kafka\Client\KafkaMessage\KafkaConsumerMessage;
use Anktx\Kafka\Client\Log\LibrdkafkaCallbacks;
use Anktx\Kafka\Client\TopicSubscription\TopicSubscriptionList;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RdKafka\Exception;
use RdKafka\Message;
use RdKafka\TopicPartition;

/**
 * Консьюмер для чтения сообщений из Apache Kafka.
 *
 * Работает в составе consumer group: партиции топиков распределяются между
 * экземплярами группы, позволяя масштабировать потребление горизонтально.
 *
 * @see https://github.com/edenhill/librdkafka/blob/master/README.md
 */
final readonly class KafkaConsumer
{
    private const int DEFAULT_CONSUME_TIMEOUT_MS = 1000;
    private \RdKafka\KafkaConsumer $consumer;

    /**
     * Создаёт консьюмера, не подключаясь к брокерам.
     *
     * Все сетевые операции (подключение, rebalance) librdkafka выполняет
     * в фоновых потоках после первого {@see subscribe()} — объект безопасен
     * для ленивого резолва в DI-контейнере.
     *
     * @param ConsumerConfig  $config Конфигурация консьюмера
     * @param LoggerInterface $logger PSR-3 логгер (по умолчанию NullLogger)
     *
     * @throws KafkaConsumerException Если не удалось создать консьюмера
     */
    public function __construct(
        ConsumerConfig $config,
        private LoggerInterface $logger = new NullLogger(),
    ) {
        $conf = $config->asKafkaConfig();

        $callbacks = new LibrdkafkaCallbacks($this->logger);
        $callbacks->attachLogCallback($conf);
        $callbacks->attachErrorCallback($conf);

        $this->consumer = new \RdKafka\KafkaConsumer($conf);

        $this->logger->info('KafkaConsumer created', [
            'brokers' => $config->brokers,
            'group_id' => $config->groupId,
            'instance_id' => $config->instanceId,
            'offset_reset' => $config->offsetReset->value,
            'auto_commit_ms' => $config->autoCommitMs,
            'session_timeout_ms' => $config->sessionTimeoutMs,
        ]);
    }

    /**
     * Подписывается на топики.
     *
     * Операция локальная: подключение к брокерам и запрос метаданных librdkafka
     * выполняет асинхронно в фоновых потоках, поэтому недоступность брокеров
     * здесь не видна — она проявится позже через {@see consume()} (таймаут)
     * и error-callback в логах. Fail-fast проверку доступности при старте
     * выполняйте на уровне приложения.
     *
     * Партиции и смещения назначает сам librdkafka через внутренний
     * rebalance-callback. Внешний assign() после subscribe() переключает
     * консьюмера в ручной режим и затирает выставленные rebalance'ом
     * партиции и смещения.
     *
     * @param TopicSubscriptionList $subscriptionList Список подписок на топики/партиции
     *
     * @throws EmptySubscriptionsException Если список подписок пуст
     * @throws KafkaConsumerException      Если librdkafka не принял подписку
     */
    public function subscribe(TopicSubscriptionList $subscriptionList): void
    {
        if ($subscriptionList->isEmpty()) {
            throw EmptySubscriptionsException::create();
        }

        try {
            $this->consumer->subscribe($subscriptionList->topicNames());
        } catch (Exception $e) {
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
        } catch (Exception $e) {
            $this->logger->error('Failed to unsubscribe', [
                'error' => $e->getMessage(),
            ]);

            throw KafkaConsumerException::fromKafkaException($e);
        }

        $this->logger->info('Unsubscribed from all topics');
    }

    /**
     * Читает одно сообщение, блокируясь до его получения или истечения таймаута.
     *
     * Возможные результаты:
     * - {@see KafkaConsumerMessage} - сообщение;
     * - {@see KafkaConsumeTimeout} - таймаут; сюда же попадает полная потеря
     *   связи с брокерами (ALL_BROKERS_DOWN): переподключение librdkafka
     *   продолжает в фоновых потоках;
     * - {@see KafkaPartitionEof} - достигнут конец партиции.
     *
     * Чтение всегда делегируется librdkafka: через consume() также доставляются
     * rebalance-события группы, поэтому предварительных проверок доступности
     * метод не выполняет. Состояние подписки метод тоже спрашивает у самого
     * librdkafka через getSubscription() — без librdkafka consume() без
     * подписки бесконечно возвращает таймауты, неотличимые от пустого топика.
     *
     * @param int $timeoutMs Таймаут ожидания в миллисекундах
     *
     * @return KafkaConsumerMessage|KafkaConsumeTimeout|KafkaPartitionEof Результат чтения
     *
     * @throws NotSubscribedException Если консьюмер не подписан на топики
     * @throws KafkaConsumerException Если чтение завершилось ошибкой
     */
    public function consume(int $timeoutMs = self::DEFAULT_CONSUME_TIMEOUT_MS): KafkaConsumerMessage|KafkaConsumeTimeout|KafkaPartitionEof
    {
        if ($this->consumer->getSubscription() === []) {
            $this->logger->warning('Attempted to consume without subscription');

            throw NotSubscribedException::create();
        }

        try {
            $message = $this->consumer->consume($timeoutMs);
        } catch (Exception $e) {
            $this->logger->error('Failed to consume message', [
                'timeout_ms' => $timeoutMs,
                'error' => $e->getMessage(),
            ]);

            throw KafkaConsumerException::fromKafkaException($e);
        }

        return match ($message->err) {
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

            \RD_KAFKA_RESP_ERR__TIMED_OUT, \RD_KAFKA_RESP_ERR__ALL_BROKERS_DOWN => new KafkaConsumeTimeout(
                partition: $message->partition,
                offset: $message->offset,
            ),

            default => $this->throwOnUnrecognizedConsumeError($message),
        };
    }

    /**
     * Читает сообщение и передаёт его в callback, соответствующий типу результата.
     *
     * @param \Closure(KafkaConsumerMessage): mixed $onMessage Обработчик сообщения
     * @param \Closure(KafkaConsumeTimeout): mixed  $onTimeout Обработчик таймаута
     * @param \Closure(KafkaPartitionEof): mixed    $onEof     Обработчик конца партиции
     * @param int                                   $timeoutMs Таймаут ожидания в миллисекундах
     *
     * @return mixed Значение, возвращённое сработавшим callback'ом
     *
     * @throws NotSubscribedException Если консьюмер не подписан на топики
     * @throws KafkaConsumerException Если чтение завершилось ошибкой
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
     * Коммитит смещение обработанного сообщения.
     *
     * Фиксирует в Kafka offset, следующий за сообщением, — после этого группа
     * не получит его повторно.
     *
     * @param KafkaConsumerMessage $message Обработанное сообщение
     *
     * @throws InvalidMessageException Если у сообщения нет смещения
     * @throws KafkaConsumerException  Если коммит не удался
     */
    public function commit(KafkaConsumerMessage $message): void
    {
        if ($message->offset === null) {
            $this->logger->error('Attempted to commit a message without offset', [
                'topic' => $message->topic,
                'partition' => $message->partition,
            ]);

            throw InvalidMessageException::noOffset($message);
        }

        try {
            $this->consumer->commit([
                new TopicPartition($message->topic, $message->partition, $message->offset + 1),
            ]);
        } catch (Exception $e) {
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
     * Логирует и бросает исключение для кода ошибки, не имеющего типизированной ветки в consume().
     *
     * @param Message $message Сообщение с кодом ошибки RD_KAFKA_RESP_ERR__*
     */
    private function throwOnUnrecognizedConsumeError(Message $message): never
    {
        $this->logger->error('Consume failed with unrecognized error', [
            'error_code' => $message->err,
            'error' => $message->errstr(),
        ]);

        throw KafkaConsumerException::create($message->errstr(), $message->err);
    }
}
