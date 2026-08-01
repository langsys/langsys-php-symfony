<?php

namespace Langsys\Symfony\Support;

use Locale;

/**
 * Canonical BCP 47 casing (the JS SDKs' canonicalizeLocale analog):
 * 'es-es' / 'pt_br' → 'es-ES' / 'pt-BR', 'zh-hant-tw' → 'zh-Hant-TW'.
 *
 * The vanilla PHP SDK normalizes locales to lowercase internally
 * (LocaleDetector::normalize), which the Langsys API accepts — but the
 * request locale and the JS SDKs' initialTranslationsLocale handoff expect
 * the canonical form, so the bundle canonicalizes at those boundaries.
 */
class LocaleFormatter
{
    public static function canonicalize(string $locale): string
    {
        if ($locale === '') {
            return $locale;
        }

        if (class_exists(Locale::class)) {
            $canonical = Locale::canonicalize($locale);

            if ($canonical) {
                return str_replace('_', '-', $canonical);
            }
        }

        [$language, $region] = array_pad(explode('-', str_replace('_', '-', $locale), 2), 2, null);

        return $region === null ? strtolower($language) : strtolower($language) . '-' . strtoupper($region);
    }
}
