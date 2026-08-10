<?php

namespace Langsys\Symfony\Tests;

use DateTimeImmutable;
use Langsys\Symfony\Interpolator;
use PHPUnit\Framework\TestCase as PhpUnitTestCase;

/**
 * Ported from langsys/laravel-sdk's InterpolatorTest. The two Interpolator
 * classes are byte-identical apart from their namespace, and the docblock on
 * each says they are kept in lockstep — so these assertions must stay in
 * lockstep too. If you change one suite, change the other.
 */
class InterpolatorTest extends PhpUnitTestCase
{
    private const WELCOME = '{name_gender, select, male {Bienvenido, {name}!} '
        . 'female {Bienvenida, {name}!} other {Te damos la bienvenida, {name}!}}';

    private Interpolator $interpolator;

    protected function setUp(): void
    {
        $this->interpolator = new Interpolator();
    }

    public function testSubstitutesSimplePlaceholders(): void
    {
        $result = $this->interpolator->interpolate('Hello, {name}!', ['name' => 'Sarah']);

        $this->assertSame('Hello, Sarah!', $result);
    }

    public function testLeavesUnknownPlaceholdersUntouched(): void
    {
        $result = $this->interpolator->interpolate('Hello, {name}! You have {count} messages.', ['name' => 'Sarah']);

        $this->assertSame('Hello, Sarah! You have {count} messages.', $result);
    }

    public function testLeavesNullParamsUntouched(): void
    {
        $result = $this->interpolator->interpolate('Hello, {name}!', ['name' => null]);

        $this->assertSame('Hello, {name}!', $result);
    }

    public function testTrimsPlaceholderWhitespace(): void
    {
        $result = $this->interpolator->interpolate('Hello, { name }!', ['name' => 'Sarah']);

        $this->assertSame('Hello, Sarah!', $result);
    }

    public function testFormatsNumbersForTheTargetLocale(): void
    {
        if (!extension_loaded('intl')) {
            $this->markTestSkipped('ext-intl not available');
        }

        $result = $this->interpolator->interpolate('{amount} items', ['amount' => 1234.5], 'de-DE');

        $this->assertSame('1.234,5 items', $result);
    }

    public function testStringNumbersOptOutOfFormatting(): void
    {
        $result = $this->interpolator->interpolate('Order {id}', ['id' => '1234567'], 'de-DE');

        $this->assertSame('Order 1234567', $result);
    }

    public function testFormatsDatesForTheTargetLocale(): void
    {
        if (!extension_loaded('intl')) {
            $this->markTestSkipped('ext-intl not available');
        }

        $date = new DateTimeImmutable('2026-07-07');
        $result = $this->interpolator->interpolate('Due {when}', ['when' => $date], 'en-US');

        $this->assertStringContainsString('2026', $result);
        $this->assertStringContainsString('Jul', $result);
    }

    public function testRendersBooleansLikeTheJsSdk(): void
    {
        $result = $this->interpolator->interpolate('Active: {flag}', ['flag' => true]);

        $this->assertSame('Active: true', $result);
    }

    public function testIcuPluralSelectsTheRightBranch(): void
    {
        if (!extension_loaded('intl')) {
            $this->markTestSkipped('ext-intl not available');
        }

        $template = '{count, plural, one {# item} other {# items}}';

        $this->assertSame('1 item', $this->interpolator->interpolate($template, ['count' => 1], 'en'));
        $this->assertSame('3 items', $this->interpolator->interpolate($template, ['count' => 3], 'en'));
    }

    public function testMalformedIcuFallsBackToSimpleInterpolation(): void
    {
        $result = $this->interpolator->interpolate('{count, plural, broken {name}', ['name' => 'x'], 'en');

        $this->assertStringContainsString('x', $result);
    }

    public function testIsIcuDetectsIcuButNotPlainSlots(): void
    {
        $this->assertTrue($this->interpolator->isIcu('{n, plural, one {#} other {#}}'));
        $this->assertTrue($this->interpolator->isIcu('{n, number}'));
        $this->assertFalse($this->interpolator->isIcu('Hello, {name}!'));
    }

    public function testNoParamsReturnsTemplateUnchanged(): void
    {
        $this->assertSame('Hello, {name}!', $this->interpolator->interpolate('Hello, {name}!', []));
    }
    public function testSelectBranchUsesSuppliedGender(): void
    {
        if (!extension_loaded('intl')) {
            $this->markTestSkipped('ext-intl not available');
        }

        $this->assertSame('Bienvenida, Laura!', $this->interpolator->interpolate(
            self::WELCOME, ['name' => 'Laura', 'name_gender' => 'female'], 'es'
        ));
        $this->assertSame('Bienvenido, Diego!', $this->interpolator->interpolate(
            self::WELCOME, ['name' => 'Diego', 'name_gender' => 'male'], 'es'
        ));
    }

    public function testMissingSelectArgumentFallsBackToOtherBranch(): void
    {
        if (!extension_loaded('intl')) {
            $this->markTestSkipped('ext-intl not available');
        }

        // The ICU promoter introduces `name_gender`; an app with no gender
        // data can't supply it and must still get a correct sentence.
        $this->assertSame('Te damos la bienvenida, Laura!', $this->interpolator->interpolate(
            self::WELCOME, ['name' => 'Laura'], 'es'
        ));
        $this->assertSame('Te damos la bienvenida, Laura!', $this->interpolator->interpolate(
            self::WELCOME, ['name' => 'Laura', 'name_gender' => null], 'es'
        ));
    }

    public function testUnrecognizedSelectValueFallsBackToOtherBranch(): void
    {
        if (!extension_loaded('intl')) {
            $this->markTestSkipped('ext-intl not available');
        }

        $this->assertSame('Te damos la bienvenida, Laura!', $this->interpolator->interpolate(
            self::WELCOME, ['name' => 'Laura', 'name_gender' => 'nonbinary'], 'es'
        ));
    }

    public function testFillsOnlyTheMissingArgumentWhenSeveralSelectsArePresent(): void
    {
        if (!extension_loaded('intl')) {
            $this->markTestSkipped('ext-intl not available');
        }

        $template = '{a_gender, select, female {Ella} other {Elle}} y {b_gender, select, female {ella} other {elle}}';

        $this->assertSame('Ella y elle', $this->interpolator->interpolate($template, ['a_gender' => 'female'], 'es'));
    }

    public function testFillsSelectsNestedInsidePluralBranches(): void
    {
        if (!extension_loaded('intl')) {
            $this->markTestSkipped('ext-intl not available');
        }

        $template = '{n, plural, one {{g, select, female {Una invitada} other {Un invitado}}} other {# invitados}}';

        $this->assertSame('Un invitado', $this->interpolator->interpolate($template, ['n' => 1], 'es'));
        $this->assertSame('Una invitada', $this->interpolator->interpolate($template, ['n' => 1, 'g' => 'female'], 'es'));
    }

    public function testFillsPluralsNestedInsideSelectBranches(): void
    {
        if (!extension_loaded('intl')) {
            $this->markTestSkipped('ext-intl not available');
        }

        $template = '{g, select, female {Ella tiene {n, plural, one {# mensaje} other {# mensajes}}} '
            . 'other {Tiene {n, plural, one {# mensaje} other {# mensajes}}}}';

        $this->assertSame('Tiene 3 mensajes', $this->interpolator->interpolate($template, ['n' => 3], 'es'));
        $this->assertSame('Ella tiene 3 mensajes', $this->interpolator->interpolate($template, ['n' => 3, 'g' => 'female'], 'es'));
    }

    public function testMissingPluralArgumentDoesNotEchoTheArgumentName(): void
    {
        if (!extension_loaded('intl')) {
            $this->markTestSkipped('ext-intl not available');
        }

        // ext-intl returns a bare "{n}" for a missing count, discarding the
        // sentence. Only selects get a neutral default, so this must fall
        // through to simple interpolation rather than render "{n}".
        $result = $this->interpolator->interpolate('{n, plural, one {# mensaje} other {# mensajes}}', [], 'es');

        $this->assertNotSame('{n}', $result);
    }

    public function testSelectOrdinalIsNotTreatedAsSelect(): void
    {
        if (!extension_loaded('intl')) {
            $this->markTestSkipped('ext-intl not available');
        }

        $template = '{n, selectordinal, one {#st} two {#nd} few {#rd} other {#th}}';

        $this->assertSame('4th', $this->interpolator->interpolate($template, ['n' => 4], 'en'));
    }
}
