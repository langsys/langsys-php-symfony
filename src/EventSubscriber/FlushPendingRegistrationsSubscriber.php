<?php

namespace Langsys\Symfony\EventSubscriber;

use Langsys\SDK\Client;
use Langsys\SDK\Exception\LangsysException;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Flushes the SDK's pending-registration queue (phrases/content blocks
 * discovered during the request under a write key) AFTER the response is
 * sent, via kernel.terminate — aligning the flush with Symfony's lifecycle
 * instead of the SDK's register_shutdown_function (which never fires between
 * requests under FrankenPHP/RoadRunner/Swoole worker runtimes).
 *
 * flushPendingRegistrations() itself silently skips read-only keys and empty
 * queues; failures are swallowed so token registration can never break a
 * response.
 */
class FlushPendingRegistrationsSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly Client $client,
        private readonly bool $autoFlush,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::TERMINATE => 'onKernelTerminate'];
    }

    public function onKernelTerminate(TerminateEvent $event): void
    {
        if (!$this->autoFlush || !$this->client->hasPendingRegistrations()) {
            return;
        }

        try {
            $this->client->flushPendingRegistrations();
        } catch (LangsysException $e) {
            // Never break the request lifecycle over token registration.
            $this->logger?->warning('Langsys pending-registration flush failed.', ['exception' => $e]);
        }
    }
}
