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
        <section class="{{ $card }} mt-6 px-5 py-4" aria-label="Ringkasan Akun" :aria-busy="loading">
            <dl class="grid grid-cols-2 gap-x-6 gap-y-4 sm:grid-cols-3 xl:flex xl:items-center xl:justify-between">
                <div class="col-span-2 border-b border-line pb-4 sm:col-span-1 sm:border-b-0 sm:pb-0 xl:border-r xl:pr-8">
                    <dt class="text-xs font-semibold text-muted">Total Akun</dt>
                    <dd class="mt-1 text-xl font-bold text-ink tabular-nums" x-text="loading ? '—' : loadError ? 'Tidak tersedia' : accountSummary.total">—</dd>
                </div>
                @foreach ($roles as $role)
                    <div class="min-w-0">
                        <dt class="text-xs font-medium text-muted">{{ $role->name }}</dt>
                        <dd class="mt-1 text-lg font-semibold text-ink tabular-nums" x-text="loading ? '—' : loadError ? '—' : (accountSummary.roleCounts[@js($role->code_role)] ?? 0)">—</dd>
                    </div>
                @endforeach
            </dl>
        </section>

        <section class="{{ $card }} mt-6 p-5" aria-labelledby="account-list-title">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h2 id="account-list-title" class="text-base font-semibold text-ink">Daftar Akun</h2>
                    <p class="mt-1 text-sm text-muted">
                        {{ $canManageAccounts ? 'Tambah, ubah, atau hapus akun anggota tim beserta role dan divisinya.' : 'Cari akun anggota tim dan saring berdasarkan divisi.' }}
                    </p>
                </div>

                @if ($canManageAccounts)
                    <button
                        type="button"
                        class="inline-flex h-9 shrink-0 items-center justify-center gap-2 self-start rounded-lg bg-primary-hover px-4 text-sm font-semibold text-white hover:bg-navy focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:cursor-not-allowed disabled:opacity-50"
                        x-ref="accountCreateButton" @click="openCreate()"
                    >
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
        @endif
</div>
