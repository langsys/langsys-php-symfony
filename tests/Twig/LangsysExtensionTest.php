<?php

namespace Langsys\Symfony\Tests\Twig;

use Langsys\SDK\Client;
use Langsys\Symfony\Interpolator;
use Langsys\Symfony\LangsysTranslator;
use Langsys\Symfony\Twig\LangsysExtension;
use PHPUnit\Framework\TestCase as PhpUnitTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * Ported from langsys/laravel-sdk's BladeDirectiveTest: `{{ t(...) }}` is the
 * Twig counterpart of the @t directive, and must escape its output the same
 * way e(t(...)) does — a translation is user-supplied content.
 */
class LangsysExtensionTest extends PhpUnitTestCase
{
    private function render(string $template, string $translation, array $context = []): string
    {
        $client = $this->createMock(Client::class);
        $client->method('translate')->willReturn($translation);

        $stack = new RequestStack();
        $request = Request::create('/');
        $request->setLocale('en');
        $stack->push($request);

        $twig = new Environment(new ArrayLoader(['page' => $template]));
        $twig->addExtension(new LangsysExtension(
            new LangsysTranslator($client, new Interpolator(), $stack)
        ));

        return $twig->render('page', $context);
    }

    public function testRegistersATFunction(): void
    {
        $extension = new LangsysExtension($this->createMock(LangsysTranslator::class));

        $names = array_map(fn ($fn) => $fn->getName(), $extension->getFunctions());

        $this->assertSame(['t'], $names);
    }

    public function testRegistersATFilter(): void
    {
        $extension = new LangsysExtension($this->createMock(LangsysTranslator::class));

        $names = array_map(fn ($filter) => $filter->getName(), $extension->getFilters());

        $this->assertSame(['t'], $names);
    }

    public function testRendersATranslationViaTheFunction(): void
    {
        $this->assertSame('Bienvenido', $this->render("{{ t('Welcome') }}", 'Bienvenido'));
    }

    public function testRendersATranslationViaTheFilter(): void
    {
        $this->assertSame('Guardar', $this->render("{{ 'Save'|t }}", 'Guardar'));
    }

    public function testPassesACategoryThroughTheFilter(): void
    {
        $this->assertSame('Guardar', $this->render("{{ 'Save'|t('Buttons') }}", 'Guardar'));
    }

    public function testInterpolatesParamsFromATwigHash(): void
    {
        $this->assertSame(
            'Hola, Sarah!',
            $this->render("{{ t('Hello, {name}!', 'Home', {name: 'Sarah'}) }}", 'Hola, {name}!')
        );
    }

    public function testInterpolatesAValueFromTheTemplateContext(): void
    {
        $this->assertSame(
            'Hola, Sarah!',
            $this->render("{{ t('Hello, {name}!', 'Home', {name: user}) }}", 'Hola, {name}!', ['user' => 'Sarah'])
        );
    }

    public function testEscapesTranslatedOutput(): void
    {
        // Matches the Blade directive's e(t(...)) — a translation is content,
        // not markup, and the Translation Manager is a user-facing surface.
        $this->assertSame(
            '&lt;script&gt;alert(1)&lt;/script&gt;',
            $this->render("{{ t('x') }}", '<script>alert(1)</script>')
        );
    }

    public function testEscapesInterpolatedParamsToo(): void
    {
        $this->assertSame(
            'Hola, &lt;b&gt;Sarah&lt;/b&gt;!',
            $this->render("{{ t('Hello, {name}!', 'Home', {name: '<b>Sarah</b>'}) }}", 'Hola, {name}!')
        );
    }

    public function testFallsBackToThePhraseInsideATemplate(): void
    {
        $client = $this->createMock(Client::class);
        $client->method('translate')->willReturn(null);

        $stack = new RequestStack();
        $request = Request::create('/');
        $request->setLocale('en');
        $stack->push($request);

        $twig = new Environment(new ArrayLoader(['page' => "{{ t('Welcome') }}"]));
        $twig->addExtension(new LangsysExtension(
            new LangsysTranslator($client, new Interpolator(), $stack)
        ));

        $this->assertSame('Welcome', $twig->render('page'));
    }
}
