<?php

namespace HasanHawary\ExportBuilder\Models\Relations;

use HasanHawary\ExportBuilder\Models\ExportFile;
use HasanHawary\ExportBuilder\Models\UnresolvedExportable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * morphTo for ExportFile::exportable(): "exportable_type" holds a page slug ("user"),
 * a morph-map alias or a class name. Types that cannot be resolved give null
 * instead of a "class not found" error.
 */
class ExportableMorphTo extends MorphTo
{
    public function getResults()
    {
        return $this->related instanceof UnresolvedExportable ? null : parent::getResults();
    }

    public function createModelByType($type)
    {
        $class = ExportFile::resolveExportableClass((string) $type) ?? UnresolvedExportable::class;

        return tap(new $class, function ($instance) {
            if (! $instance->getConnectionName()) {
                $instance->setConnection($this->getConnection()->getName());
            }
        });
    }

    protected function getResultsByType($type)
    {
        if (ExportFile::resolveExportableClass((string) $type) === null) {
            return new Collection;
        }

        return parent::getResultsByType($type);
    }
}
