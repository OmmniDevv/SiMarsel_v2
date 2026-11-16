{{--
    Empty dashboard section reserved for a follow-up ticket.
    Variables: $key (slot name), $title, $exportLabel (optional, export action for this section).
--}}
<div class="flex flex-wrap items-start justify-between gap-3">
    <h2 id="dashboard-{{ $key }}-title" class="text-base font-semibold text-ink">{{ $title }}</h2>

    @isset($exportLabel)
        <div data-dashboard-slot="{{ $key }}-export" class="flex flex-wrap items-center gap-2">
            <button type="button" disabled class="inline-flex h-9 cursor-not-allowed items-center justify-center rounded-lg border border-line bg-white px-3 text-sm font-semibold text-label opacity-60">
                {{ $exportLabel }}
            </button>
        </div>
    @endisset
</div>

<div data-dashboard-slot="{{ $key }}" class="mt-4 min-w-0">
    <p class="grid min-h-32 place-items-center rounded-lg border border-dashed border-line bg-soft px-4 py-6 text-center text-sm text-muted">
        Bagian ini sedang disiapkan.
    </p>
</div>
