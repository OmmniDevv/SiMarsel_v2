<?php

namespace App\Repositories;

use App\Enums\DashboardDataset;
use App\Models\DashboardSnapshot;
use App\Models\Downloader;
use App\Models\Platform;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Reads the stored Downloader data: the latest daily total per (platform_id, date) from downloaders and
 * the downloaders dashboard_snapshots recorded at a cutoff. It only reads stored state and never sums snapshots.
 */
class DownloaderRepository
{
    /**
     * The selected platforms ordered by id; no slug means every platform.
     *
     * @param  list<string>  $slugs
     * @return array<int, array{slug: string, name: string}>
     */
    public function platforms(array $slugs): array
    {
        return Platform::query()
            ->when($slugs !== [], fn (Builder $query) => $query->whereIn('slug', $slugs))
            ->orderBy('id')
            ->get(['id', 'slug', 'name'])
            ->mapWithKeys(fn (Platform $platform) => [(int) $platform->id => ['slug' => $platform->slug, 'name' => $platform->name]])
            ->all();
    }

    /**
     * The latest daily total of every platform and date in the inclusive range.
     *
     * @param  list<int>  $platformIds
     * @return array<int, array<string, int>> platform_id => [Y-m-d => total]
     */
    public function dailyTotals(array $platformIds, CarbonImmutable $dateFrom, CarbonImmutable $dateTo): array
    {
        $totals = [];

        Downloader::query()
            ->whereIn('platform_id', $platformIds)
            ->whereDate('date', '>=', $dateFrom->toDateString())
            ->whereDate('date', '<=', $dateTo->toDateString())
            ->toBase()
            ->get(['platform_id', 'date', 'total'])
            ->each(function (object $row) use (&$totals) {
                $totals[(int) $row->platform_id][substr((string) $row->date, 0, 10)] = (int) $row->total;
            });

        return $totals;
    }

    /**
     * The downloaders snapshots of the selected platforms with a cutoff in [startAt, endAt], in the row shape
     * read by ComparisonSnapshotService::read().
     *
     * @param  list<int>  $platformIds
     * @return list<array<string, mixed>>
     */
    public function snapshots(array $platformIds, CarbonImmutable $startAt, CarbonImmutable $endAt): array
    {
        return DashboardSnapshot::query()
            ->where('dataset', DashboardDataset::Downloaders->value)
            ->whereIn('platform_id', $platformIds)
            ->where('cutoff_at', '>=', $startAt->format('Y-m-d H:i:s'))
            ->where('cutoff_at', '<=', $endAt->format('Y-m-d H:i:s'))
            ->get()
            ->map(fn (DashboardSnapshot $snapshot) => $snapshot->toComparisonRow())
            ->all();
    }
}
