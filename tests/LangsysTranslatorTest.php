<?php

namespace Langsys\Symfony\Tests;

use Langsys\SDK\Client;
use Langsys\SDK\Exception\LangsysException;
use Langsys\Symfony\Interpolator;
use Langsys\Symfony\LangsysTranslator;
use PHPUnit\Framework\TestCase as PhpUnitTestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Ported from langsys/laravel-sdk's LangsysTranslatorTest. The behavior that
 * matters here is the degradation contract: a phrase must render even when the
 * API returns null, throws, or was never reachable — an i18n layer that 500s
 * takes the whole page with it.
 */
class LangsysTranslatorTest extends PhpUnitTestCase
{
    private function stack(?string $locale = null): RequestStack
    {
        $stack = new RequestStack();

        if ($locale !== null) {
            $request = Request::create('/');
            $request->setLocale($locale);
            $stack->push($request);
        }

        return $stack;
    }

    private function translator(Client $client, ?RequestStack $stack = null, ?LoggerInterface $logger = null): LangsysTranslator
    {
        return new LangsysTranslator($client, new Interpolator(), $stack ?? $this->stack('en'), $logger);
    }

    public function testReturnsTheTranslationFromTheClient(): void
    {
        $client = $this->createMock(Client::class);
        $client->method('translate')->willReturn('Bienvenido');

        $this->assertSame('Bienvenido', $this->translator($client)->translate('Welcome'));
    }

    public function testFallsBackToThePhraseWhenTheClientReturnsNull(): void
    {
        // The API returns null for a phrase that exists but has no translation
        // yet; the SDK's `!== ''` check lets that through unguarded.
        $client = $this->createMock(Client::class);
        $client->method('translate')->willReturn(null);

        $this->assertSame('Welcome', $this->translator($client)->translate('Welcome'));
    }

    public function testFallsBackToThePhraseWhenTheApiThrows(): void
    {
        $client = $this->createMock(Client::class);
        $client->method('translate')->willThrowException(new LangsysException('connection refused'));

        $this->assertSame('Welcome', $this->translator($client)->translate('Welcome'));
    }

    public function testLogsWhenTheApiThrows(): void
    {
        $client = $this->createMock(Client::class);
        $client->method('translate')->willThrowException(new LangsysException('connection refused'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');

        $this->translator($client, null, $logger)->translate('Welcome');
    }

    public function testWorksWithoutALogger(): void
    {
        $client = $this->createMock(Client::class);
        $client->method('translate')->willThrowException(new LangsysException('offline'));

        $this->assertSame('Welcome', $this->translator($client, null, null)->translate('Welcome'));
    }

    public function testUsesTheRequestLocaleByDefault(): void
    {
        $client = $this->createMock(Client::class);
        $client->expects($this->once())
            ->method('translate')
            ->with('Welcome', 'fr-fr', '__uncategorized__')
            ->willReturn('Bienvenue');

        $this->translator($client, $this->stack('fr-FR'))->translate('Welcome');
    }

    public function testFallsBackToEnWhenThereIsNoRequest(): void
    {
        // CLI commands and worker runtimes have no current request. Resolving
        // via Client::getLocale() there can trigger an HTTP round-trip for the
        // project base locale, so the translator hardcodes 'en' instead.
        $client = $this->createMock(Client::class);
        $client->expects($this->once())
            ->method('translate')
            ->with('Welcome', 'en', '__uncategorized__')
            ->willReturn('Welcome');

        $this->translator($client, $this->stack())->translate('Welcome');
    }

    public function testAnExplicitLocaleWinsOverTheRequest(): void
    {
        $client = $this->createMock(Client::class);
        $client->expects($this->once())
            ->method('translate')
            ->with('Welcome', 'ja-jp', '__uncategorized__')
            ->willReturn('ようこそ');

        $this->translator($client, $this->stack('fr-FR'))->translate('Welcome', null, [], 'ja-JP');
    }

    public function testNormalizesTheLocaleForTheSdk(): void
    {
        $client = $this->createMock(Client::class);
        $client->expects($this->once())
            ->method('translate')
            ->with('Welcome', 'pt-br', '__uncategorized__')
            ->willReturn('Bem-vindo');

        $this->translator($client, $this->stack('pt_BR'))->translate('Welcome');
    }

    public function testPassesTheCategoryThrough(): void
    {
        $client = $this->createMock(Client::class);
        $client->expects($this->once())
            ->method('translate')
            ->with('Save', 'en', 'Buttons')
            ->willReturn('Guardar');

        $this->translator($client)->translate('Save', 'Buttons');
    }

    public function testDefaultsAnOmittedCategoryToUncategorized(): void
    {
        $client = $this->createMock(Client::class);
        $client->expects($this->once())
            ->method('translate')
            ->with('Save', 'en', '__uncategorized__')
            ->willReturn('Guardar');

        $this->translator($client)->translate('Save');
    }

    public function testInterpolatesParamsIntoTheTranslation(): void
    {
        $client = $this->createMock(Client::class);
        $client->method('translate')->willReturn('Hola, {name}!');

        $this->assertSame('Hola, Sarah!', $this->translator($client)->translate('Hello, {name}!', null, ['name' => 'Sarah']));
    }

    public function testInterpolatesIntoTheFallbackPhraseToo(): void
    {
        // Degrading to the source phrase must not also lose the params —
        // '{name}' leaking into rendered output is worse than an untranslated
        // but complete sentence.
        $client = $this->createMock(Client::class);
        $client->method('translate')->willThrowException(new LangsysException('offline'));

        $this->assertSame(
            'Hello, Sarah!',
            $this->translator($client)->translate('Hello, {name}!', null, ['name' => 'Sarah'])
        );
    }

    public function testSkipsInterpolationEntirelyWhenThereAreNoParams(): void
    {
        $client = $this->createMock(Client::class);
        $client->method('translate')->willReturn('Cost: {0}');

        $this->assertSame('Cost: {0}', $this->translator($client)->translate('Cost: {0}'));
    }

    public function testExposesTheUnderlyingClient(): void
    {
        $client = $this->createMock(Client::class);

        $this->assertSame($client, $this->translator($client)->client());
    }
}
