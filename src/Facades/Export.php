<?php

namespace HasanHawary\ExportBuilder\Facades;

use HasanHawary\ExportBuilder\ExportBuilder;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Facade for the Export Builder.
 *
 *   return Export::make(['page' => 'user', 'format' => 'xlsx'])->response();
 *
 * Same as: (new ExportBuilder($filters))->response()
 *
 * @method static ExportBuilder make(array $filter)
 * @method static BinaryFileResponse response()  Only useful after make(); without filters it answers 422.
 * @see ExportBuilder
 */
class Export extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ExportBuilder::class;
    }
}
