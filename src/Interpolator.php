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
            $formatted = MessageFormatter::formatMessage($locale, $template, $params);
            if ($formatted !== false) {
                return $formatted;
            }
        }

        return $this->_simpleInterpolate($template, $params, $locale);
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
