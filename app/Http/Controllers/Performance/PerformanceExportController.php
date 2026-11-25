<?php

namespace App\Http\Controllers\Performance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Performance\PerformanceExportRequest;
use App\Services\PerformanceExportService;
use App\Support\Period\PeriodSelection;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PerformanceExportController extends Controller
{
    public function __construct(private PerformanceExportService $performanceExportService) {}

    /**
     * Download the Ringkasan Performa data for the active filter as Excel or CSV.
     */
    public function __invoke(PerformanceExportRequest $request): BinaryFileResponse
    {
        $export = $this->performanceExportService->export(
            $request->filter(),
            $request->validated('format'),
            $request->filterForDataset(PeriodSelection::DATASET_DOWNLOADERS),
        );

        return response()->download($export['path'], $export['filename'])->deleteFileAfterSend();
    }
}
