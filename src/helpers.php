<?php

use HasanHawary\ExportBuilder\Support\ExportHelper;

/**
 * Global function aliases for ExportHelper static methods.
 *
 * Kept for backwards compatibility. Prefer calling ExportHelper directly
 * in new code: ExportHelper::isIndexedArray() / ExportHelper::resolveTrans()
 */
if (! function_exists('eb_isArrayIndex')) {
    function eb_isArrayIndex(mixed $value): bool
    {
        return ExportHelper::isIndexedArray($value);
    }
}

if (! function_exists('eb_resolveTrans')) {
    function eb_resolveTrans(
        mixed   $trans  = '',
        string  $page   = 'api',
        ?string $lang   = null,
        bool    $snaked = true
    ): string {
        return ExportHelper::resolveTrans($trans, $page, $lang, $snaked);
    }
}
