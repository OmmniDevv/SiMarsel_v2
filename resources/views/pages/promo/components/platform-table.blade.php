{{-- Read-only: platforms are managed in the global platform master, so there are no create/edit/delete actions here. --}}
<section x-data="promoPlatformTable" class="{{ $card }} min-w-0 p-5" aria-labelledby="platform-list-title">
    <div class="flex flex-wrap items-center gap-2">
        <h2 id="platform-list-title" class="text-base font-semibold text-ink">Daftar Platform</h2>
        <p x-show="!loading && !loadError" x-cloak class="text-xs tabular-nums text-muted" aria-live="polite"><span class="font-semibold text-ink" x-text="platforms.length"></span> platform</p>
        <span class="inline-flex items-center gap-1 rounded-md bg-soft px-2 py-0.5 text-xs font-semibold text-muted">
            <x-icon name="lock" class="size-3" />
            Baca Saja
        </span>
    </div>
    <p class="mt-1 text-sm text-muted">Data dikelola secara terpusat dari sistem utama.</p>

    <div class="mt-4 overflow-hidden rounded-lg border border-line">
        <div class="overflow-x-auto">
            <table class="w-full table-fixed text-left text-sm" :aria-busy="loading.toString()">
                <caption class="sr-only">Daftar platform dan slug dari master global, baca-saja</caption>
                <thead class="bg-soft text-xs font-semibold text-label">
                    <tr>
                        <th scope="col" class="px-4 py-3">Nama Platform</th>
                        <th scope="col" class="px-4 py-3">Slug</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-line">
                    <template x-for="platform in platforms" :key="platform.id">
                        <tr x-show="!loading && !loadError" class="hover:bg-soft/60">
                            <td class="px-4 py-3 font-semibold break-words text-ink" x-text="platform.name"></td>
                            <td class="px-4 py-3">
                                <span class="font-mono text-xs break-all text-muted" x-text="platform.slug"></span>
                            </td>
                        </tr>
                    </template>

                    <tr x-show="loading" x-cloak>
                        <td colspan="2" class="px-4 py-10 text-center text-sm text-muted">Memuat daftar platform…</td>
                    </tr>

                    <tr x-show="!loading && loadError" x-cloak>
                        <td colspan="2" class="px-4 py-10 text-center">
                            <p class="text-sm font-medium text-red-600" x-text="loadError"></p>
                            <button type="button" class="mt-2 text-xs font-semibold text-primary hover:text-primary-hover" @click="fetchPlatforms()">Coba Lagi</button>
                        </td>
                    </tr>

                    <tr x-show="!loading && !loadError && platforms.length === 0" x-cloak>
                        <td colspan="2" class="px-4 py-10 text-center text-sm font-medium text-ink">Belum ada platform di master platform global.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
    <p class="mt-4 text-xs text-muted">Slug adalah pengenal platform untuk integrasi data. Hubungi pengelola sistem jika daftar perlu diperbarui.</p>
</section>
