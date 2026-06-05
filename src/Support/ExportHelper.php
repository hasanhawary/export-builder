<?php

namespace HasanHawary\ExportBuilder\Support;

use Illuminate\Support\Str;

/**
 * Static utility helpers for the Export Builder package.
 *
 * Replaces the global functions eb_isArrayIndex() and eb_resolveTrans()
 * with a proper namespaced class — no global namespace pollution.
 *
 * The global functions in helpers.php are kept as thin aliases for
 * backwards compatibility with any host-app code that calls them directly.
 */
final class ExportHelper
{
    /**
     * Determine whether an array is purely sequential (integer-indexed).
     * Returns false for associative arrays or empty arrays treated as associative.
     */
    public static function isIndexedArray(mixed $value): bool
    {
        return is_array($value)
            && count(array_filter(array_keys($value), 'is_string')) === 0;
    }

    /**
     * Resolve a translation key using the given file/namespace.
     * Falls back to the raw value when no translation exists.
     *
     * @param  mixed   $trans   The key to translate (snake_cased automatically)
     * @param  string  $page    The translation file/namespace (e.g. 'api', 'export')
     * @param  string|null  $lang   Optional locale override
     * @param  bool    $snaked  Whether to snake_case the key before looking it up
     */
    public static function resolveTrans(
        mixed   $trans  = '',
        string  $page   = 'api',
        ?string $lang   = null,
        bool    $snaked = true
    ): string {
        if (empty($trans)) {
            return '---';
        }

        app()->setLocale($lang ?? app()->getLocale());

        $key = $snaked ? Str::snake((string) $trans) : (string) $trans;

        // Package namespace lookup (e.g. 'export' → 'export::export.{key}')
        if (! str_contains($page, '::') && ! str_contains($page, '.')) {
            $namespacedKey = "{$page}::{$page}.{$key}";
            $translated    = __($namespacedKey);
            if (! Str::startsWith($translated, "{$page}::")) {
                return $translated;
            }
        }

        // Flat file lookup (e.g. 'api.{key}')
        $translated = __("{$page}.{$key}");

        return Str::startsWith($translated, "{$page}.") ? (string) $trans : $translated;
    }
}
