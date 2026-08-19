<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Tests\Integration;

use Anktx\Kafka\Client\Config\ProducerConfig;
use PHPUnit\Framework\TestCase;
use RdKafka\Conf;

final class ProducerConfigIntegrationTest extends TestCase
{
    public function testAsKafkaConfig(): void
    {
        $config = new ProducerConfig('localhost:9092');
        $kafkaConfig = $config->asKafkaConfig();

        self::assertInstanceOf(Conf::class, $kafkaConfig);
    }
}
