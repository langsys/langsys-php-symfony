<?php

namespace Langsys\Symfony;

use DateTimeInterface;
use IntlDateFormatter;
use MessageFormatter;
use NumberFormatter;

/**
 * Port of the base JS SDK's interpolate() (langsys-js-typescript
 * src/interpolate.ts) so `{name}` phrases render identically across the JS
 * SDKs and Symfony. The vanilla PHP SDK has no interpolation — this is where
 * params and ICU pluralization live on the PHP side. Kept in lockstep with
 * the identical class in langsys/laravel-sdk.
 *
 * Two paths:
 *   - ICU MessageFormat (`{var, plural|select|…, …}`): formatted via ext-intl's
 *     MessageFormatter, which knows every target locale's plural rules.
 *     Malformed ICU (or missing ext-intl) falls through to simple
 *     interpolation rather than throwing.
 *   - Simple `{name}` slots: regex replacement. Unknown placeholders are left
 *     untouched so missing data is visible rather than silently empty.
 *     Numbers and dates are CLDR-formatted for the target locale; pass strings
 *     to opt out (IDs and codes that must not get grouping separators).
 */
class Interpolator
{
    private const ICU_PATTERN = '/\{[^{}]+,\s*(plural|select|selectordinal|number|date|time)\s*[,}]/';

    public function isIcu(string $template): bool
    {
        return (bool) preg_match(self::ICU_PATTERN, $template);
    }

    public function interpolate(string $template, array $params, string $locale = 'en'): string
    {
        if ($this->isIcu($template) && class_exists(MessageFormatter::class)) {
            $formatted = MessageFormatter::formatMessage($locale, $template, $this->_fillMissingSelectArgs($template, $params));
            if ($formatted !== false && !$this->_isArgumentEcho($formatted, $template)) {
                return $formatted;
            }
        }

        return $this->_simpleInterpolate($template, $params, $locale);
    }

    /**
     * Fill in `'other'` for every `select` argument the caller didn't provide.
     *
     * The ICU promoter in langsys-ai introduces a select argument the source
     * phrase never had — `{name}` in the source becomes a `{name_gender,
     * select, …}` branch in the gendered target locales — so an app that
     * doesn't know its user's gender has no way to supply it. Without this,
     * ext-intl discards the whole sentence and returns a bare `{name_gender}`,
     * and only in the gendered locales. Defaulting to `other` yields the
     * neutral branch, which is the correct sentence for an app with no gender
     * data.
     *
     * Port of `withSelectDefaults` in the base JS SDK (langsys-js-typescript
     * src/interpolate.ts), which walks the parsed AST; there is no ICU parser
     * on the PHP side, so we scan the template instead. The pattern matches
     * nested selects too, and `selectordinal` can't match it (no comma follows
     * `select`). Explicitly-passed values are never touched, and an
     * unrecognized value already lands on `other` under ICU's own semantics.
     */
    private function _fillMissingSelectArgs(string $template, array $params): array
    {
        if (!preg_match_all('/\{\s*([A-Za-z_][A-Za-z0-9_]*)\s*,\s*select\s*,/', $template, $matches)) {
            return $params;
        }

        foreach ($matches[1] as $argument) {
            if (!array_key_exists($argument, $params) || $params[$argument] === null) {
                $params[$argument] = 'other';
            }
        }

        return $params;
    }

    /**
     * True when ext-intl gave up and echoed an argument name instead of
     * formatting — its failure mode for an argument the caller never passed
     * (a missing plural count, say, which has no sane neutral branch the way
     * a select does). It returns that echo as a successful string rather than
     * `false`, so without this check the caller would render `{n}` in place of
     * the whole sentence. Falling through to simple interpolation instead
     * mirrors what the JS SDK does when intl-messageformat throws.
     */
    private function _isArgumentEcho(string $formatted, string $template): bool
    {
        return $formatted !== $template && preg_match('/^\{\s*[A-Za-z_][A-Za-z0-9_]*\s*\}$/', $formatted) === 1;
    }

    private function _simpleInterpolate(string $template, array $params, string $locale): string
    {
        return preg_replace_callback('/\{([^{},]+)\}/', function (array $match) use ($params, $locale) {
            $key = trim($match[1]);

            if (!array_key_exists($key, $params) || $params[$key] === null) {
                return $match[0];
            }

            return $this->_formatValue($params[$key], $locale);
        }, $template);
    }

    private function _formatValue(mixed $value, string $locale): string
    {
        if ($value instanceof DateTimeInterface) {
            return $this->_formatDate($value, $locale);
        }

        if (is_int($value) || is_float($value)) {
            return $this->_formatNumber($value, $locale);
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }

    private function _formatDate(DateTimeInterface $value, string $locale): string
    {
        if (!class_exists(IntlDateFormatter::class)) {
            return $value->format(DateTimeInterface::ATOM);
        }

        $formatted = IntlDateFormatter::create($locale, IntlDateFormatter::MEDIUM, IntlDateFormatter::NONE)
            ?->format($value);

        return $formatted !== false && $formatted !== null ? $formatted : $value->format(DateTimeInterface::ATOM);
    }

    private function _formatNumber(int|float $value, string $locale): string
    {
        if (!class_exists(NumberFormatter::class)) {
            return (string) $value;
        }

        $formatted = NumberFormatter::create($locale, NumberFormatter::DEFAULT_STYLE)?->format($value);

        return $formatted !== false && $formatted !== null ? $formatted : (string) $value;
    }
}
