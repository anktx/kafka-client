<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client;

use Anktx\Kafka\Client\Config\ProducerConfig;
use Anktx\Kafka\Client\Exception\Kafka\KafkaFlushTimeoutException;
use Anktx\Kafka\Client\Exception\Kafka\KafkaProducerException;
use Anktx\Kafka\Client\Exception\Logic\InvalidConfigException;
use Anktx\Kafka\Client\KafkaMessage\KafkaProducerMessage;
use Anktx\Kafka\Client\Log\RdKafkaCallbacks;
use Anktx\Kafka\Client\PollStrategy\NeverPollStrategy;
use Anktx\Kafka\Client\PollStrategy\PollStrategy;
use Anktx\Kafka\Client\Topic\Topic;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RdKafka\Exception;
use RdKafka\Producer;
use RdKafka\ProducerTopic;

/**
 * Kafka Producer для отправки сообщений в Kafka.
 *
 * @see https://github.com/edenhill/librdkafka/blob/master/README.md
 */
final class KafkaProducer
{
    private const int DEFAULT_FLUSH_TIMEOUT_MS = 1000;

    /** Бюджет poll()-вызовов на один drain очереди delivery-report'ов. */
    private const int MAX_DRAIN_POLLS = 100;
    private readonly Producer $producer;

    /**
     * @var ProducerTopic[]
     */
    private array $topics = [];

    /**
     * Создаёт новый экземпляр Kafka Producer.
     *
     * @param ProducerConfig  $config       Конфигурация продюсера
     * @param PollStrategy    $pollStrategy Стратегия опроса очереди (по умолчанию NeverPollStrategy)
     * @param LoggerInterface $logger       PSR-3 логгер (по умолчанию NullLogger)
     *
     * @throws InvalidConfigException Если конфигурация отклонена librdkafka
     * @throws KafkaProducerException Если не удалось создать клиента RdKafka
     */
    public function __construct(
        ProducerConfig $config,
        private readonly PollStrategy $pollStrategy = new NeverPollStrategy(),
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $conf = $config->asKafkaConfig();

        $callbacks = new RdKafkaCallbacks($this->logger);
        $callbacks->attachLogCallback($conf);
        $callbacks->attachErrorCallback($conf);
        $callbacks->attachDeliveryReportCallback($conf);

        try {
            $this->producer = new Producer($conf);
        } catch (Exception $e) { // @codeCoverageIgnoreStart
            // Через публичный API недостижимо: все значения Conf провалидированы
            // в ProducerConfig::asKafkaConfig() (отклонение set() уходит как
            // InvalidConfigException ещё до try); отказ rd_kafka_new()
            // возможен только на уровне процесса (OOM/EMFILE).
            $this->logger->error('Failed to create RdKafka producer', [
                'brokers' => $config->brokers->value,
                'reason' => $e->getMessage(),
                'exception' => $e,
            ]);

            throw KafkaProducerException::fromKafkaException($e);
            // @codeCoverageIgnoreEnd
        }

        $this->logger->info('KafkaProducer created', [
            'brokers' => $config->brokers->value,
            'compression' => $config->compressionType->value,
            'poll_strategy' => $pollStrategy::class,
        ]);
    }

    /**
     * Отправляет сообщение в Kafka.
     *
     * Сообщение помещается в локальную очередь и отправляется асинхронно.
     * Для гарантированной отправки вызовите метод {@see flush()}.
     *
     * @param KafkaProducerMessage $message Сообщение для отправки
     *
     * @throws KafkaProducerException Если не удалось отправить сообщение
     */
    public function produce(KafkaProducerMessage $message): void
    {
        if ($this->pollStrategy->shouldPoll()) {
            $this->drainDeliveryReports();
            $this->pollStrategy->markPolled();
        }

        try {
            $topic = $this->topic($message->topic);

            $topic->producev(
                partition: $message->partition,
                msgflags: 0,
                payload: $message->body,
                key: $message->key,
                headers: $message->headers,
                timestamp_ms: $message->timestampMs,
            );
        } catch (Exception $e) {
            // getCode() у RdKafka\Exception — не RD_KAFKA_RESP_ERR_*-код,
            // поэтому error_code здесь не логируется: исключение целиком
            // передаётся в PSR-3 context['exception'].
            $this->logger->error('Failed to produce message', [
                'topic' => $message->topic->name,
                'partition' => $message->partition,
                'key' => $message->key,
                'reason' => $e->getMessage(),
                'exception' => $e,
            ]);

            throw KafkaProducerException::produceFailed(
                topic: $message->topic->name,
                partition: $message->partition,
                e: $e,
            );
        }
    }

    /**
     * Блокирует до тех пор, пока все сообщения не будут отправлены.
     *
     * Метод гарантирует, что все накопленные в очереди сообщения отправлены в Kafka.
     * Рекомендуется вызывать перед завершением работы приложения.
     *
     * Отдельный вызов RdKafka\Producer::flush() может вернуть таймаут
     * транзитно (например, пока устанавливается соединение), поэтому до
     * истечения суммарного дедлайна $timeoutMs вызов повторяется с остатком
     * бюджета; исключение бросается только после исчерпания дедлайна.
     *
     * @param int $timeoutMs Суммарный таймаут ожидания в миллисекундах (по умолчанию 1000 мс)
     *
     * @throws InvalidConfigException     Если таймаут отрицательный
     * @throws KafkaFlushTimeoutException Если истёк суммарный таймаут ожидания
     * @throws KafkaProducerException     Если произошла ошибка при отправке
     */
    public function flush(int $timeoutMs = self::DEFAULT_FLUSH_TIMEOUT_MS): void
    {
        if ($timeoutMs < 0) {
            throw InvalidConfigException::nonNegativeInt('timeoutMs', $timeoutMs);
        }

        $startedAtNs = hrtime(true);
        $attempts = 0;

        do {
            $remainingMs = max(0, $timeoutMs - self::elapsedMs($startedAtNs));
            ++$attempts;

            $result = $this->producer->flush($remainingMs);

            if ($result === \RD_KAFKA_RESP_ERR_NO_ERROR) {
                $this->logger->debug('Producer flushed successfully', [
                    'timeout_ms' => $timeoutMs,
                    'attempts' => $attempts,
                ]);

                return;
            }

            if ($result !== \RD_KAFKA_RESP_ERR__TIMED_OUT) {
                $this->logger->error('Flush failed', [
                    'timeout_ms' => $timeoutMs,
                    'attempts' => $attempts,
                    'error_code' => $result,
                    'reason' => rd_kafka_err2str($result),
                    'out_queue_len' => $this->producer->getOutQLen(),
                ]);

                throw KafkaProducerException::flushFailed($result);
            }
        } while (self::elapsedMs($startedAtNs) < $timeoutMs);

        $this->logger->warning('Flush timed out', [
            'timeout_ms' => $timeoutMs,
            'attempts' => $attempts,
            'error_code' => $result,
            'out_queue_len' => $this->producer->getOutQLen(),
        ]);

        throw KafkaFlushTimeoutException::flushTimeout(
            timeoutMs: $timeoutMs,
            outQueueLen: $this->producer->getOutQLen(),
        );
    }

    /**
     * Опустошает очередь delivery-report'ов с ограниченным бюджетом poll()-вызовов.
     *
     * poll(0) неблокирующий, поэтому без бюджета цикл крутился бы вхолостую
     * на 100% CPU, пока очередь не дренируется сама: при недоступных брокерах
     * отчёты не приходят вплоть до message.timeout.ms (5 минут по умолчанию).
     * Недренжированный остаток логируется warning'ом — сообщения ещё в очереди.
     */
    private function drainDeliveryReports(): void
    {
        $polls = 0;

        while ($this->producer->getOutQLen() > 0 && $polls < self::MAX_DRAIN_POLLS) {
            $this->producer->poll(0);
            ++$polls;
        }

        $remaining = $this->producer->getOutQLen();

        if ($remaining > 0) {
            $this->logger->warning('Delivery report queue not fully drained', [
                'max_polls' => self::MAX_DRAIN_POLLS,
                'remaining_messages' => $remaining,
            ]);
        }
    }

    /**
     * Получает или создаёт объект топика для Kafka.
     *
     * Топики кэшируются для повторного использования.
     *
     * @param Topic $topic Имя топика
     *
     * @return ProducerTopic Объект топика RdKafka
     */
    private function topic(Topic $topic): ProducerTopic
    {
        if (!isset($this->topics[$topic->name])) {
            $this->topics[$topic->name] = $this->producer->newTopic($topic->name);
        }

        return $this->topics[$topic->name];
    }

    /**
     * Вычисляет прошедшее время в миллисекундах от отметки hrtime().
     */
    private static function elapsedMs(int $startedAtNs): int
    {
        return intdiv(hrtime(true) - $startedAtNs, 1_000_000);
    }
}
