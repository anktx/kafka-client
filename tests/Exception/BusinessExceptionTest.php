<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Tests\Exception;

use Anktx\Kafka\Client\Exception\Business\BusinessException;
use Anktx\Kafka\Client\Exception\Business\EmptySubscriptionsException;
use Anktx\Kafka\Client\Exception\Business\TopicHasNoPartitionException;
use PHPUnit\Framework\TestCase;

final class BusinessExceptionTest extends TestCase
{
    public function testBusinessExceptionExtendsDomainException(): void
    {
        $exception = new class ('Test message') extends BusinessException {};

        $this->assertInstanceOf(\DomainException::class, $exception);
        $this->assertSame('Test message', $exception->getMessage());
    }

    public function testEmptySubscriptionsException(): void
    {
        $exception = new EmptySubscriptionsException('No subscriptions');

        $this->assertInstanceOf(BusinessException::class, $exception);
        $this->assertInstanceOf(EmptySubscriptionsException::class, $exception);
        $this->assertSame('No subscriptions', $exception->getMessage());
    }

    public function testTopicHasNoPartitionException(): void
    {
        $exception = new TopicHasNoPartitionException('Topic has no partition');

        $this->assertInstanceOf(BusinessException::class, $exception);
        $this->assertInstanceOf(TopicHasNoPartitionException::class, $exception);
        $this->assertSame('Topic has no partition', $exception->getMessage());
    }

    public function testEmptySubscriptionsExceptionWithCode(): void
    {
        $exception = new EmptySubscriptionsException('No subscriptions', 100);

        $this->assertSame(100, $exception->getCode());
    }

    public function testTopicHasNoPartitionExceptionWithCode(): void
    {
        $exception = new TopicHasNoPartitionException('Topic has no partition', 200);

        $this->assertSame(200, $exception->getCode());
    }

    public function testBusinessExceptionWithPrevious(): void
    {
        $previous = new \Exception('Previous error');
        $exception = new class ('Test message', 0, $previous) extends BusinessException {};

        $this->assertSame($previous, $exception->getPrevious());
    }

    public function testEmptySubscriptionsExceptionIsDomainException(): void
    {
        $exception = new EmptySubscriptionsException('Test');

        $this->assertInstanceOf(\DomainException::class, $exception);
    }

    public function testTopicHasNoPartitionExceptionIsDomainException(): void
    {
        $exception = new TopicHasNoPartitionException('Test');

        $this->assertInstanceOf(\DomainException::class, $exception);
    }

    public function testEmptySubscriptionsExceptionCreate(): void
    {
        $exception = EmptySubscriptionsException::create();

        $this->assertInstanceOf(BusinessException::class, $exception);
        $this->assertInstanceOf(EmptySubscriptionsException::class, $exception);
        $this->assertSame('At least one subscription is required', $exception->getMessage());
    }

    public function testTopicHasNoPartitionExceptionCreate(): void
    {
        $exception = TopicHasNoPartitionException::create('test-topic');

        $this->assertInstanceOf(BusinessException::class, $exception);
        $this->assertInstanceOf(TopicHasNoPartitionException::class, $exception);
        $this->assertSame('Topic "test-topic" has no partition', $exception->getMessage());
    }
}
