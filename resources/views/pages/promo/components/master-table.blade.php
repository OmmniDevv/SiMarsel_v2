{{-- CRUD table for one promo master. Variables: $type (funnel|source), $label, $description, $placeholder. --}}
@php
    $noun = strtolower($label);
@endphp

<section x-data="promoMasterTable(@js($type), @js($label))" class="{{ $card }} min-w-0 p-5" aria-labelledby="{{ $type }}-list-title">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
            <h2 id="{{ $type }}-list-title" class="text-base font-semibold text-ink">Daftar {{ $label }}</h2>
            <p class="mt-1 text-sm text-muted">{{ $description }}</p>
            <p x-show="!loading && !loadError" x-cloak class="mt-2 text-xs tabular-nums text-muted" aria-live="polite">
                <span class="font-semibold text-ink" x-text="filteredItems.length"></span><span x-show="search.trim()"> dari <span x-text="items.length"></span></span> {{ $noun }}
            </p>
        </div>

        <button
            type="button"
            class="inline-flex h-11 w-full shrink-0 items-center justify-center gap-2 rounded-lg bg-primary px-4 text-sm font-semibold text-white hover:bg-primary-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary sm:w-auto"
            @click="openCreate()"
        >
            <x-icon name="plus" />
            Tambah {{ $label }}
        </button>
    </div>

    <div class="mt-5 flex flex-col gap-3 border-t border-line pt-4 sm:flex-row sm:items-end">
        <div class="min-w-0 flex-1">
            <label for="{{ $type }}-search" class="mb-1.5 block text-xs font-semibold text-label">Cari {{ $label }}</label>
            <div class="relative">
                <svg class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-label" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true">
                    <circle cx="11" cy="11" r="7" />
                    <path d="M20 20l-3.5-3.5" />
                </svg>
                <input
                    id="{{ $type }}-search"
                    type="search"
                    x-model="search"
                    placeholder="Cari nama {{ $noun }}…"
                    class="h-10 w-full rounded-lg border border-line bg-white pr-3 pl-9 text-sm text-ink placeholder:text-label focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none"
                >
            </div>
        </div>

        <button type="button" x-show="search.trim()" x-cloak @click="search = ''" class="inline-flex h-10 shrink-0 items-center justify-center rounded-lg border border-line px-3 text-sm font-semibold text-ink hover:bg-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">Atur ulang pencarian</button>
    </div>

    <div class="mt-4 overflow-hidden rounded-lg border border-line">
        <div class="overflow-x-auto focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary" tabindex="0" role="region" aria-label="Daftar {{ $label }}">
            <table class="w-full min-w-[420px] text-left text-sm" :aria-busy="loading.toString()">
                <caption class="sr-only">Daftar {{ $label }} dan jumlah kode promo yang terkait saat ini</caption>
                <thead class="bg-soft text-xs font-semibold text-label">
                    <tr>
                        <th scope="col" class="px-4 py-3">Nama {{ $label }}</th>
                        <th scope="col" class="px-4 py-3 text-right">Kode promo terkait</th>
                        <th scope="col" class="px-4 py-3 text-right">Aksi</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-line">
                    <template x-for="item in filteredItems" :key="item.id">
                        <tr x-show="!loading && !loadError" class="hover:bg-soft/60">
                            <td class="px-4 py-3 font-semibold break-all text-ink" x-text="item.name"></td>
                            <td class="px-4 py-3 text-right tabular-nums text-muted" x-text="item.promo_codes_count + ' kode promo'"></td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                @include('pages.promo.components.row-actions', ['identifier' => 'item.name'])
                            </td>
                        </tr>
                    </template>

                    <tr x-show="loading" x-cloak>
                        <td colspan="3" class="px-4 py-10 text-center text-sm text-muted">Memuat daftar {{ $noun }}…</td>
                    </tr>

                    <tr x-show="!loading && loadError" x-cloak>
                        <td colspan="3" class="px-4 py-10 text-center">
                            <p class="text-sm font-medium text-red-600" x-text="loadError"></p>
                            <button type="button" class="mt-2 text-xs font-semibold text-primary hover:text-primary-hover" @click="fetchItems()">Coba Lagi</button>
                        </td>
                    </tr>

                    <tr x-show="!loading && !loadError && items.length === 0" x-cloak>
                        <td colspan="3" class="px-4 py-10 text-center">
                            <p class="text-sm font-medium text-ink">Belum ada {{ $noun }}.</p>
                            <button type="button" class="mt-2 text-xs font-semibold text-primary hover:text-primary-hover" @click="openCreate()">Tambah {{ $label }} Pertama</button>
                        </td>
                    </tr>

                    <tr x-show="!loading && !loadError && items.length > 0 && filteredItems.length === 0" x-cloak>
                        <td colspan="3" class="px-4 py-10 text-center">
                            <p class="text-sm font-medium text-ink">Tidak ada {{ $noun }} yang cocok.</p>
                            <p class="mt-1 text-xs text-muted">Coba ubah kata kunci pencarian.</p>
                            <button type="button" class="mt-2 text-xs font-semibold text-primary hover:text-primary-hover" @click="search = ''">Atur Ulang Pencarian</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    @include('pages.promo.components.master-form-modal')
    @include('pages.promo.components.master-delete-modal')
</section>
