<?php

namespace Langsys\Symfony\Tests\EventSubscriber;

use Langsys\SDK\Client;
use Langsys\Symfony\EventSubscriber\LocaleSubscriber;
use PHPUnit\Framework\TestCase as PhpUnitTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Ported from langsys/laravel-sdk's DetectLocaleTest. Source-chain precedence
 * is the contract: an explicit ?locale= must beat a stored cookie, which must
 * beat Accept-Language, or a user's language switch silently does nothing.
 */
class LocaleSubscriberTest extends PhpUnitTestCase
{
    private const DEFAULTS = [
        'sources' => ['query', 'cookie', 'session', 'header'],
        'query_param' => 'locale',
        'cookie' => 'langsys_locale',
        'session_key' => 'langsys_locale',
        'persist' => 'cookie',
        'supported' => [],
        'cookie_minutes' => 525600,
    ];

    private function subscriber(array $overrides = [], ?Client $client = null): LocaleSubscriber
    {
        return new LocaleSubscriber($client ?? $this->createMock(Client::class), $overrides + self::DEFAULTS);
    }

    private function event(Request $request, int $type = HttpKernelInterface::MAIN_REQUEST): RequestEvent
    {
        return new RequestEvent($this->createMock(HttpKernelInterface::class), $request, $type);
    }

    public function testSubscribesToRequestAndResponse(): void
    {
        $events = LocaleSubscriber::getSubscribedEvents();

        $this->assertArrayHasKey(KernelEvents::REQUEST, $events);
        $this->assertArrayHasKey(KernelEvents::RESPONSE, $events);
    }

    public function testRunsAfterSymfonysOwnLocaleListener(): void
    {
        // Symfony's LocaleListener is priority 16; ours must be lower so an
        // explicit Langsys source overrides the framework default.
        $this->assertSame(10, LocaleSubscriber::getSubscribedEvents()[KernelEvents::REQUEST][0][1]);
        $this->assertLessThan(16, LocaleSubscriber::getSubscribedEvents()[KernelEvents::REQUEST][0][1]);
    }

    public function testResolvesTheLocaleFromTheQueryString(): void
    {
        $request = Request::create('/?locale=fr-FR');

        $this->subscriber()->onKernelRequest($this->event($request));

        $this->assertSame('fr-FR', $request->getLocale());
    }

    public function testResolvesTheLocaleFromACookie(): void
    {
        $request = Request::create('/', 'GET', [], ['langsys_locale' => 'ja-JP']);

        $this->subscriber()->onKernelRequest($this->event($request));

        $this->assertSame('ja-JP', $request->getLocale());
    }

    public function testResolvesTheLocaleFromTheSession(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $session->set('langsys_locale', 'de-DE');
        $request = Request::create('/');
        $request->setSession($session);

        $this->subscriber(['sources' => ['session']])->onKernelRequest($this->event($request));

        $this->assertSame('de-DE', $request->getLocale());
    }

    public function testResolvesTheLocaleFromAcceptLanguage(): void
    {
        $request = Request::create('/', 'GET', [], [], [], ['HTTP_ACCEPT_LANGUAGE' => 'es-ES,es;q=0.9']);

        $this->subscriber(['sources' => ['header']])->onKernelRequest($this->event($request));

        $this->assertSame('es-ES', $request->getLocale());
    }

    public function testQueryStringBeatsCookie(): void
    {
        $request = Request::create('/?locale=fr-FR', 'GET', [], ['langsys_locale' => 'ja-JP']);

        $this->subscriber()->onKernelRequest($this->event($request));

        $this->assertSame('fr-FR', $request->getLocale());
    }

    public function testCookieBeatsAcceptLanguage(): void
    {
        $request = Request::create('/', 'GET', [], ['langsys_locale' => 'ja-JP'], [], ['HTTP_ACCEPT_LANGUAGE' => 'es-ES']);

        $this->subscriber()->onKernelRequest($this->event($request));

        $this->assertSame('ja-JP', $request->getLocale());
    }

    public function testCanonicalizesWhateverTheSourceProvided(): void
    {
        $request = Request::create('/?locale=pt_br');

        $this->subscriber()->onKernelRequest($this->event($request));

        $this->assertSame('pt-BR', $request->getLocale());
    }

    public function testPushesTheLocaleOntoTheSdkClient(): void
    {
        $client = $this->createMock(Client::class);
        $client->expects($this->once())->method('setLocale')->with('fr-FR');

        $this->subscriber([], $client)->onKernelRequest($this->event(Request::create('/?locale=fr-FR')));
    }

    public function testIgnoresSubRequests(): void
    {
        $request = Request::create('/?locale=fr-FR');
        $request->setLocale('en');

        $this->subscriber()->onKernelRequest($this->event($request, HttpKernelInterface::SUB_REQUEST));

        $this->assertSame('en', $request->getLocale());
    }

    public function testLeavesTheLocaleAloneWhenNoSourceMatches(): void
    {
        $request = Request::create('/');
        $request->setLocale('en');

        $this->subscriber(['sources' => ['query']])->onKernelRequest($this->event($request));

        $this->assertSame('en', $request->getLocale());
    }

    public function testRejectsALocaleOutsideTheSupportedList(): void
    {
        // Query source only: Request::create() supplies a default
        // `Accept-Language: en-us,en;q=0.5`, so leaving 'header' in the chain
        // would resolve en-US here and hide what this test is asserting.
        $request = Request::create('/?locale=ru-RU');
        $request->setLocale('en');

        $this->subscriber(['sources' => ['query'], 'supported' => ['en-US', 'fr-FR']])
            ->onKernelRequest($this->event($request));

        $this->assertSame('en', $request->getLocale());
    }

    public function testFallsThroughToAcceptLanguageWhenTheQueryLocaleIsUnsupported(): void
    {
        $request = Request::create('/?locale=ru-RU'); // default header is en-us
        $request->setLocale('xx');

        $this->subscriber(['supported' => ['en-US', 'fr-FR']])->onKernelRequest($this->event($request));

        $this->assertSame('en-US', $request->getLocale());
    }

    public function testAcceptsASupportedLocaleRegardlessOfCasing(): void
    {
        $request = Request::create('/?locale=FR_fr');

        $this->subscriber(['supported' => ['fr-FR']])->onKernelRequest($this->event($request));

        $this->assertSame('fr-FR', $request->getLocale());
    }

    public function testFallsThroughToTheNextSourceWhenOneIsUnsupported(): void
    {
        $request = Request::create('/?locale=ru-RU', 'GET', [], ['langsys_locale' => 'fr-FR']);

        $this->subscriber(['supported' => ['fr-FR']])->onKernelRequest($this->event($request));

        $this->assertSame('fr-FR', $request->getLocale());
    }

    public function testPersistsAnExplicitChoiceToTheSession(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $request = Request::create('/?locale=fr-FR');
        $request->setSession($session);

        $this->subscriber(['persist' => 'session'])->onKernelRequest($this->event($request));

        $this->assertSame('fr-FR', $session->get('langsys_locale'));
    }

    public function testDoesNotPersistALocaleThatCameFromAHeader(): void
    {
        // Only an explicit choice (the query param) is worth remembering;
        // persisting a header would pin a guess forever.
        $session = new Session(new MockArraySessionStorage());
        $request = Request::create('/', 'GET', [], [], [], ['HTTP_ACCEPT_LANGUAGE' => 'es-ES']);
        $request->setSession($session);

        $this->subscriber(['persist' => 'session', 'sources' => ['header']])
            ->onKernelRequest($this->event($request));

        $this->assertNull($session->get('langsys_locale'));
    }

    public function testSetsACookieOnTheResponseForAnExplicitChoice(): void
    {
        $request = Request::create('/?locale=fr-FR');
        $subscriber = $this->subscriber();
        $subscriber->onKernelRequest($this->event($request));

        $response = new Response();
        $subscriber->onKernelResponse(new ResponseEvent(
            $this->createMock(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response
        ));

        $cookies = $response->headers->getCookies();
        $this->assertCount(1, $cookies);
        $this->assertSame('langsys_locale', $cookies[0]->getName());
        $this->assertSame('fr-FR', $cookies[0]->getValue());
    }

    public function testSetsNoCookieWhenNothingWasChosen(): void
    {
        $request = Request::create('/');
        $subscriber = $this->subscriber();
        $subscriber->onKernelRequest($this->event($request));

        $response = new Response();
        $subscriber->onKernelResponse(new ResponseEvent(
            $this->createMock(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response
        ));

        $this->assertCount(0, $response->headers->getCookies());
    }

    public function testTheCookieOutlivesTheSession(): void
    {
        $request = Request::create('/?locale=fr-FR');
        $subscriber = $this->subscriber(['cookie_minutes' => 60]);
        $subscriber->onKernelRequest($this->event($request));

        $response = new Response();
        $subscriber->onKernelResponse(new ResponseEvent(
            $this->createMock(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response
        ));

        $this->assertGreaterThan(time(), $response->headers->getCookies()[0]->getExpiresTime());
    }
}
