{{-- Promo transactions and revenue per daily, weekly, or monthly bucket, with total sales as the overlay. Buckets and totals come from the backend. --}}
@php
    $periods = [
        ['value' => 'daily', 'label' => 'Harian'],
        ['value' => 'weekly', 'label' => 'Mingguan'],
        ['value' => 'monthly', 'label' => 'Bulanan'],
    ];
    $pill = 'inline-flex min-h-11 shrink-0 cursor-pointer items-center rounded-md px-3 text-xs font-semibold transition-colors peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-primary peer-disabled:cursor-not-allowed peer-disabled:opacity-40';
@endphp

<section
    x-data="promoAnalysisTrend"
    @promo-analysis-filter.window="load($event.detail)"
    @promo-analysis-copy.window="provideCopy($event.detail)"
    @promo-analysis-export-params.window="provideExportParams($event.detail)"
    class="{{ $card }} min-w-0 p-5"
    aria-labelledby="promo-trend-title"
>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h2 id="promo-trend-title" class="text-base font-semibold text-ink">Tren Penggunaan Promo</h2>

        <fieldset class="inline-flex shrink-0 items-center gap-0.5 rounded-lg bg-soft p-0.5" aria-label="Periode Grafik">
            @foreach ($periods as $option)
                <div>
                    <input
                        type="radio"
                        name="promo-trend-period"
                        id="promo-trend-period-{{ $option['value'] }}"
                        value="{{ $option['value'] }}"
                        class="peer sr-only"
                        :checked="activePeriod === @js($option['value'])"
                        :disabled="isPeriodDisabled(@js($option['value']))"
                        @change="setPeriod(@js($option['value']))"
                    >
                    <label
                        for="promo-trend-period-{{ $option['value'] }}"
                        class="{{ $pill }}"
                        :class="activePeriod === @js($option['value']) ? 'bg-white text-ink shadow-sm' : 'text-muted hover:text-ink'"
                        :title="isPeriodDisabled(@js($option['value'])) ? 'Rentang tanggal lebih dari 366 hari hanya dapat ditampilkan bulanan.' : null"
                    >{{ $option['label'] }}</label>
                </div>
            @endforeach
        </fieldset>
    </div>
    <p class="mt-2 text-xs leading-relaxed text-muted">Batang menunjukkan transaksi promo (sumbu kanan). Kedua garis menunjukkan revenue dalam rupiah (sumbu kiri); garis putus-putus adalah total penjualan.</p>

    <div class="relative mt-4 h-80" :aria-busy="loading.toString()">
        <canvas x-ref="canvas" role="img" :aria-label="chartLabel" :class="loading && hasPoints && 'opacity-50'"></canvas>

        <div x-show="loading && !hasPoints" class="absolute inset-0 animate-pulse rounded-lg bg-soft motion-reduce:animate-none" aria-hidden="true"></div>

        <div x-show="!loading && !hasPoints" x-cloak class="absolute inset-0 grid place-items-center rounded-lg border border-dashed border-line px-4 text-center">
            <div :role="loadError ? 'alert' : 'status'">
                <p class="text-sm font-medium" :class="loadError ? 'text-red-600' : 'text-ink'" x-text="placeholder"></p>
                <button x-show="loadError" type="button" class="mt-2 text-xs font-semibold text-primary hover:text-primary-hover" @click="retry()">Coba Lagi</button>
            </div>
        </div>
    </div>
    <details x-show="hasPoints && !loading && !loadError" x-cloak class="mt-4 border-t border-line pt-3">
        <summary class="cursor-pointer rounded text-sm font-semibold text-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">Lihat data tren</summary>
        <div class="mt-3 max-h-80 overflow-auto rounded-lg border border-line" tabindex="0" role="region" aria-label="Tabel data tren promo">
            <table class="w-full min-w-[640px] text-left text-sm">
                <caption class="sr-only">Data yang sama dengan grafik tren sesuai filter aktif</caption>
                <thead class="sticky top-0 bg-soft text-xs text-muted">
                    <tr>
                        <th scope="col" class="px-4 py-3">Periode</th>
                        <template x-for="series in tableSeries" :key="series.key">
                            <th scope="col" class="px-4 py-3 text-right" x-text="series.label + (series.format === 'currency' ? ' (Rp)' : ' (transaksi)')"></th>
                        </template>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    <template x-for="(row, index) in tableRows" :key="index">
                        <tr>
                            <th scope="row" class="px-4 py-3 font-medium text-ink" x-text="row.label"></th>
                            <template x-for="series in tableSeries" :key="series.key">
                                <td class="px-4 py-3 text-right whitespace-nowrap text-ink tabular-nums" x-text="format(row[series.key], series.format)"></td>
                            </template>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </details>
</section>
