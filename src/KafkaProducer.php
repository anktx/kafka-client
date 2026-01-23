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

/**
 * Kafka Producer для отправки сообщений в Kafka.
 *
 * @see https://github.com/edenhill/librdkafka/blob/master/README.md
 */
final class KafkaProducer
{
    private readonly Producer $producer;
    private readonly LoggerInterface $logger;

    /**
     * @var ProducerTopic[]
     */
    private array $topics = [];

    /**
     * Создаёт новый экземпляр Kafka Producer.
     *
     * @param ProducerConfig $config       Конфигурация продюсера
     * @param PollStrategy   $pollStrategy Стратегия опроса очереди (по умолчанию NeverPoolStrategy)
     */
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
        if (! isset($this->topics[$name])) {
            $this->topics[$name] = $this->producer->newTopic($name);
        }

        return $this->topics[$name];
    }
}
