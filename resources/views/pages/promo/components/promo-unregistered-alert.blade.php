{{-- Transaction codes without a master for their platform. Search never changes the pending total. --}}
@php
    $pageButton = 'inline-flex h-10 items-center justify-center gap-1 rounded-lg border border-line bg-white px-3 text-xs font-semibold text-ink hover:bg-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:cursor-not-allowed disabled:opacity-50';
@endphp

<div x-data="promoUnregisteredCodes" @promo-codes-changed.window="refresh()" x-show="visible || totalError || reviewOpen" x-cloak class="min-w-0">
    <p x-show="totalError && !reviewOpen" x-cloak class="mb-3 flex flex-wrap items-center gap-3 rounded-lg border border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-700" role="alert">
        <span x-text="totalError"></span>
        <button type="button" class="font-semibold underline hover:no-underline" @click="refresh()">Coba Lagi</button>
    </p>

    <section x-show="visible" x-cloak class="{{ $card }} min-w-0" aria-labelledby="unregistered-title">
        <div class="flex flex-col gap-3 p-5 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex min-w-0 gap-3">
                <x-icon name="alert" class="mt-0.5 size-5 text-amber-700" />
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 id="unregistered-title" class="text-base font-semibold text-ink">Kode transaksi belum terdaftar</h2>
                        <span class="rounded-md bg-amber-100 px-2 py-0.5 text-xs font-semibold tabular-nums text-amber-900" x-text="pendingTotal + ' perlu ditinjau'" aria-live="polite"></span>
                    </div>
                    <p class="mt-1 text-sm text-muted">Ada kode pada transaksi yang belum terdaftar pada sistem. Silakan daftarkan kode tersebut agar dapat dianalisis.</p>
</p>
                </div>
            </div>

            <button
                type="button"
                class="inline-flex h-11 w-full shrink-0 items-center justify-center gap-2 rounded-lg border border-line bg-white px-4 text-sm font-semibold text-ink hover:bg-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary sm:w-auto"
                aria-controls="unregistered-review"
                :aria-expanded="reviewOpen.toString()"
                @click="toggleReview()"
            >
                <span x-text="reviewOpen ? 'Tutup tinjauan' : 'Tinjau kode'">Tinjau kode</span>
                <x-icon name="chevron-down" class="size-4" ::class="reviewOpen && 'rotate-180'" />
            </button>
        </div>
    </section>
    <div
        id="unregistered-review"
        x-dialog="reviewOpen"
        x-show="reviewOpen"
        x-cloak
        class="fixed inset-0 z-50 flex justify-end"
        role="dialog"
        aria-modal="true"
        aria-labelledby="unregistered-review-title"
        aria-label="Daftar kode transaksi belum terdaftar"
        @keydown.escape.stop="closeReview()"
    >
        <div class="absolute inset-0 bg-slate-900/50" aria-hidden="true" @click="closeReview()"></div>
        <section class="relative flex h-dvh w-full min-w-0 flex-col bg-surface shadow-xl lg:max-w-3xl">
            <header class="flex shrink-0 items-start justify-between gap-3 border-b border-line p-5">
                <div class="min-w-0">
                    <h2 id="unregistered-review-title" class="text-lg font-semibold text-ink">Tinjau kode transaksi</h2>
                    <p class="mt-1 text-sm text-muted">Daftarkan kode sesuai platform asal transaksi.</p>
                </div>
                <button type="button" @click="closeReview()" class="inline-flex size-11 shrink-0 items-center justify-center rounded-lg border border-line text-ink hover:bg-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary" aria-label="Tutup tinjauan">
                    <x-icon name="close" class="size-5" />
                </button>
            </header>
            <div class="min-h-0 flex-1 overflow-y-auto overscroll-contain p-5">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                    <div class="min-w-0 flex-1 sm:max-w-md">
                        <label for="unregistered-search" class="mb-1.5 block text-xs font-semibold text-label">Cari kode belum terdaftar</label>
                        <div class="relative">
                            <x-icon name="search" class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-label" />
                            <input id="unregistered-search" data-dialog-initial type="search" x-model.debounce.400ms="filter.search" placeholder="Kode atau platform…" maxlength="100" class="h-10 w-full rounded-lg border border-line bg-white pr-3 pl-9 text-sm text-ink placeholder:text-label focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none">
                        </div>
                    </div>
                    <p class="text-sm tabular-nums text-muted sm:pb-2.5" aria-live="polite" x-show="meta" x-cloak><span class="font-semibold text-ink" x-text="total"></span> hasil tinjauan</p>
                </div>

                <p x-show="totalError && !loadError" x-cloak class="mt-4 flex flex-wrap items-center gap-3 rounded-lg border border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-700" role="alert">
                    <span x-text="totalError"></span>
                    <button type="button" class="min-h-11 font-semibold underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary" @click="fetchPendingTotal()">Periksa ulang jumlah kode</button>
                </p>

                <p x-show="loading && !items.length" x-cloak class="py-8 text-center text-sm text-muted" role="status">Memeriksa kode promo yang belum terdaftar…</p>
                <div x-show="!loading && loadError" x-cloak class="py-8 text-center" role="alert">
                    <p class="text-sm font-medium text-red-600" x-text="loadError"></p>
                    <button type="button" class="mt-2 rounded-sm text-sm font-semibold text-primary underline focus-visible:outline-2 focus-visible:outline-primary" @click="fetchItems()">Coba Lagi</button>
                </div>
                <div x-show="!loading && !loadError && !items.length && hasFilter" x-cloak class="py-8 text-center" role="status">
                    <p class="text-sm font-medium text-ink">Tidak ada kode belum terdaftar yang cocok.</p>
                    <button type="button" class="mt-2 rounded-sm text-sm font-semibold text-primary underline focus-visible:outline-2 focus-visible:outline-primary" @click="filter.search = ''">Atur Ulang Pencarian</button>
                </div>

                <ul x-show="items.length && !loadError" class="mt-4 divide-y divide-line border-y border-line" :class="loading && 'opacity-50'" :aria-busy="loading.toString()" aria-label="Kode transaksi untuk ditinjau">
                    <template x-for="item in items" :key="item.platform.id + ':' + item.code">
                        <li class="py-4">
                            <div class="flex min-w-0 flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                <div class="min-w-0 flex-1">
                                    <div class="flex min-w-0 flex-wrap items-baseline gap-x-3 gap-y-1">
                                        <h3 class="min-w-0 font-mono text-sm font-semibold break-all text-ink" x-text="item.code"></h3>
                                        <p class="min-w-0 text-sm break-words text-muted"><span class="sr-only">Platform: </span><span x-text="item.platform.name"></span></p>
                                    </div>
                                    <dl class="mt-2 flex flex-wrap gap-x-5 gap-y-1 text-xs text-muted">
                                        <div class="flex flex-wrap gap-x-1">
                                            <dt class="sr-only">Penggunaan</dt>
                                            <dd class="tabular-nums" x-text="item.usages_count + ' penggunaan'"></dd>
                                            <dt class="sr-only">Transaksi</dt>
                                            <dd class="tabular-nums" x-text="'· ' + item.transactions_count + ' transaksi'"></dd>
                                        </div>
                                        <div class="flex flex-wrap gap-x-1">
                                            <dt>Terakhir dipakai:</dt>
                                            <dd x-text="formatUsedOn(item.last_used_on)"></dd>
                                        </div>
                                        <div x-show="item.first_used_on && item.first_used_on !== item.last_used_on" class="flex flex-wrap gap-x-1">
                                            <dt>Sejak:</dt>
                                            <dd x-text="formatUsedOn(item.first_used_on)"></dd>
                                        </div>
                                    </dl>
                                    <details class="mt-2 text-xs text-muted">
                                        <summary class="w-fit cursor-pointer rounded-sm py-1 font-semibold focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">Variasi kode asli<span class="sr-only" x-text="' ' + item.code + ' di ' + item.platform.name"></span></summary>
                                        <dl class="mt-2 grid gap-2">
                                            <div>
                                                <dt>Kode asli</dt>
                                                <dd class="mt-1 flex flex-wrap gap-1" title="Penulisan asli dari transaksi Markaz">
                                                    <template x-for="raw in item.raw_codes" :key="raw">
                                                        <span class="rounded-md bg-soft px-1.5 py-0.5 font-mono text-xs font-semibold break-all whitespace-pre-wrap text-ink" x-text="raw"></span>
                                                    </template>
                                                </dd>
                                            </div>
                                            <div class="flex min-w-0 flex-wrap gap-x-1">
                                                <dt>Didaftarkan sebagai:</dt>
                                                <dd class="min-w-0 font-mono font-semibold break-all text-ink" x-text="item.register.code"></dd>
                                            </div>
                                        </dl>
                                    </details>
                                </div>
                                <button type="button" class="inline-flex min-h-11 w-full shrink-0 items-center justify-center rounded-lg border border-line bg-white px-4 text-sm font-semibold text-ink hover:bg-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary sm:w-auto" :aria-label="'Daftarkan kode ' + item.code + ' ' + item.platform.name" @click="register(item)">Daftarkan</button>
                            </div>
                        </li>
                    </template>
                </ul>
                <p x-show="!loading && !loadError && !items.length && !hasFilter" x-cloak class="py-8 text-center text-sm text-muted" role="status">Semua kode transaksi sudah terdaftar.</p>
                <details class="mt-5 text-sm text-muted">
                    <summary class="w-fit cursor-pointer rounded-sm font-semibold text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">Tentang pemetaan transaksi</summary>
                    <p class="mt-2 max-w-prose text-xs leading-relaxed">Transaksi dengan kode ini tetap tersimpan dan tetap dihitung dalam analisis. Mendaftarkan kode dari alert juga dapat melengkapi
                    penggunaan historis yang sebelumnya belum terpetakan dengan funnel/source yang didaftarkan.
                    Hanya penggunaan yang belum terhubung ke kode promo yang diproses; penggunaan yang sudah terhubung tidak diproses ulang meskipun funnel/source-nya kosong. Klasifikasi Funnel/Source yang sudah ada tidak akan ditimpa.</p>
                </details>


            </div>
            <nav x-show="meta" x-cloak class="shrink-0 border-t border-line px-5 py-4" aria-label="Navigasi halaman kode belum terdaftar">
                <p class="mb-3 text-xs text-muted">
                    Menampilkan <span class="font-semibold text-ink" x-text="meta?.from ?? 0"></span>–<span class="font-semibold text-ink" x-text="meta?.to ?? 0"></span>
                    dari <span x-text="meta?.total ?? 0"></span> kode
                </p>

                <div class="flex flex-wrap items-center gap-2">
                    <button type="button" class="{{ $pageButton }}" :disabled="loading || filter.page <= 1" @click="goToPage(filter.page - 1)">
                        <x-icon name="chevron-left" class="size-3.5" />
                        Sebelumnya
                    </button>
                    <span class="text-xs text-muted">
                        Halaman <span class="font-semibold text-ink" x-text="meta?.current_page"></span> dari <span x-text="meta?.last_page"></span>
                    </span>
                    <button type="button" class="{{ $pageButton }}" :disabled="loading || filter.page >= (meta?.last_page ?? 1)" @click="goToPage(filter.page + 1)">
                        Berikutnya
                        <x-icon name="chevron-right" class="size-3.5" />
                    </button>
                </div>
            </nav>
        </section>
    </div>
</div>
