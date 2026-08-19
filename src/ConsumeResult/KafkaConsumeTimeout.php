<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\ConsumeResult;

/**
 * Маркер таймаута consume(): полезной нагрузки нет.
 *
 * Раньше объект нёс partition/offset из служебного Message librdkafka,
 * но для таймаута это мусорные значения (-1/-1001), вводившие в заблуждение.
 */
final readonly class KafkaConsumeTimeout {}
