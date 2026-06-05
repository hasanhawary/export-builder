<?php

namespace HasanHawary\ExportBuilder\Facades;

use HasanHawary\ExportBuilder\ExportBuilder;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Facade for the Export Builder.
 *
 * The ExportBuilder requires filters at construction time, so use the factory
 * helper instead of calling response() directly on the facade:
 *
 *   // Recommended — direct instantiation
 *   return (new ExportBuilder($filters))->response();
 *
 *   // Via container with parameters
 *   return app()->makeWith(ExportBuilder::class, ['filter' => $filters])->response();
 *
 * @method static BinaryFileResponse response()
 * @see ExportBuilder
 */
class Export extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ExportBuilder::class;
    }
}
