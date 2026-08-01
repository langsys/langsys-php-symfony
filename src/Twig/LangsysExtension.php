<?php

namespace Langsys\Symfony\Twig;

use Langsys\Symfony\LangsysTranslator;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * The Blade @t directive's Twig counterpart:
 *
 *   {{ t('Welcome to our store', 'Home') }}
 *   {{ t('Hello, {name}!', 'Home', {name: user.name}) }}
 *   {{ 'Save changes'|t('Buttons') }}
 *
 * Output goes through Twig's normal auto-escaping, matching the Blade
 * directive's e(t(...)).
 */
class LangsysExtension extends AbstractExtension
{
    public function __construct(private readonly LangsysTranslator $translator)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('t', $this->translator->translate(...)),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('t', $this->translator->translate(...)),
        ];
    }
}
