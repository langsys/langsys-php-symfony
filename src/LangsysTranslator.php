<?php

namespace Langsys\Symfony;

use Langsys\SDK\Client;
use Langsys\SDK\Exception\LangsysException;
use Langsys\SDK\Locale\LocaleDetector;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The service every integration point (Twig function, autowired usage) calls.
 * Wraps the vanilla SDK's phrase lookup and adds the Symfony-shaped concerns
 * around it: the request locale, and a failure mode that degrades instead of
 * 500ing. This is also the mockable seam for app tests — the SDK's cURL client
 * is concrete and non-injectable, so fake this (or decorate langsys.client)
 * instead of stubbing HTTP.
 *
 * Interpolation is deliberately NOT here. It belongs to langsys/langsys-php,
 * which resolves placeholders and ICU constructs inside translate() itself; a
 * thin binding defers to its upstream rather than carrying a second copy that
 * can drift.
 */
class LangsysTranslator
{
    public function __construct(
        private readonly Client $client,
        private readonly RequestStack $requestStack,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Translate a phrase. Mirrors the JS SDKs' t(phrase, category?, params?):
     * the phrase is both the lookup key and the base-language default, and
     * `{name}` placeholders are substituted from $params after lookup.
     */
    public function translate(string $phrase, ?string $category = null, array $params = [], ?string $locale = null): string
    {
        // Default to the request locale (set by LocaleSubscriber), not
        // Client::getLocale() — the latter auto-detects from $_SERVER and can
        // fall back to an HTTP call for the project's base locale when unset.
        $locale ??= $this->requestStack->getCurrentRequest()?->getLocale() ?? 'en';

        // The API returns null for a phrase that exists but has no translation
        // yet, and the SDK's empty check (`$value !== ''`) lets null through.
        // Fall back to the phrase, same as the SDK's empty-string handling.
        try {
            return $this->client->translate(
                $phrase,
                LocaleDetector::normalize($locale),
                $category ?? '__uncategorized__',
                null,
                $params,
            ) ?? $phrase;
        } catch (LangsysException $e) {
            // An unreachable or refusing API must never take the page down —
            // serve the base-language phrase and log the failure instead of
            // letting it become a 500.
            $this->logger?->error('Langsys translation failed; serving source phrase.', ['exception' => $e]);
        }

        // Degraded path: the phrase still has to come back with its
        // placeholders resolved, so run it through the same interpolator the
        // successful path used.
        return $params === []
            ? $phrase
            : $this->client->getInterpolator()->interpolate($phrase, $params, $locale);
    }

    public function client(): Client
    {
        return $this->client;
    }
}
