{{-- Export the full report using the applied filter and current chart/table settings. --}}
@php
    $exportButton = 'inline-flex h-11 w-full shrink-0 items-center justify-center gap-2 rounded-lg px-4 text-sm font-semibold focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:cursor-not-allowed disabled:opacity-50';
    $spinner = 'size-3.5 animate-spin rounded-full border-2 border-t-transparent motion-reduce:animate-none';
@endphp

<div x-data="promoAnalysisActions" @promo-analysis-filter.window="clearStatus()" class="min-w-0 sm:w-52">
    <div class="flex flex-col gap-1.5 sm:items-end">
        <button
            type="button"
            class="{{ $exportButton }} border border-line bg-white text-ink hover:bg-soft"
            :disabled="exporting || actionsDisabled"
            :aria-busy="exporting.toString()"
            aria-describedby="promo-analysis-export-scope"
            @click="exportExcel()"
        >
            <span x-show="exporting" x-cloak class="{{ $spinner }} border-ink" aria-hidden="true"></span>
            <x-icon name="table" x-show="!exporting" class="size-4" />
            <span x-text="exporting ? 'Mengekspor…' : 'Ekspor Excel'">Ekspor Excel</span>
        </button>
    </div>

    <p
        x-show="status"
        x-cloak
        class="mt-2 rounded-lg border px-4 py-2.5 text-sm"
        :class="status?.type === 'error' ? 'border-red-200 bg-red-50 text-red-700' : 'border-line bg-soft text-muted'"
        :role="status?.type === 'error' ? 'alert' : 'status'"
        x-text="status?.message"
    ></p>
</div>
