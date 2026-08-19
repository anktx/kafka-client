<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client;

use Anktx\Kafka\Client\Config\ProducerConfig;
use Anktx\Kafka\Client\Exception\Kafka\KafkaConnectionException;
use Anktx\Kafka\Client\Exception\Kafka\KafkaProducerException;
use Anktx\Kafka\Client\KafkaMessage\KafkaProducerMessage;
use Anktx\Kafka\Client\Log\LibrdkafkaLogLevel;
use Anktx\Kafka\Client\PollStrategy\NeverPollStrategy;
use Anktx\Kafka\Client\PollStrategy\PollStrategy;
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

    /**
     * Коды ошибок librdkafka, означающие потерю соединения с брокерами.
     *
     * @var list<int>
     */
    private const array CONNECTION_ERROR_CODES = [
        \RD_KAFKA_RESP_ERR__ALL_BROKERS_DOWN,
        \RD_KAFKA_RESP_ERR__TRANSPORT,
        \RD_KAFKA_RESP_ERR__RESOLVE,
    ];
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
     */
    public function __construct(
        ProducerConfig $config,
        private readonly PollStrategy $pollStrategy = new NeverPollStrategy(),
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $conf = $config->asKafkaConfig();

        $conf->setLogCb($this->onLog(...));
        $conf->setErrorCb($this->onBrokerError(...));

        $this->producer = new Producer($conf);

        $this->logger->info('KafkaProducer created', [
            'brokers' => $config->brokers,
            'compression' => $config->compressionType->name,
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
     * Блокирует до тех пор, пока все сообщения не будут отправлены.
     *
     * Метод гарантирует, что все накопленные в очереди сообщения отправлены в Kafka.
     * Рекомендуется вызывать перед завершением работы приложения.
     *
     * @param int $timeoutMs Таймаут ожидания в миллисекундах (по умолчанию 1000 мс)
     *
     * @throws KafkaConnectionException Если истёк таймаут ожидания
     * @throws KafkaProducerException   Если произошла ошибка при отправке
     */
    public function flush(int $timeoutMs = self::DEFAULT_FLUSH_TIMEOUT_MS): void
    {
        $result = $this->producer->flush($timeoutMs);

        if ($result === \RD_KAFKA_RESP_ERR_NO_ERROR) {
            $this->logger->info('Producer flushed successfully', [
                'timeout_ms' => $timeoutMs,
            ]);

            return;
        }

        if ($result === \RD_KAFKA_RESP_ERR__TIMED_OUT) {
            $this->logger->warning('Flush timed out', [
                'timeout_ms' => $timeoutMs,
                'error_code' => $result,
            ]);

            throw KafkaConnectionException::flushTimeout($timeoutMs);
        }

        $this->logger->error('Flush failed', [
            'timeout_ms' => $timeoutMs,
            'error_code' => $result,
            'error' => rd_kafka_err2str($result),
        ]);

        throw KafkaProducerException::flushFailed($result);
    }

    /**
     * Получает или создаёт объект топика для Kafka.
     *
     * Топики кэшируются для повторного использования.
     *
     * @param string $name Имя топика
     *
     * @return ProducerTopic Объект топика RdKafka
     */
    private function topic(string $name): ProducerTopic
    {
        if (!isset($this->topics[$name])) {
            $this->topics[$name] = $this->producer->newTopic($name);
        }

        return $this->topics[$name];
    }

    /**
     * Log-callback librdkafka: перенаправляет внутренние сообщения библиотеки
     * в PSR-3 лог, преобразуя syslog severity в строковый уровень PSR-3.
     *
     * @param Producer $producer Продюсер (не используется)
     * @param int      $level    Уровень логирования (syslog severity 0–7)
     * @param string   $facility Источник сообщения
     * @param string   $message  Текст сообщения
     */
    private function onLog(Producer $producer, int $level, string $facility, string $message): void
    {
        $this->logger->log(LibrdkafkaLogLevel::toPsrLevel($level), $message, ['facility' => $facility]);
    }

    /**
     * Error-callback librdkafka: логирует потерю соединения с брокерами.
     *
     * Выполняется синхронно в C-коде ext-rdkafka, поэтому бросать исключения
     * отсюда нельзя. Переподключением librdkafka занимается сам.
     *
     * @param Producer $producer Продюсер (не используется)
     * @param int      $err      Код ошибки RD_KAFKA_RESP_ERR__*
     * @param string   $reason   Описание ошибки
     */
    private function onBrokerError(Producer $producer, int $err, string $reason): void
    {
        if (!\in_array($err, self::CONNECTION_ERROR_CODES, true)) {
            return;
        }

        $this->logger->warning('Kafka broker connection error', [
            'error_code' => $err,
            'reason' => $reason,
        ]);
    }
}
