<div class="mt-4">
    <p x-show="loading" role="status" class="py-8 text-center text-sm text-muted">Memuat daftar divisi…</p>
    <div x-show="!loading && loadError" role="alert" class="rounded-lg border border-line px-4 py-8 text-center">
        <p class="text-sm font-medium text-red-600" x-text="loadError"></p>
        <button type="button" class="mt-2 inline-flex min-h-11 items-center rounded-md px-3 text-sm font-semibold text-primary-hover hover:text-navy focus-visible:outline-2 focus-visible:outline-primary-hover" @click="fetchDivisions()">Coba Lagi</button>
    </div>
    <div x-show="!loading && !loadError && !filteredDivisions.length" class="rounded-lg border border-line px-4 py-8 text-center">
        <p class="text-sm font-medium text-ink" x-text="divisions.length ? 'Tidak ada divisi yang cocok.' : 'Belum ada divisi.'"></p>
        <p class="mt-1 text-sm text-muted" x-text="divisions.length ? 'Coba kata kunci lain atau atur ulang pencarian.' : 'Divisi yang terdaftar akan ditampilkan di sini.'"></p>
        @if ($canManage)
            <button type="button" x-show="!divisions.length" class="mt-2 inline-flex min-h-11 items-center rounded-md px-3 text-sm font-semibold text-primary-hover hover:text-navy focus-visible:outline-2 focus-visible:outline-primary-hover" @click="openCreate()">Tambah Divisi Pertama</button>
        @endif
        <button type="button" x-show="divisions.length && search" class="mt-2 inline-flex min-h-11 items-center rounded-md px-3 text-sm font-semibold text-primary-hover hover:text-navy focus-visible:outline-2 focus-visible:outline-primary-hover" @click="search = ''">Atur Ulang Pencarian</button>
    </div>
</div>
<ul x-show="!loading && !loadError && filteredDivisions.length" class="mt-4 divide-y divide-line border-y border-line lg:hidden" aria-label="Daftar divisi">
    <template x-for="division in filteredDivisions" :key="division.id">
        <li class="space-y-3 py-4">
            <div class="min-w-0 wrap-anywhere">
                <p class="font-semibold text-ink" x-text="division.name"></p>
                <p class="mt-1 text-sm text-muted" x-text="division.code_division"></p>
            </div>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <p class="text-sm text-muted" x-text="division.accounts_count ? division.accounts_count + ' akun' : 'Belum ada anggota'"></p>
                @if ($canManage)
                    @include('pages.settings.components.division-actions')
                @endif
            </div>
        </li>
    </template>
</ul>
@php
    $columns = $canManage ? 4 : 3;
@endphp

<div x-show="!loading && !loadError && filteredDivisions.length" class="mt-4 hidden overflow-hidden rounded-lg border border-line lg:block">
    <div class="overflow-x-auto">
        <table class="w-full min-w-[560px] text-left text-sm">
            <thead class="bg-soft text-xs font-semibold text-muted">
                <tr>
                    <th scope="col" class="px-4 py-3">Kode</th>
                    <th scope="col" class="px-4 py-3">Nama Divisi</th>
                    <th scope="col" class="px-4 py-3">Anggota</th>
                    @if ($canManage)
                        <th scope="col" class="px-4 py-3 text-right">Aksi</th>
                    @endif
                </tr>
            </thead>

            <tbody class="divide-y divide-line">
                <template x-for="division in filteredDivisions" :key="division.id">
                    <tr class="hover:bg-soft/60">
                        <td class="max-w-xs px-4 py-3 wrap-anywhere">
                            <span class="inline-flex items-center rounded-md bg-primary-soft px-2 py-0.5 text-xs font-semibold text-primary-hover" x-text="division.code_division"></span>
                        </td>
                        <td class="max-w-sm px-4 py-3 font-semibold text-ink wrap-anywhere" x-text="division.name"></td>
                        <td class="px-4 py-3">
                            <span x-show="division.accounts_count" class="text-muted" x-text="division.accounts_count + ' akun'"></span>
                            <span x-show="!division.accounts_count" class="inline-flex items-center rounded-md bg-soft px-2 py-0.5 text-xs font-semibold text-muted">Belum ada anggota</span>
                        </td>
                        @if ($canManage)
                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                @include('pages.settings.components.division-actions')
                            </td>
                        @endif
                    </tr>
                </template>

            </tbody>
        </table>
    </div>
</div>
