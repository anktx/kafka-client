# PHP Kakfa client wrapper

The package provides a robust and flexible client for interacting with Apache Kafka using PHP.
It aims to simplify the process of producing and consuming messages, making it easier for developers to integrate Kafka into their PHP applications.

## Requirements

- PHP 8.1 or higher.
- ext-rdkafka

## Installation

```shell
composer require anktx/kafka-client
```

## General usage

### Producer

```php
use Anktx\Kafka\Client\Config\ProducerConfig;
use Anktx\Kafka\Client\KafkaProducer;
use Anktx\Kafka\Client\Message\KafkaProducerMessage;

$kafkaProducer = new KafkaProducer(
    new ProducerConfig(
        brokers: 'kafka:9092',
        /*  >>> the rest are optional <<<
        queueBufferingMaxKBytes: 2048,
        batchSize: 1024,
        lingerMs: 10,
        compressionType: CompressionType::snappy,
        isDebug: true,
        logger: new \Psr\Log\NullLogger(),
        */
    )
);

$kafkaProducer->produce(
    new KafkaProducerMessage(
        topic: 'topic',
        body: 'message body',
        /*  >>> the rest are optional <<<
        partition: 1,
        key: 'key',
        headers: ['name' => 'value'],
        */
    )
);

$kafkaProducer->flush();
```

### Consumer

```php
<?php

use Anktx\Kafka\Client\Config\ConsumerConfig;
use Anktx\Kafka\Client\KafkaConsumer;
use Anktx\Kafka\Client\Subscription\Subscription;
use Anktx\Kafka\Client\Subscription\SubscriptionList;

$kafkaConsumer = new KafkaConsumer(
    new ConsumerConfig(
        brokers: 'kafka:9092',
        groupId: 'groupId',
        instanceId: '1',
        /*  >>> the rest are optional <<<
        offsetReset: OffsetReset::latest,
        autoCommitMs: 1000,
        sessionTimeoutMs: 10000,
        isDebug: true,
        logger: new \Psr\Log\NullLogger(),
        */
    )
);

$kafkaConsumer->subscribe(
    new SubscriptionList(
        new Subscription(topic: 'topic1'),
        // new Subscription(topic: 'topic2', partition: 1),
    ),
);

$messagesToConsume = 100;
$i = 0;

while (++$i < $messagesToConsume) {
    $message = $kafkaConsumer->consume();

    echo $message->body;
    print_r($message->headers);
    
    do_some_processing($message->body);

    $kafkaConsumer->commit($message);
}
```
