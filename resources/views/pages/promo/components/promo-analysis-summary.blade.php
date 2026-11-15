{{-- KPI cards for the active analysis filter. Every value, comparison and formula comes from the backend. --}}
@php
    $metrics = [
        ['key' => 'promo_revenue', 'label' => 'Revenue Promo', 'format' => 'currency', 'primary' => true],
        ['key' => 'promo_transactions', 'label' => 'Transaksi Promo', 'format' => 'number', 'primary' => true],
        ['key' => 'non_promo_revenue', 'label' => 'Revenue tanpa Promo', 'format' => 'currency'],
        ['key' => 'non_promo_transactions', 'label' => 'Transaksi tanpa Promo', 'format' => 'number'],
        ['key' => 'promo_transaction_percentage', 'label' => 'Persentase Transaksi Promo', 'format' => 'percent'],
        ['key' => 'used_promo_codes', 'label' => 'Kode Promo Terpakai', 'format' => 'number'],
    ];
    $stateButton = 'inline-flex h-9 items-center justify-center rounded-lg border border-line bg-white px-3 text-sm font-semibold text-ink hover:bg-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary';
@endphp

<section
    x-data="promoAnalysisSummary(@js($metrics))"
    @promo-analysis-filter.window="load($event.detail)"
    @promo-analysis-copy.window="provideCopy($event.detail)"
    class="{{ $card }} min-w-0 p-5"
    aria-labelledby="promo-summary-title"
>
    <h2 id="promo-summary-title" class="text-base font-semibold text-ink">Ringkasan Performa</h2>
    <div x-show="!loading && !loadError && summary" x-cloak class="mt-2 text-xs leading-relaxed text-muted" aria-live="polite">
        <p>Periode berjalan: <span class="font-medium text-ink" x-text="currentPeriod"></span></p>
        <p>Pembanding: <span class="font-medium text-ink" x-text="previousPeriod"></span>. Persentase mengikuti cakupan tanggal ini; jumlah hari dapat berbeda.</p>
    </div>

    <div x-show="!loading && loadError" x-cloak class="flex flex-col items-center gap-3 rounded-lg border border-red-200 bg-red-50 px-4 py-10 text-center" role="alert">
        <p class="text-sm text-red-700" x-text="loadError"></p>
        <button type="button" class="{{ $stateButton }}" @click="retry()">Coba Lagi</button>
    </div>

    <p x-show="!loading && isEmpty" x-cloak x-text="emptyMessage" class="mb-4 rounded-lg border border-dashed border-line bg-soft px-4 py-6 text-center text-sm font-semibold text-ink" role="status"></p>

    <div x-show="!loadError" class="mt-5 grid gap-5 sm:grid-cols-2" :aria-busy="loading.toString()">
        @foreach (array_slice($metrics, 0, 2) as $metric)
            @include('pages.promo.components.promo-analysis-card', ['metric' => $metric])
        @endforeach
    </div>
    <div x-show="!loadError" class="mt-5 grid gap-5 border-t border-line pt-5 sm:grid-cols-2 xl:grid-cols-4" :aria-busy="loading.toString()">
        @foreach (array_slice($metrics, 2) as $metric)
            @include('pages.promo.components.promo-analysis-card', ['metric' => $metric])
        @endforeach
    </div>
</section>
