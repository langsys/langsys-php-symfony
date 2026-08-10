<?php

namespace Langsys\Symfony\Tests\EventSubscriber;

use Langsys\SDK\Client;
use Langsys\SDK\Exception\LangsysException;
use Langsys\Symfony\EventSubscriber\FlushPendingRegistrationsSubscriber;
use PHPUnit\Framework\TestCase as PhpUnitTestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Ported from langsys/laravel-sdk's FlushPendingRegistrationsTest. Flushing on
 * kernel.terminate is what makes this work under FrankenPHP/RoadRunner/Swoole,
 * where the SDK's own register_shutdown_function never fires between requests.
 */
class FlushPendingRegistrationsSubscriberTest extends PhpUnitTestCase
{
    private function event(): TerminateEvent
    {
        return new TerminateEvent(
            $this->createMock(HttpKernelInterface::class),
            Request::create('/'),
            new Response()
        );
    }

    public function testSubscribesToTerminateOnly(): void
    {
        $this->assertSame(
            [KernelEvents::TERMINATE => 'onKernelTerminate'],
            FlushPendingRegistrationsSubscriber::getSubscribedEvents()
        );
    }

    public function testFlushesWhenRegistrationsArePending(): void
    {
        $client = $this->createMock(Client::class);
        $client->method('hasPendingRegistrations')->willReturn(true);
        $client->expects($this->once())->method('flushPendingRegistrations');

        (new FlushPendingRegistrationsSubscriber($client, true))->onKernelTerminate($this->event());
    }

    public function testDoesNotFlushWhenTheQueueIsEmpty(): void
    {
        $client = $this->createMock(Client::class);
        $client->method('hasPendingRegistrations')->willReturn(false);
        $client->expects($this->never())->method('flushPendingRegistrations');

        (new FlushPendingRegistrationsSubscriber($client, true))->onKernelTerminate($this->event());
    }

    public function testDoesNotFlushWhenAutoFlushIsOff(): void
    {
        $client = $this->createMock(Client::class);
        $client->expects($this->never())->method('flushPendingRegistrations');

        (new FlushPendingRegistrationsSubscriber($client, false))->onKernelTerminate($this->event());
    }

    public function testDoesNotEvenAskTheClientWhenAutoFlushIsOff(): void
    {
        $client = $this->createMock(Client::class);
        $client->expects($this->never())->method('hasPendingRegistrations');

        (new FlushPendingRegistrationsSubscriber($client, false))->onKernelTerminate($this->event());
    }

    public function testSwallowsAFailedFlush(): void
    {
        // Token registration must never break the request lifecycle.
        $client = $this->createMock(Client::class);
        $client->method('hasPendingRegistrations')->willReturn(true);
        $client->method('flushPendingRegistrations')->willThrowException(new LangsysException('offline'));

        $subscriber = new FlushPendingRegistrationsSubscriber($client, true);

        $this->expectNotToPerformAssertions();
        $subscriber->onKernelTerminate($this->event());
    }

    public function testLogsAFailedFlush(): void
    {
        $client = $this->createMock(Client::class);
        $client->method('hasPendingRegistrations')->willReturn(true);
        $client->method('flushPendingRegistrations')->willThrowException(new LangsysException('offline'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        (new FlushPendingRegistrationsSubscriber($client, true, $logger))->onKernelTerminate($this->event());
    }

    public function testSurvivesAFailedFlushWithNoLogger(): void
    {
        $client = $this->createMock(Client::class);
        $client->method('hasPendingRegistrations')->willReturn(true);
        $client->method('flushPendingRegistrations')->willThrowException(new LangsysException('offline'));

        $subscriber = new FlushPendingRegistrationsSubscriber($client, true, null);

        $this->expectNotToPerformAssertions();
        $subscriber->onKernelTerminate($this->event());
    }
}
