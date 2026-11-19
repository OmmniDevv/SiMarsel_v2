<div class="mt-4">
    <p x-show="loading" role="status" class="py-8 text-center text-sm text-muted">Memuat daftar akun…</p>
    <div x-show="!loading && loadError" role="alert" class="rounded-lg border border-line px-4 py-8 text-center">
        <p class="text-sm font-medium text-red-600" x-text="loadError"></p>
        <button type="button" class="mt-2 inline-flex min-h-11 items-center rounded-md px-3 text-sm font-semibold text-primary-hover hover:text-navy focus-visible:outline-2 focus-visible:outline-primary-hover" @click="fetchUsers()">Coba Lagi</button>
    </div>
    <div x-show="!loading && !loadError && filteredUsers.length === 0" class="rounded-lg border border-line px-4 py-8 text-center">
        <p class="text-sm font-medium text-ink" x-text="users.length ? 'Tidak ada akun yang cocok.' : 'Belum ada akun terdaftar.'"></p>
        <p class="mt-1 text-sm text-muted" x-text="users.length ? 'Coba kata kunci lain atau ubah filter divisi.' : 'Akun anggota tim akan ditampilkan di sini.'"></p>
        <button type="button" x-show="search || filterDivision" class="mt-2 inline-flex min-h-11 items-center rounded-md px-3 text-sm font-semibold text-primary-hover hover:text-navy focus-visible:outline-2 focus-visible:outline-primary-hover" @click="resetFilters()">Atur Ulang Filter</button>
        @if ($canManage)
            <button type="button" x-show="!users.length" class="mt-2 inline-flex min-h-11 items-center rounded-md px-3 text-sm font-semibold text-primary-hover hover:text-navy focus-visible:outline-2 focus-visible:outline-primary-hover" @click="openCreate()">Tambah Akun</button>
        @endif
    </div>
</div>
<ul x-show="!loading && !loadError && filteredUsers.length" class="mt-4 divide-y divide-line border-y border-line lg:hidden" aria-label="Daftar akun">
    <template x-for="user in filteredUsers" :key="user.id">
        <li class="space-y-3 py-4">
            <div class="min-w-0 wrap-anywhere">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="font-semibold text-ink" x-text="user.fullname"></span>
                    <span x-show="isSelf(user)" class="rounded-md bg-primary-soft px-2 py-0.5 text-xs font-semibold text-primary-hover">Anda</span>
                </div>
                <p class="mt-1 text-sm text-muted" x-text="'@' + user.username"></p>
            </div>
            <dl class="grid grid-cols-2 gap-4 text-sm">
                <div class="min-w-0 wrap-anywhere"><dt class="text-xs text-muted">Role</dt><dd class="mt-1 text-ink" x-text="user.role.name"></dd></div>
                <div class="min-w-0 wrap-anywhere"><dt class="text-xs text-muted">Divisi</dt><dd class="mt-1 text-ink" x-text="user.division?.name ?? 'Tanpa Divisi'"></dd></div>
            </dl>
            @if ($canManage)
                <div class="flex justify-end">
                    @include('pages.settings.components.account-actions')
                </div>
            @endif
        </li>
    </template>
</ul>
@php
    $columns = $canManage ? 4 : 3;
@endphp

<div x-show="!loading && !loadError && filteredUsers.length" class="mt-4 hidden overflow-hidden rounded-lg border border-line lg:block">
    <div class="overflow-x-auto">
        <table class="w-full min-w-[640px] text-left text-sm">
            <thead class="bg-soft text-xs font-semibold text-muted">
                <tr>
                    <th scope="col" class="px-4 py-3">Nama Lengkap</th>
                    <th scope="col" class="px-4 py-3">Divisi</th>
                    <th scope="col" class="px-4 py-3">Role</th>
                    @if ($canManage)
                        <th scope="col" class="px-4 py-3 text-right">Aksi</th>
                    @endif
                </tr>
            </thead>

            <tbody class="divide-y divide-line">
                <template x-for="user in filteredUsers" :key="user.id">
                    <tr class="hover:bg-soft/60">
                        <td class="max-w-sm px-4 py-3 wrap-anywhere">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-semibold text-ink" x-text="user.fullname"></span>
                                <span
                                    x-show="isSelf(user)"
                                    class="inline-flex items-center rounded-md bg-primary-soft px-2 py-0.5 text-xs font-semibold text-primary-hover"
                                >Anda</span>
                            </div>
                            <span class="text-xs text-muted" x-text="'@' + user.username"></span>
                        </td>
                        <td class="px-4 py-3">
                            <span x-show="user.division" class="inline-flex items-center rounded-md bg-primary-soft px-2 py-0.5 text-xs font-semibold text-primary-hover" x-text="user.division?.name"></span>
                            <span x-show="!user.division" class="inline-flex items-center rounded-md bg-soft px-2 py-0.5 text-xs font-semibold text-muted">Tanpa Divisi</span>
                        </td>
                        <td class="px-4 py-3 text-muted" x-text="user.role.name"></td>
                        @if ($canManage)
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            @include('pages.settings.components.account-actions')
                        </td>
                        @endif
                    </tr>
                </template>
            </tbody>
        </table>
    </div>
</div>
