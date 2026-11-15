{{-- Keep transaction review separate from the registered collection and its create actions. --}}
@php
    $button = 'inline-flex h-11 w-full items-center justify-center gap-2 rounded-lg px-4 text-sm font-semibold focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary sm:w-auto';
@endphp

<div x-data="promoCodeManagement" @promo-codes-changed.window="reload()" @promo-code-register.window="openRegister($event.detail)" class="mt-6 grid min-w-0 gap-4">
    @include('pages.promo.components.promo-unregistered-alert')

    <section class="{{ $card }} min-w-0 p-5" aria-labelledby="promo-list-title">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div>
                <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                    <h2 id="promo-list-title" class="text-base font-semibold text-ink">Kode promo terdaftar</h2>
                    <p x-show="meta" x-cloak class="text-sm tabular-nums text-muted" aria-live="polite">(<span x-text="meta?.total ?? 0"></span><span x-show="hasFilter"> hasil filter</span>)</p>
                </div>
                <p class="mt-1 text-sm text-muted">Petakan setiap kode promo ke platform, funnel, dan source yang tepat.</p>
            </div>

            <div class="flex shrink-0 flex-col gap-2 sm:flex-row">
                <button type="button" class="{{ $button }} bg-primary text-white hover:bg-primary-hover" @click="openCreate()">
                    <x-icon name="plus" />
                    Tambah kode promo
                </button>
                <button type="button" class="{{ $button }} border border-line bg-white text-ink hover:bg-soft" @click="$dispatch('promo-bulk-open')">
                    <x-icon name="table" />
                    Input massal
                </button>
            </div>
        </div>

        @include('pages.promo.components.promo-code-filter')
        @include('pages.promo.components.promo-code-table')

        @include('pages.promo.components.promo-code-form-modal')
        @include('pages.promo.components.promo-code-confirm-modal')
        @include('pages.promo.components.promo-code-delete-modal')
    </section>

    @include('pages.promo.components.promo-code-bulk-modal')
</div>
