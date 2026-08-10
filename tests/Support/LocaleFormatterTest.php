<?php

namespace Langsys\Symfony\Tests\Support;

use Langsys\Symfony\Support\LocaleFormatter;
use PHPUnit\Framework\TestCase as PhpUnitTestCase;

/**
 * The PHP-side analog of the JS SDKs' canonicalizeLocale — the cases below
 * mirror tests/locale.test.ts in langsys-js-typescript, because the request
 * locale and the SSR handoff to the JS SDK have to agree on one spelling.
 */
class LocaleFormatterTest extends PhpUnitTestCase
{
    public function testCanonicalizesCasing(): void
    {
        $this->assertSame('en-US', LocaleFormatter::canonicalize('en-us'));
        $this->assertSame('en-US', LocaleFormatter::canonicalize('EN-US'));
        $this->assertSame('es-ES', LocaleFormatter::canonicalize('es-es'));
    }

    public function testAcceptsUnderscoreSeparators(): void
    {
        $this->assertSame('pt-BR', LocaleFormatter::canonicalize('pt_br'));
    }

    public function testCanonicalizesScriptSubtags(): void
    {
        if (!extension_loaded('intl')) {
            $this->markTestSkipped('ext-intl not available');
        }

        $this->assertSame('zh-Hant-TW', LocaleFormatter::canonicalize('zh-hant-tw'));
        $this->assertSame('sr-Cyrl-RS', LocaleFormatter::canonicalize('sr_cyrl_rs'));
    }

    public function testLeavesBareLanguageCodesLowercase(): void
    {
        $this->assertSame('en', LocaleFormatter::canonicalize('en'));
        $this->assertSame('es', LocaleFormatter::canonicalize('ES'));
    }

    public function testPassesEmptyStringThrough(): void
    {
        $this->assertSame('', LocaleFormatter::canonicalize(''));
    }

    public function testIsIdempotent(): void
    {
        $once = LocaleFormatter::canonicalize('pt_br');

        $this->assertSame($once, LocaleFormatter::canonicalize($once));
    }

    public function testNeverReturnsUnderscores(): void
    {
        $this->assertStringNotContainsString('_', LocaleFormatter::canonicalize('zh_hant_tw'));
    }
}
