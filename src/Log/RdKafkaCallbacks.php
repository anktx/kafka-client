<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Log;

use Anktx\Kafka\Client\PollStrategy\TimeoutPollStrategy;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
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
     * Error-callback librdkafka: логирует все ошибки клиента.
     *
     * Потеря соединения с брокерами — warning с конкретизацией по коду
     * (переподключением librdkafka занимается сам): все брокеры
     * недоступны, обрыв соединения, имя брокера не резолвится;
     * фатальные ошибки — error (клиент после них неработоспособен),
     * прочие (аутентификация, SASL и т.п.) — warning: раньше они глотались
     * молча и, например, неверные креды были видны только в debug-логе.
     *
     * Выполняется синхронно в C-коде ext-rdkafka, поэтому бросать исключения
     * отсюда нельзя.
     *
     * @param KafkaConsumer|Producer $client Клиент, вызвавший callback (не используется)
     * @param int                    $err    Код ошибки RD_KAFKA_RESP_ERR__*
     * @param string                 $reason Описание ошибки
     */
    private function onBrokerError(KafkaConsumer|Producer $client, int $err, string $reason): void
    {
        $context = [
            'error_code' => $err,
            'reason' => $reason,
        ];

        [$level, $logMessage] = match ($err) {
            \RD_KAFKA_RESP_ERR__ALL_BROKERS_DOWN => [LogLevel::WARNING, 'All Kafka brokers down'],
            \RD_KAFKA_RESP_ERR__TRANSPORT => [LogLevel::WARNING, 'Kafka broker connection error'],
            \RD_KAFKA_RESP_ERR__RESOLVE => [LogLevel::WARNING, 'Kafka broker hostname resolution failed'],
            \RD_KAFKA_RESP_ERR__FATAL => [LogLevel::ERROR, 'Kafka fatal error, client is unusable'],
            default => [LogLevel::WARNING, 'Kafka client error'],
        };

        $this->logger->log($level, $logMessage, $context);
    }

    /**
     * Delivery-report callback librdkafka: сообщает итог доставки каждого
     * отправленного сообщения.
     *
     * Классификация уровней: успешная доставка — debug; превышение
     * message.timeout.ms — warning (ожидаемое следствие недоступности
     * брокеров, за один обрыв приходят сотни отчётов — error-уровень
     * утопит алерты); прерывание доставки при уничтожении клиента — info
     * (штатное завершение работы); остальные коды — error.
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

        $context = [
            'topic' => $message->topic_name,
            'partition' => $message->partition,
            'error_code' => $message->err,
            'reason' => $message->errstr(),
        ];

        [$level, $logMessage] = match ($message->err) {
            \RD_KAFKA_RESP_ERR__MSG_TIMED_OUT => [LogLevel::WARNING, 'Message delivery timed out'],
            \RD_KAFKA_RESP_ERR__DESTROY => [LogLevel::INFO, 'Message delivery aborted by producer shutdown'],
            default => [LogLevel::ERROR, 'Message delivery failed'],
        };

        $this->logger->log($level, $logMessage, $context);
    }
}
