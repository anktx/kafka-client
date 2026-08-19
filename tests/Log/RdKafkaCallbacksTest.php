<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Tests\Log;

use Anktx\Kafka\Client\Log\RdKafkaCallbacks;
use Anktx\Kafka\Client\Tests\Support\InMemoryLogger;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use RdKafka\Conf;
use RdKafka\KafkaConsumer;
use RdKafka\Message;
use RdKafka\Producer;

/**
 * Юнит-тесты callback'ов {@see RdKafkaCallbacks}.
 *
 * Wiring attach*-методов проверяется через mock RdKafka\Conf: колбэк
 * захватывается из set*Cb() и вызывается напрямую — живой брокер не нужен.
 * Фиксируют контракт доставки: успех логируется как debug, сбой — как
 * error с кодом ошибки; потеря соединения с брокерами — как warning.
 */
final class RdKafkaCallbacksTest extends TestCase
{
    #[AllowMockObjectsWithoutExpectations]
    public function testAttachLogCallbackForwardsLibrdkafkaLogToLogger(): void
    {
        $logger = new InMemoryLogger();
        $onLog = $this->captureCallback(
            'setLogCb',
            $logger,
            static fn(RdKafkaCallbacks $callbacks, Conf $conf) => $callbacks->attachLogCallback($conf),
        );

        $onLog($this->createMock(KafkaConsumer::class), 3, 'PRODUCE', 'message queued');

        self::assertCount(1, $logger->records);
        self::assertSame(LogLevel::ERROR, $logger->records[0]['level']);
        self::assertSame('message queued', $logger->records[0]['message']);
        self::assertSame(['facility' => 'PRODUCE'], $logger->records[0]['context']);
    }

    #[DataProvider('provideAttachErrorCallbackLogsConnectionErrorsCases')]
    #[AllowMockObjectsWithoutExpectations]
    public function testAttachErrorCallbackLogsConnectionErrors(int $err): void
    {
        $logger = new InMemoryLogger();
        $onBrokerError = $this->captureCallback(
            'setErrorCb',
            $logger,
            static fn(RdKafkaCallbacks $callbacks, Conf $conf) => $callbacks->attachErrorCallback($conf),
        );

        $onBrokerError($this->createMock(KafkaConsumer::class), $err, 'connection refused');

        $records = $logger->findByMessage('Kafka broker connection error');
        self::assertCount(1, $records);
        self::assertSame(LogLevel::WARNING, $records[0]['level']);
        self::assertSame($err, $records[0]['context']['error_code']);
        self::assertSame('connection refused', $records[0]['context']['reason']);
        // Ровно одна запись: удаление return из ветки уронило бы код в общий
        // warning 'Kafka client error' — задвоение записей ловится здесь.
        self::assertCount(1, $logger->records);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function provideAttachErrorCallbackLogsConnectionErrorsCases(): iterable
    {
        // Все коды из RdKafkaCallbacks::CONNECTION_ERROR_CODES: потеря
        // соединения проявляется любым из них, ветка — одна на все три.
        yield 'all brokers down' => [\RD_KAFKA_RESP_ERR__ALL_BROKERS_DOWN];

        yield 'transport' => [\RD_KAFKA_RESP_ERR__TRANSPORT];

        yield 'resolve' => [\RD_KAFKA_RESP_ERR__RESOLVE];
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testAttachErrorCallbackLogsNonConnectionErrorsAsWarning(): void
    {
        // Раньше не-connection ошибки (аутентификация, SASL и т.п.) глотались
        // молча — например, неверные креды были видны только в debug-логе.
        $logger = new InMemoryLogger();
        $onBrokerError = $this->captureCallback(
            'setErrorCb',
            $logger,
            static fn(RdKafkaCallbacks $callbacks, Conf $conf) => $callbacks->attachErrorCallback($conf),
        );

        $onBrokerError($this->createMock(KafkaConsumer::class), \RD_KAFKA_RESP_ERR__BAD_MSG, 'bad message format');

        $records = $logger->findByMessage('Kafka client error');
        self::assertCount(1, $records);
        self::assertSame(LogLevel::WARNING, $records[0]['level']);
        self::assertSame(\RD_KAFKA_RESP_ERR__BAD_MSG, $records[0]['context']['error_code']);
        self::assertSame('bad message format', $records[0]['context']['reason']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testAttachErrorCallbackLogsFatalErrorAsError(): void
    {
        $logger = new InMemoryLogger();
        $onBrokerError = $this->captureCallback(
            'setErrorCb',
            $logger,
            static fn(RdKafkaCallbacks $callbacks, Conf $conf) => $callbacks->attachErrorCallback($conf),
        );

        $onBrokerError($this->createMock(KafkaConsumer::class), \RD_KAFKA_RESP_ERR__FATAL, 'fatal broker error');

        $records = $logger->findByMessage('Kafka fatal error, client is unusable');
        self::assertCount(1, $records);
        self::assertSame(LogLevel::ERROR, $records[0]['level']);
        self::assertSame(\RD_KAFKA_RESP_ERR__FATAL, $records[0]['context']['error_code']);
        self::assertSame('fatal broker error', $records[0]['context']['reason']);
        // Ровно одна запись: после fatal-ветки код не должен проваливаться
        // в общий warning 'Kafka client error'.
        self::assertCount(1, $logger->records);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testAttachDeliveryReportCallbackLogsDeliveredMessageAsDebug(): void
    {
        $logger = new InMemoryLogger();
        $onDeliveryReport = $this->captureCallback(
            'setDrMsgCb',
            $logger,
            static fn(RdKafkaCallbacks $callbacks, Conf $conf) => $callbacks->attachDeliveryReportCallback($conf),
        );

        $onDeliveryReport($this->createMock(Producer::class), self::message([
            'err' => \RD_KAFKA_RESP_ERR_NO_ERROR,
            'topic_name' => 'test-topic',
            'partition' => 2,
            'offset' => 15,
        ]));

        $records = $logger->findByMessage('Message delivered');
        self::assertCount(1, $records);
        self::assertSame(LogLevel::DEBUG, $records[0]['level']);
        self::assertSame('test-topic', $records[0]['context']['topic']);
        self::assertSame(2, $records[0]['context']['partition']);
        self::assertSame(15, $records[0]['context']['offset']);
        // Ровно одна запись: без return успех-ветка проваливалась бы в error
        // 'Message delivery failed'.
        self::assertCount(1, $logger->records);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testAttachDeliveryReportCallbackLogsFailureAsError(): void
    {
        $logger = new InMemoryLogger();
        $onDeliveryReport = $this->captureCallback(
            'setDrMsgCb',
            $logger,
            static fn(RdKafkaCallbacks $callbacks, Conf $conf) => $callbacks->attachDeliveryReportCallback($conf),
        );

        $onDeliveryReport($this->createMock(Producer::class), self::message([
            'err' => \RD_KAFKA_RESP_ERR__MSG_TIMED_OUT,
            'topic_name' => 'test-topic',
            'partition' => 1,
        ]));

        $records = $logger->findByMessage('Message delivery failed');
        self::assertCount(1, $records);
        self::assertSame(LogLevel::ERROR, $records[0]['level']);
        self::assertSame('test-topic', $records[0]['context']['topic']);
        self::assertSame(1, $records[0]['context']['partition']);
        self::assertSame(\RD_KAFKA_RESP_ERR__MSG_TIMED_OUT, $records[0]['context']['error_code']);
        self::assertNotSame('', $records[0]['context']['reason']);
    }

    /**
     * Навешивает выбранный callback на mock RdKafka\Conf и возвращает
     * колбэк, захваченный из set*Cb(), для прямого вызова в тесте.
     *
     * @param 'setDrMsgCb'|'setErrorCb'|'setLogCb'   $setter Метод RdKafka\Conf, регистрирующий callback
     * @param \Closure(RdKafkaCallbacks, Conf): void $attach Действие, навешивающее callback на Conf
     */
    private function captureCallback(string $setter, InMemoryLogger $logger, \Closure $attach): \Closure
    {
        $captured = null;

        $conf = $this->createMock(Conf::class);
        $conf->expects($this->once())
            ->method($setter)
            ->willReturnCallback(static function (callable $callback) use (&$captured): void {
                $captured = $callback;
            })
        ;

        $attach(new RdKafkaCallbacks($logger), $conf);

        self::assertInstanceOf(\Closure::class, $captured);

        return $captured;
    }

    /**
     * @param array<string, mixed> $values
     */
    private static function message(array $values): Message
    {
        $message = new Message();
        foreach ($values as $name => $value) {
            // @phpstan-ignore property.dynamicName
            $message->{$name} = $value;
        }

        return $message;
    }
}
