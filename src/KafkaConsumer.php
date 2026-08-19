<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client;

use Anktx\Kafka\Client\Config\ConsumerConfig;
use Anktx\Kafka\Client\ConsumeResult\KafkaBrokersDown;
use Anktx\Kafka\Client\ConsumeResult\KafkaConsumeTimeout;
use Anktx\Kafka\Client\ConsumeResult\KafkaPartitionEof;
use Anktx\Kafka\Client\Exception\Kafka\KafkaConsumerException;
use Anktx\Kafka\Client\Exception\Logic\ClientClosedException;
use Anktx\Kafka\Client\Exception\Logic\EmptySubscriptionsException;
use Anktx\Kafka\Client\Exception\Logic\InvalidConfigException;
use Anktx\Kafka\Client\Exception\Logic\NotSubscribedException;
use Anktx\Kafka\Client\KafkaMessage\KafkaConsumerMessage;
use Anktx\Kafka\Client\Log\RdKafkaCallbacks;
use Anktx\Kafka\Client\StreamObserver\BrokersDownBudgetStreamObserver;
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
final class KafkaConsumer
{
    private const int DEFAULT_CONSUME_TIMEOUT_MS = 1000;
    private \RdKafka\KafkaConsumer $consumer;
    private bool $closed = false;

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
     * @throws InvalidConfigException Если конфигурация отклонена librdkafka
     * @throws KafkaConsumerException Если не удалось создать клиента RdKafka
     */
    public function __construct(
        ConsumerConfig $config,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $conf = $config->asKafkaConfig();

        $callbacks = new RdKafkaCallbacks($this->logger);
        $callbacks->attachLogCallback($conf);
        $callbacks->attachErrorCallback($conf);

        try {
            $this->consumer = new \RdKafka\KafkaConsumer($conf);
        } catch (Exception $e) { // @codeCoverageIgnoreStart
            // Через публичный API недостижимо: все значения Conf провалидированы
            // в ConsumerConfig::asKafkaConfig() (отклонение set() уходит как
            // InvalidConfigException ещё до try), group.id гарантированно непуст;
            // отказ rd_kafka_new() возможен только на уровне процесса (OOM/EMFILE).
            $this->logger->error('Failed to create RdKafka consumer', [
                'brokers' => $config->brokers,
                'group_id' => $config->groupId,
                'reason' => $e->getMessage(),
                'exception' => $e,
            ]);

            throw KafkaConsumerException::fromKafkaException($e);
            // @codeCoverageIgnoreEnd
        }

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
     * rebalance-callback, поэтому подписка задаётся только именем топика.
     * Внешний assign() после subscribe() переключает консьюмера в ручной
     * режим и затирает выставленные rebalance'ом партиции и смещения.
     *
     * Повторный вызов не объединяется со старым и не отвергается — он
     * заменяет подписку: librdkafka принимает переданный список как полный
     * набор топиков (старые отписываются, в фоне запускается rebalance).
     * Для изменения набора топиков передавайте новый полный список.
     *
     * @param TopicSubscriptionList $subscriptionList Список подписок на топики
     *
     * @throws ClientClosedException       Если консьюмер закрыт через close()
     * @throws EmptySubscriptionsException Если список подписок пуст
     * @throws KafkaConsumerException      Если librdkafka не принял подписку
     */
    public function subscribe(TopicSubscriptionList $subscriptionList): void
    {
        $this->assertNotClosed(__METHOD__);

        if ($subscriptionList->isEmpty()) {
            throw EmptySubscriptionsException::create();
        }

        try {
            $this->consumer->subscribe($subscriptionList->topicNames());
        } catch (Exception $e) {
            $this->logger->error('Failed to subscribe to topics', [
                'topics' => $subscriptionList->topicNames(),
                'reason' => $e->getMessage(),
                'exception' => $e,
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
     * @throws ClientClosedException  Если консьюмер закрыт через close()
     * @throws KafkaConsumerException Если не удалось отписаться
     */
    public function unsubscribe(): void
    {
        $this->assertNotClosed(__METHOD__);

        try {
            $this->consumer->unsubscribe();
        } catch (Exception $e) {
            $this->logger->error('Failed to unsubscribe', [
                'reason' => $e->getMessage(),
                'exception' => $e,
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
     * - {@see KafkaConsumeTimeout} - таймаут (за окно опроса не пришло
     *   сообщений);
     * - {@see KafkaBrokersDown} - полная потеря соединения со всеми
     *   брокерами (ALL_BROKERS_DOWN): не ошибка, переподключение librdkafka
     *   продолжает в фоновых потоках; «вечная» ли потеря — изнутри клиента
     *   неопределимо, порог ожидания определяет вызывающий код (см.
     *   {@see BrokersDownBudgetStreamObserver});
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
     * @return KafkaBrokersDown|KafkaConsumerMessage|KafkaConsumeTimeout|KafkaPartitionEof Результат чтения
     *
     * @throws ClientClosedException  Если консьюмер закрыт через close()
     * @throws NotSubscribedException Если консьюмер не подписан на топики
     * @throws KafkaConsumerException Если чтение завершилось ошибкой
     */
    public function consume(int $timeoutMs = self::DEFAULT_CONSUME_TIMEOUT_MS): KafkaBrokersDown|KafkaConsumerMessage|KafkaConsumeTimeout|KafkaPartitionEof
    {
        $this->assertNotClosed(__METHOD__);

        try {
            $subscription = $this->consumer->getSubscription();
        } catch (Exception $e) {
            $this->logger->error('Failed to get subscription state', [
                'reason' => $e->getMessage(),
                'exception' => $e,
            ]);

            throw KafkaConsumerException::fromKafkaException($e);
        }

        if ($subscription === []) {
            $this->logger->warning('Attempted to consume without subscription');

            throw NotSubscribedException::create();
        }

        try {
            $message = $this->consumer->consume($timeoutMs);
        } catch (Exception $e) {
            $this->logger->error('Failed to consume message', [
                'timeout_ms' => $timeoutMs,
                'reason' => $e->getMessage(),
                'exception' => $e,
            ]);

            throw KafkaConsumerException::fromKafkaException($e);
        }

        return match ($message->err) {
            \RD_KAFKA_RESP_ERR_NO_ERROR => new KafkaConsumerMessage(
                topic: $message->topic_name,
                partition: $message->partition,
                offset: $message->offset,
                body: $message->payload,
                key: $message->key,
                headers: $message->headers,
                // ext-rdkafka не задаёт timestamp при null-payload, а -1 —
                // сентинел «брокер не передал время»: оба случая — null.
                timestampMs: $message->timestamp !== null && $message->timestamp !== -1
                    ? $message->timestamp
                    : null,
            ),

            \RD_KAFKA_RESP_ERR__PARTITION_EOF => new KafkaPartitionEof(
                topic: $message->topic_name,
                partition: $message->partition,
                offset: $message->offset,
            ),

            \RD_KAFKA_RESP_ERR__TIMED_OUT => new KafkaConsumeTimeout(),

            \RD_KAFKA_RESP_ERR__ALL_BROKERS_DOWN => new KafkaBrokersDown(),

            default => $this->throwOnUnrecognizedConsumeError($message),
        };
    }

    /**
     * Коммитит смещение обработанного сообщения.
     *
     * Фиксирует в Kafka offset, следующий за сообщением, — после этого группа
     * не получит его повторно.
     *
     * Коммит синхронный: вызов блокируется до подтверждения брокера,
     * асинхронного варианта API не предоставляет. Любая ошибка оборачивается
     * в KafkaConsumerException (семейство Kafka — инфраструктурные сбои):
     * библиотека сознательно не классифицирует ошибки коммита как retryable
     * и не ретраит автоматически — решение о повторе остаётся за вызывающим
     * кодом.
     *
     * @param KafkaConsumerMessage $message Обработанное сообщение
     *
     * @throws ClientClosedException  Если консьюмер закрыт через close()
     * @throws KafkaConsumerException Если коммит не удался
     */
    public function commit(KafkaConsumerMessage $message): void
    {
        $this->assertNotClosed(__METHOD__);

        try {
            $this->consumer->commit([
                new TopicPartition($message->topic, $message->partition, $message->offset + 1),
            ]);
        } catch (Exception $e) {
            $this->logger->error('Failed to commit message', [
                'topic' => $message->topic,
                'partition' => $message->partition,
                'offset' => $message->offset,
                'reason' => $e->getMessage(),
                'exception' => $e,
            ]);

            throw KafkaConsumerException::fromKafkaException($e);
        }
    }

    /**
     * Закрывает консьюмер и освобождает ресурсы.
     *
     * Идемпотентен: повторные вызовы — no-op. После закрытия все методы
     * (кроме close()) бросают {@see ClientClosedException} до любых
     * обращений к RdKafka.
     *
     * Флаг закрытия выставляется только после успешного нативного close():
     * упавший close() консьюмера не закрывает, и его можно и нужно
     * повторить (например, в finally) — вызовы снова проксируются в RdKafka,
     * пока не завершатся успешно. Состояние нативного клиента после
     * упавшего close() не определено (librdkafka мог освободить часть
     * ресурсов), поэтому повторный close() — единственная детерминированная
     * операция.
     *
     * @throws KafkaConsumerException Если librdkafka не смог закрыть консьюмера
     *                                (консьюмер остаётся открытым — вызов можно повторить)
     */
    public function close(): void
    {
        if ($this->closed) {
            $this->logger->debug('KafkaConsumer already closed');

            return;
        }

        $this->logger->info('Closing KafkaConsumer');

        try {
            $this->consumer->close();
        } catch (Exception $e) {
            $this->logger->error('Failed to close KafkaConsumer', [
                'reason' => $e->getMessage(),
                'exception' => $e,
            ]);

            throw KafkaConsumerException::fromKafkaException($e);
        }

        $this->closed = true;

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
            'reason' => $message->errstr(),
        ]);

        throw KafkaConsumerException::create($message->errstr(), $message->err);
    }

    /**
     * Гарантирует, что консьюмер ещё открыт.
     *
     * @param string $method Полное имя вызывающего метода (__METHOD__)
     *
     * @throws ClientClosedException Если консьюмер уже закрыт
     */
    private function assertNotClosed(string $method): void
    {
        if ($this->closed) {
            $this->logger->warning('Attempted to use a closed KafkaConsumer', [
                'method' => $method,
            ]);

            throw ClientClosedException::forMethod($method);
        }
    }
}
