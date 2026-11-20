@php
    $card = 'rounded-panel border border-line bg-surface shadow-sm';
    $canManageDivisions = auth()->user()->can('create', App\Models\Division::class);
@endphp

<div
    x-data="divisionManagement(@js($canManageDivisions), tab === 'divisions')"
    @organization-tab-changed.window="activate($event.detail)"
    class="min-w-0"
>
        <section class="{{ $card }} mt-6 overflow-hidden" aria-label="Ringkasan Divisi" :aria-busy="loading">
            <div class="flex flex-wrap items-center gap-x-5 gap-y-2 bg-soft px-5 py-5 sm:px-6">
                <div class="min-w-0 flex-1">
                    <h2 id="division-total-title" class="text-lg font-semibold text-navy">Total Divisi</h2>
                    <p class="mt-1 text-sm text-muted">Seluruh divisi terdaftar. </p>
                </div>
                <p aria-labelledby="division-total-title" class="text-4xl font-bold text-navy tabular-nums" :class="loadError && 'text-base!'" x-text="loading ? '—' : loadError ? 'Tidak tersedia' : divisions.length">—</p>
            </div>
            <div class="border-t border-line px-5 py-4 sm:px-6">
                <h3 class="mb-3 text-sm font-semibold text-ink">Keanggotaan divisi</h3>
                <dl class="grid gap-x-8 gap-y-4 sm:grid-cols-2">
                    <div class="flex min-w-0 items-baseline justify-between gap-3">
                        <dt class="text-sm text-muted">Akun dalam divisi</dt>
                        <dd class="text-lg font-semibold text-ink tabular-nums" x-text="loading || loadError ? '—' : totalMembers">—</dd>
                    </div>
                    <div class="flex min-w-0 items-baseline justify-between gap-3">
                        <dt class="text-sm text-muted">Divisi tanpa anggota</dt>
                        <dd class="text-lg font-semibold text-ink tabular-nums" x-text="loading || loadError ? '—' : emptyDivisions">—</dd>
                    </div>
                </dl>
            </div>
        </section>

        <section class="{{ $card }} mt-5 p-5 sm:p-6" aria-labelledby="division-list-title">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h2 id="division-list-title" class="text-lg font-semibold text-ink">Daftar Divisi</h2>
                    <p class="mt-1 text-sm text-muted">
                        {{ $canManageDivisions ? 'Kelola kode dan nama divisi, serta lihat jumlah anggotanya.' : 'Cari divisi berdasarkan kode atau nama. Hanya Manager yang dapat mengelola divisi.' }}
                    </p>
                </div>

                @if ($canManageDivisions)
                    <button
                        type="button"
                        class="inline-flex h-11 shrink-0 items-center justify-center gap-2 self-start rounded-lg bg-primary-hover px-4 text-sm font-semibold text-white hover:bg-navy focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:cursor-not-allowed disabled:opacity-50"
                        x-ref="divisionCreateButton" @click="openCreate()"
                    >
                        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14" /></svg>
                        Tambah Divisi
                    </button>
                @endif
            </div>

            @include('pages.settings.components.division-filter')
            @include('pages.settings.components.division-table', ['canManage' => $canManageDivisions])
        </section>

        @if ($canManageDivisions)
            @include('pages.settings.components.division-create-modal')
            @include('pages.settings.components.division-edit-modal')
            @include('pages.settings.components.division-delete-modal')
            @include('pages.settings.components.discard-changes-modal', ['dialogPrefix' => 'discard-division'])
        @endif
</div>
