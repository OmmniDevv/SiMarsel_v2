<?php

namespace App\Http\Requests\Performance;

use App\Support\Period\PeriodSelection;

/**
 * The Downloader trend uses the same preset, comparison, and granularity rules as the sales trend,
 * but always resolves the period on the downloaders completeness metadata.
 */
class DownloaderTrendRequest extends PerformanceSalesTrendRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['dataset' => PeriodSelection::DATASET_DOWNLOADERS]);
    }
}
