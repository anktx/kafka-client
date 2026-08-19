<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Exception;

/**
 * Маркер всех исключений библиотеки: реализуется базами обоих семейств
 * (Kafka, Logic) и позволяет поймать одним catch всё, что кидает библиотека.
 */
interface KafkaClientException extends \Throwable {}
