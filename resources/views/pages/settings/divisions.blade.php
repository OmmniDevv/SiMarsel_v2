@php
    $card = 'rounded-panel border border-line bg-surface shadow-sm';
    $canManageDivisions = auth()->user()->can('create', App\Models\Division::class);
@endphp

<div
    x-data="divisionManagement(@js($canManageDivisions), tab === 'divisions')"
    @organization-tab-changed.window="activate($event.detail)"
    class="min-w-0"
>
        <section class="{{ $card }} mt-6 px-5 py-4" aria-label="Ringkasan Divisi" :aria-busy="loading">
            <dl class="grid grid-cols-2 gap-x-6 gap-y-4 sm:grid-cols-3">
                <div class="col-span-2 border-b border-line pb-4 sm:col-span-1 sm:border-b-0 sm:pb-0">
                    <dt class="text-xs font-semibold text-muted">Total Divisi</dt>
                    <dd class="mt-1 text-xl font-bold text-ink tabular-nums" x-text="loading ? '—' : loadError ? 'Tidak tersedia' : divisions.length">—</dd>
                </div>
                <div class="min-w-0">
                    <dt class="text-xs font-medium text-muted">Anggota Divisi</dt>
                    <dd class="mt-1 text-lg font-semibold text-ink tabular-nums" x-text="loading ? '—' : loadError ? '—' : totalMembers">—</dd>
                </div>
                <div class="min-w-0">
                    <dt class="text-xs font-medium text-muted">Divisi Tanpa Anggota</dt>
                    <dd class="mt-1 text-lg font-semibold text-ink tabular-nums" x-text="loading ? '—' : loadError ? '—' : emptyDivisions">—</dd>
                </div>
            </dl>
        </section>

        <section class="{{ $card }} mt-6 p-5" aria-labelledby="division-list-title">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h2 id="division-list-title" class="text-base font-semibold text-ink">Daftar Divisi</h2>
                    <p class="mt-1 text-sm text-muted">
                        {{ $canManageDivisions ? 'Tambah, ubah, atau hapus divisi yang tidak lagi digunakan.' : 'Hanya manager yang dapat menambah, mengubah, atau menghapus divisi.' }}
                    </p>
                </div>

                @if ($canManageDivisions)
                    <button
                        type="button"
                        class="inline-flex h-9 shrink-0 items-center justify-center gap-2 self-start rounded-lg bg-primary-hover px-4 text-sm font-semibold text-white hover:bg-navy focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:cursor-not-allowed disabled:opacity-50"
                        x-ref="divisionCreateButton" @click="openCreate()"
                    >
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
        @endif
</div>
