<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Tests\Topic;

use Anktx\Kafka\Client\Exception\Logic\InvalidTopicException;
use Anktx\Kafka\Client\Topic\Topic;
use PHPUnit\Framework\TestCase;

/**
 * Юнит-тесты value object {@see Topic}: инвариант непустого имени —
 * общий для сообщений, подписок и результатов consume(); граничные
 * случаи проверки topic больше не дублируются их тестами.
 */
final class TopicTest extends TestCase
{
    public function testCreate(): void
    {
        $topic = new Topic('test-topic');

        self::assertSame('test-topic', $topic->name);
    }

    public function testRejectsEmptyName(): void
    {
        $this->expectException(InvalidTopicException::class);
        $this->expectExceptionMessage('Topic name must not be an empty string');

        new Topic('');
    }
}
