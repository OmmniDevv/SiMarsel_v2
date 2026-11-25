<?php

namespace App\Http\Controllers\Performance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Performance\DownloaderTrendRequest;
use App\Http\Resources\DownloaderTrendResource;
use App\Services\DownloaderAggregationService;

class DownloaderTrendController extends Controller
{
    public function __construct(private DownloaderAggregationService $downloaderAggregationService) {}

    public function __invoke(DownloaderTrendRequest $request): DownloaderTrendResource
    {
        return DownloaderTrendResource::make(
            $this->downloaderAggregationService->trend($request->filter(), $request->granularity()),
        );
    }
}
