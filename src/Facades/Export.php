<?php

namespace HasanHawary\ReportBuilder\Facades;

use HasanHawary\ExportBuilder\ExportBuilder;
use Illuminate\Support\Facades\Facade;

/**
 * Facade for the ReportBuilder.
 *
 * @method static mixed response()
 * @see \HasanHawary\ExportBuilder\ExportBuilder
 */
class Export extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ExportBuilder::class;
    }
}
