{{-- Spreadsheet-style Bulk Entry. Columns follow Single Entry; there is no code type or active status by design. --}}
@php
    $cellClass = 'h-11 w-full rounded-md border border-line bg-white px-3 text-sm text-ink placeholder:text-label focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none disabled:cursor-not-allowed disabled:bg-soft disabled:text-muted';
    $cellError = 'mt-1 text-xs text-red-600';
    $secondaryButton = 'inline-flex h-11 items-center justify-center gap-2 rounded-lg border border-line bg-white px-3 text-sm font-semibold text-ink hover:bg-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:cursor-not-allowed disabled:opacity-50';
    $columns = [
        ['field' => 'platform_id', 'label' => 'Platform', 'options' => 'platforms', 'empty' => 'Pilih platform'],
        ['field' => 'funnel_id', 'label' => 'Funnel', 'options' => 'funnels', 'empty' => 'Belum diketahui'],
        ['field' => 'source_id', 'label' => 'Source', 'options' => 'sources', 'empty' => 'Belum diketahui'],
    ];
@endphp

<div
    x-data="promoCodeBulkEntry"
    @promo-bulk-open.window="open()"
    x-dialog="isOpen"
    x-show="isOpen"
    x-cloak
    class="fixed inset-0 z-50 flex items-center justify-center p-4"
    role="dialog"
    aria-modal="true"
    aria-labelledby="promo-bulk-title"
    @keydown.escape.window="isOpen && close()"
    @keydown.ctrl.enter.prevent="submit()"
    @keydown.meta.enter.prevent="submit()"
>
    <div x-show="isOpen" x-transition.opacity class="absolute inset-0 bg-slate-900/50" @click="close()"></div>

    <div
        x-show="isOpen"
        x-transition
        class="relative flex max-h-[calc(100vh-2rem)] w-full max-w-6xl flex-col rounded-panel border border-line bg-surface shadow-lg"
    >
        <div class="border-b border-line p-5">
            <h2 id="promo-bulk-title" class="text-base font-semibold text-ink">Input Massal</h2>
            <p class="mt-1 text-sm text-muted">
                Isi kode dan pilih platform pada setiap baris. Funnel dan Source boleh dikosongkan.
            </p>
            <p class="mt-2 text-xs leading-relaxed text-muted">Gunakan Tab untuk berpindah kolom. Tambah baris sesuai kebutuhan, lalu pilih Simpan Semua atau tekan Ctrl/⌘ + Enter. Baris kosong tidak dikirim.</p>
            <details class="mt-3 text-xs text-muted">
                <summary class="w-fit cursor-pointer rounded font-semibold text-primary focus-visible:outline-2 focus-visible:outline-primary">Tempel dari Excel / Google Sheets</summary>
                <p class="mt-2 max-w-prose leading-relaxed">Salin sel dengan urutan Kode, Platform, Funnel, Source, lalu tempel di kolom Kode Promo. Nama platform, funnel, dan source harus sesuai pilihan yang tersedia.</p>
            </details>
            <p class="mt-2 text-xs text-muted">Baris yang belum disimpan tetap tersedia jika panel ditutup dan dibuka kembali pada halaman ini.</p>

            <p x-show="optionsError" class="mt-3 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700" role="alert">
                <span x-text="optionsError"></span>
                <button type="button" class="ml-1 font-semibold underline" @click="fetchOptions()">Coba Lagi</button>
            </p>

            @include('pages.promo.components.promo-code-bulk-summary')
        </div>

        <div class="min-h-0 flex-1 overflow-auto" tabindex="0" role="region" aria-label="Form baris kode promo" :aria-busy="saving.toString()">
            <table class="w-full min-w-[880px] text-left text-sm">
                <thead class="sticky top-0 z-10 bg-soft text-xs font-semibold text-label">
                    <tr>
                        <th scope="col" class="w-12 px-3 py-3 text-center">#</th>
                        <th scope="col" class="sticky left-0 z-10 bg-soft px-2 py-3">Kode Promo <span class="text-red-500" aria-hidden="true">*</span></th>
                        <th scope="col" class="px-2 py-3">Platform <span class="text-red-500" aria-hidden="true">*</span></th>
                        <th scope="col" class="px-2 py-3">Funnel</th>
                        <th scope="col" class="px-2 py-3">Source</th>
                        <th scope="col" class="w-28 px-2 py-3">Status</th>
                        <th scope="col" class="w-12 px-2 py-3"><span class="sr-only">Aksi</span></th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-line">
                    <template x-for="(row, index) in rows" :key="row.key">
                        <tr class="align-top focus-within:bg-primary-soft/60" :class="{ 'bg-emerald-50/60': row.status === 'created', 'bg-red-50/60': row.status === 'failed' }">
                            <td class="px-3 py-2.5 text-center text-xs font-semibold text-label" x-text="index + 1"></td>

                            <td class="sticky left-0 bg-surface px-2 py-2">
                                <input
                                    type="text"
                                    x-model="row.code"
                                    :data-dialog-initial="index === 0 ? '' : null"
                                    @input="clearError(row, 'code')"
                                    @paste="paste($event, index)"
                                    class="{{ $cellClass }} font-mono uppercase"
                                    :class="row.errors.code && 'border-red-500!'"
                                    :disabled="row.status === 'created' || saving"
                                    :aria-label="'Kode promo baris ' + (index + 1)"
                                    :aria-invalid="Boolean(row.errors.code)"
                                    aria-required="true"
                                    :aria-describedby="row.errors.code ? 'promo-bulk-' + row.key + '-code-error' : null"
                                    placeholder="CPNS26"
                                    autocomplete="off"
                                    spellcheck="false"
                                    maxlength="255"
                                >
                                <p :id="'promo-bulk-' + row.key + '-code-error'" x-show="row.errors.code" x-text="row.errors.code" class="{{ $cellError }}"></p>
                            </td>

                            @foreach ($columns as $column)
                                <td class="px-2 py-2">
                                    <select
                                        x-model="row.{{ $column['field'] }}"
                                        @change="clearError(row, '{{ $column['field'] }}')"
                                        class="{{ $cellClass }}"
                                        :class="row.errors.{{ $column['field'] }} && 'border-red-500!'"
                                        :disabled="row.status === 'created' || saving"
                                        :aria-label="'{{ $column['label'] }} baris ' + (index + 1)"
                                        :aria-invalid="Boolean(row.errors.{{ $column['field'] }})"
                                        @if ($column['field'] === 'platform_id') aria-required="true" @endif
                                        :aria-describedby="row.errors.{{ $column['field'] }} ? 'promo-bulk-' + row.key + '-{{ $column['field'] }}-error' : null"
                                    >
                                        <option value="">{{ $column['empty'] }}</option>
                                        <template x-if="row.unmatched.{{ $column['field'] }}">
                                            <option :value="row.{{ $column['field'] }}" x-text="row.unmatched.{{ $column['field'] }} + ' (tidak ditemukan)'" selected></option>
                                        </template>
                                        <template x-for="option in options.{{ $column['options'] }}" :key="option.id">
                                            <option :value="String(option.id)" x-text="option.name" :selected="String(option.id) === row.{{ $column['field'] }}"></option>
                                        </template>
                                    </select>
                                    <p :id="'promo-bulk-' + row.key + '-{{ $column['field'] }}-error'" x-show="row.errors.{{ $column['field'] }}" x-text="row.errors.{{ $column['field'] }}" class="{{ $cellError }}"></p>
                                </td>
                            @endforeach

                            <td class="px-2 py-2.5">
                                <span x-show="row.status === 'created'" class="inline-flex items-center rounded-md bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-700">Tersimpan</span>
                                <span x-show="row.status === 'failed'" class="inline-flex items-center rounded-md bg-red-100 px-2 py-0.5 text-xs font-semibold text-red-700">Gagal</span>
                                <span x-show="!row.status" class="text-xs text-label">—</span>
                            </td>

                            <td class="px-2 py-2 text-right">
                                <button
                                    type="button"
                                    class="inline-grid size-9 place-items-center rounded-md text-label hover:bg-soft hover:text-red-600 focus-visible:outline-2 focus-visible:outline-red-600 disabled:cursor-not-allowed disabled:opacity-40"
                                    :aria-label="'Hapus baris ' + (index + 1)"
                                    title="Hapus baris"
                                    :disabled="saving"
                                    @click="removeRow(index)"
                                >
                                    <x-icon name="close" />
                                </button>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        <div class="flex flex-col gap-3 border-t border-line p-5 lg:flex-row lg:items-center lg:justify-between">
            <div class="flex flex-wrap items-center gap-2">
                <button type="button" class="{{ $secondaryButton }}" :disabled="saving || !canAddRows" @click="addRows(1, true)">Tambah Baris</button>
                <button type="button" class="{{ $secondaryButton }}" :disabled="saving || !canAddRows" @click="addRows(5, true)">Tambah 5 Baris</button>
                <p class="text-xs text-muted">
                    <span class="font-semibold text-ink" x-text="pendingRows.length"></span> baris siap disimpan,
                    maksimal <span x-text="maxRows"></span> baris.
                </p>
            </div>

            <div class="flex flex-col gap-2 lg:items-end">
                <p x-show="formError" x-text="formError" class="text-xs text-red-600" role="alert"></p>
                <div class="flex flex-col-reverse gap-2 sm:flex-row">
                    <button type="button" class="{{ $secondaryButton }}" :disabled="saving" @click="close()">Tutup</button>
                    <button
                        type="button"
                        class="inline-flex h-11 items-center justify-center gap-2 rounded-lg bg-primary px-4 text-sm font-semibold text-white hover:bg-primary-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:cursor-not-allowed disabled:opacity-50"
                        :disabled="saving || !pendingRows.length || (optionsLoading && !options.platforms.length)"
                        @click="submit()"
                    >
                        <span x-text="saving ? 'Menyimpan…' : 'Simpan Semua'">Simpan Semua</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
