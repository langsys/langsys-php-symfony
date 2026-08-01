<?php

namespace Langsys\Symfony\EventSubscriber;

use DateTimeImmutable;
use Langsys\SDK\Client;
use Langsys\SDK\Locale\LocaleDetector;
use Langsys\Symfony\Support\LocaleFormatter;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Symfony counterpart of langsys/laravel-sdk's DetectLocale middleware.
 * Resolves the request locale through the configured source chain
 * (query → cookie → session → Accept-Language by default), sets it on both
 * the Request and the Langsys client, and persists an explicit choice
 * (query source) via cookie or session so later requests keep it.
 */
class LocaleSubscriber implements EventSubscriberInterface
{
    private const PERSIST_ATTRIBUTE = '_langsys_persist_locale';

    /** @param array{sources: string[], query_param: string, cookie: string, session_key: string, persist: ?string, supported: string[], cookie_minutes: int} $config */
    public function __construct(
        private readonly Client $client,
        private readonly array $config,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Priority 10: after Symfony's own LocaleListener (16), so an
            // explicit Langsys source overrides the framework default.
            KernelEvents::REQUEST => [['onKernelRequest', 10]],
            KernelEvents::RESPONSE => 'onKernelResponse',
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        [$locale, $source] = $this->_resolve($request);

        if (!$locale) {
            return;
        }

        $request->setLocale($locale);
        $this->client->setLocale($locale);

        if ($source === 'query') {
            if ($this->config['persist'] === 'session' && $request->hasSession()) {
                $request->getSession()->set($this->config['session_key'], $locale);
            } elseif ($this->config['persist'] === 'cookie') {
                $request->attributes->set(self::PERSIST_ATTRIBUTE, $locale);
            }
        }
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $locale = $event->getRequest()->attributes->get(self::PERSIST_ATTRIBUTE);

        if (!$locale) {
            return;
        }

        $event->getResponse()->headers->setCookie(
            Cookie::create($this->config['cookie'], $locale)
                ->withExpires(new DateTimeImmutable(sprintf('+%d minutes', $this->config['cookie_minutes'])))
        );
    }

    /** @return array{0: ?string, 1: ?string} [locale, source] */
    private function _resolve(Request $request): array
    {
        foreach ($this->config['sources'] as $source) {
            $locale = $this->_fromSource($request, $source);

            if ($locale && $this->_isSupported($locale)) {
                // Canonical BCP 47 for the Request and downstream consumers;
                // the SDK re-normalizes to its lowercase form internally.
                return [LocaleFormatter::canonicalize($locale), $source];
            }
        }

        return [null, null];
    }

    private function _fromSource(Request $request, string $source): ?string
    {
        return match ($source) {
            'query'   => $request->query->get($this->config['query_param']),
            'cookie'  => $request->cookies->get($this->config['cookie']),
            'session' => $request->hasSession()
                ? $request->getSession()->get($this->config['session_key'])
                : null,
            'header'  => $this->_fromAcceptLanguage($request->headers->get('Accept-Language')),
            default   => null,
        };
    }

    private function _fromAcceptLanguage(?string $header): ?string
    {
        if (!$header) {
            return null;
        }

        if (function_exists('locale_accept_from_http')) {
            $locale = locale_accept_from_http($header);
            if ($locale) {
                return $locale;
            }
        }

        preg_match('/^\s*([a-zA-Z]{2,3})(?:[-_]([a-zA-Z]{2}))?/', $header, $matches);

        if (!isset($matches[1])) {
            return null;
        }

        return isset($matches[2]) ? "$matches[1]-$matches[2]" : $matches[1];
    }

    private function _isSupported(string $locale): bool
    {
        $supported = $this->config['supported'];

        if ($supported === []) {
            return true;
        }

        return in_array(
            LocaleDetector::normalize($locale),
            array_map(LocaleDetector::normalize(...), $supported),
            true
        );
    }
}
