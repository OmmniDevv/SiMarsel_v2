@php
    $card = 'rounded-panel border border-line bg-surface shadow-sm';

    // Every role can read the account list; only Manager can create, edit, and delete.
    $canManageAccounts = auth()->user()->can('create', App\Models\Account::class);
@endphp

<div
    x-data="accountSettings(@js($roles), @js($divisions), @js($canManageAccounts), tab === 'accounts')"
    @organization-tab-changed.window="activate($event.detail)"
    class="min-w-0"
>
        <section class="{{ $card }} mt-6 overflow-hidden" aria-label="Ringkasan Akun" :aria-busy="loading">
            <div class="flex flex-wrap items-center gap-x-5 gap-y-2 bg-soft px-5 py-5 sm:px-6">
                <div class="min-w-0 flex-1">
                    <h2 id="account-total-title" class="text-lg font-semibold text-navy">Total Akun</h2>
                    <p class="mt-1 text-sm text-muted">Seluruh akun terdaftar.</p>
                </div>
                <p aria-labelledby="account-total-title" class="text-4xl font-bold text-navy tabular-nums" :class="loadError && 'text-base!'" x-text="loading ? '—' : loadError ? 'Tidak tersedia' : accountSummary.total">—</p>
            </div>
            <div class="border-t border-line px-5 py-4 sm:px-6">
                <h3 class="mb-3 text-sm font-semibold text-ink">Rincian berdasarkan role</h3>
                <dl class="grid grid-cols-2 gap-x-8 gap-y-4 sm:grid-cols-3 xl:grid-cols-5">
                    @foreach ($roles as $role)
                        <div class="flex min-w-0 items-baseline justify-between gap-3">
                            <dt class="text-sm text-muted wrap-anywhere">{{ $role->name }}</dt>
                            <dd class="text-lg font-semibold text-ink tabular-nums" x-text="loading ? '—' : loadError ? '—' : (accountSummary.roleCounts[@js($role->code_role)] ?? 0)">—</dd>
                        </div>
                    @endforeach
                </dl>
            </div>
        </section>

        <section class="{{ $card }} mt-5 p-5 sm:p-6" aria-labelledby="account-list-title">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h2 id="account-list-title" class="text-lg font-semibold text-ink">Daftar Akun</h2>
                    <p class="mt-1 text-sm text-muted">
                        {{ $canManageAccounts ? 'Kelola identitas, role, dan divisi anggota tim.' : 'Cari akun anggota tim dan saring berdasarkan divisi.' }}
                    </p>
                </div>

                @if ($canManageAccounts)
                    <button
                        type="button"
                        class="inline-flex h-11 shrink-0 items-center justify-center gap-2 self-start rounded-lg bg-primary-hover px-4 text-sm font-semibold text-white hover:bg-navy focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:cursor-not-allowed disabled:opacity-50"
                        x-ref="accountCreateButton" @click="openCreate()"
                    >
                        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14" /></svg>
                        Tambah Akun
                    </button>
                @endif
            </div>

            @include('pages.settings.components.account-filter')
            @include('pages.settings.components.account-table', ['canManage' => $canManageAccounts])
        </section>

        @if ($canManageAccounts)
            @include('pages.settings.components.account-create-modal')
            @include('pages.settings.components.account-edit-modal')
            @include('pages.settings.components.account-delete-modal')
            @include('pages.settings.components.account-discard-modal')
        @endif
</div>
