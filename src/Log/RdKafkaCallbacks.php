<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Log;

use Anktx\Kafka\Client\PollStrategy\TimeoutPollStrategy;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RdKafka\Conf;
use RdKafka\KafkaConsumer;
use RdKafka\Message;
use RdKafka\Producer;

/**
 * Callback'и librdkafka для RdKafka\Conf и единая политика их логирования
 * в PSR-3. Общая точка переиспользования для продюсера и консьюмера.
 *
 * Общие для обоих клиентов log- и error-callback'и навешиваются через
 * {@see attachLogCallback()} и {@see attachErrorCallback()}; producer-only
 * delivery-report — через {@see attachDeliveryReportCallback()}.
 *
 * Все callback'и выполняются синхронно в C-коде ext-rdkafka, поэтому
 * бросать исключения из них нельзя.
 */
final readonly class RdKafkaCallbacks
{
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

    /**
     * @param LoggerInterface $logger PSR-3 логгер (по умолчанию NullLogger)
     */
    public function __construct(
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * Навешивает на конфигурацию log-callback librdkafka.
     */
    public function attachLogCallback(Conf $conf): void
    {
        $conf->setLogCb($this->onLog(...));
    }

    /**
     * Навешивает на конфигурацию error-callback librdkafka.
     */
    public function attachErrorCallback(Conf $conf): void
    {
        $conf->setErrorCb($this->onBrokerError(...));
    }

    /**
     * Навешивает на конфигурацию delivery-report callback librdkafka.
     */
    public function attachDeliveryReportCallback(Conf $conf): void
    {
        $conf->setDrMsgCb($this->onDeliveryReport(...));
    }

    /**
     * Log-callback librdkafka: перенаправляет внутренние сообщения библиотеки
     * в PSR-3 лог, преобразуя syslog severity в строковый уровень PSR-3.
     *
     * @param KafkaConsumer|Producer $client   Клиент, вызвавший callback (не используется)
     * @param int                    $level    Уровень логирования (syslog severity 0–7)
     * @param string                 $facility Источник сообщения
     * @param string                 $message  Текст сообщения
     */
    private function onLog(KafkaConsumer|Producer $client, int $level, string $facility, string $message): void
    {
        $this->logger->log(RdKafkaLogLevel::toPsrLevel($level), $message, ['facility' => $facility]);
    }

    /**
     * Error-callback librdkafka: логирует потерю соединения с брокерами.
     *
     * Выполняется синхронно в C-коде ext-rdkafka, поэтому бросать исключения
     * отсюда нельзя. Переподключением librdkafka занимается сам.
     *
     * @param KafkaConsumer|Producer $client Клиент, вызвавший callback (не используется)
     * @param int                    $err    Код ошибки RD_KAFKA_RESP_ERR__*
     * @param string                 $reason Описание ошибки
     */
    private function onBrokerError(KafkaConsumer|Producer $client, int $err, string $reason): void
    {
        if (!\in_array($err, self::CONNECTION_ERROR_CODES, true)) {
            return;
        }

        $this->logger->warning('Kafka broker connection error', [
            'error_code' => $err,
            'reason' => $reason,
        ]);
    }

    /**
     * Delivery-report callback librdkafka: сообщает итог доставки каждого
     * отправленного сообщения.
     *
     * Выполняется синхронно в C-коде ext-rdkafka при poll()/flush(), поэтому
     * бросать исключения отсюда нельзя — ошибка доставки только логируется.
     * Отчёты доезжают до этого callback'а только когда кто-то вызывает poll():
     * PollStrategy с опросом ({@see TimeoutPollStrategy})
     * доставляет отчёты в фоне, NeverPollStrategy — только в момент flush().
     *
     * @param Producer $client  Продюсер, вызвавший callback (не используется)
     * @param Message  $message Отчёт о доставке сообщения
     */
    private function onDeliveryReport(Producer $client, Message $message): void
    {
        if ($message->err === \RD_KAFKA_RESP_ERR_NO_ERROR) {
            $this->logger->debug('Message delivered', [
                'topic' => $message->topic_name,
                'partition' => $message->partition,
                'offset' => $message->offset,
            ]);

            return;
        }

        $this->logger->error('Message delivery failed', [
            'topic' => $message->topic_name,
            'partition' => $message->partition,
            'error_code' => $message->err,
            'error' => $message->errstr(),
        ]);
    }
}
