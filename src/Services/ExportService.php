<?php

namespace HasanHawary\ExportBuilder\Services;

use HasanHawary\ExportBuilder\ExportBuilder;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ExportService
{
    public function response(array $filters): BinaryFileResponse
    {
        return (new ExportBuilder($filters))->response();
    }
}
