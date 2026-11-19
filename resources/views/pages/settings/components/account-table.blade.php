@php
    $columns = $canManage ? 4 : 3;
@endphp

<div class="mt-4 overflow-hidden rounded-lg border border-line">
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
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2">
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
                            <div class="inline-grid w-32 grid-cols-2 items-center gap-2 align-middle">
                                <button
                                    type="button"
                                    class="inline-flex h-9 items-center justify-center rounded-md px-2 font-semibold text-primary-hover hover:text-navy focus-visible:outline-2 focus-visible:outline-primary"
                                    :aria-label="'Ubah akun ' + user.fullname + ' (@' + user.username + ')'"
                                    @click="openEdit(user)"
                                >Ubah</button>
                                <div class="min-w-0">
                                    <button
                                        type="button"
                                        x-show="!isSelf(user)"
                                        class="inline-flex h-9 w-full items-center justify-center rounded-md px-2 font-semibold text-red-600 hover:text-red-700 focus-visible:outline-2 focus-visible:outline-red-600"
                                        :aria-label="'Hapus akun ' + user.fullname + ' (@' + user.username + ')'"
                                        @click="openDelete(user)"
                                    >Hapus</button>
                                    <span x-show="isSelf(user)" class="inline-flex h-9 w-full items-center justify-center text-muted" title="Anda tidak dapat menghapus akun sendiri">
                                        <span aria-hidden="true">—</span>
                                        <span class="sr-only">Anda tidak dapat menghapus akun sendiri.</span>
                                    </span>
                                </div>
                            </div>
                        </td>
                        @endif
                    </tr>
                </template>

                <tr x-show="loading" x-cloak>
                    <td colspan="{{ $columns }}" class="px-4 py-10 text-center text-sm text-muted">Memuat daftar akun…</td>
                </tr>

                <tr x-show="!loading && loadError" x-cloak>
                    <td colspan="{{ $columns }}" class="px-4 py-10 text-center">
                        <p class="text-sm font-medium text-red-600" x-text="loadError"></p>
                        <button type="button" class="mt-2 text-xs font-semibold text-primary-hover hover:text-navy" @click="fetchUsers()">Coba Lagi</button>
                    </td>
                </tr>

                <tr x-show="!loading && !loadError && filteredUsers.length === 0" x-cloak>
                    <td colspan="{{ $columns }}" class="px-4 py-10 text-center">
                        <p class="text-sm font-medium text-ink" x-text="users.length ? 'Tidak ada akun yang cocok.' : 'Belum ada akun terdaftar.'"></p>
                        <p class="mt-1 text-xs text-muted" x-text="users.length ? 'Coba ubah kata kunci atau pilih divisi lain.' : 'Akun anggota tim akan ditampilkan di sini.'"></p>
                        <button type="button" x-show="search || filterDivision" class="mt-2 text-xs font-semibold text-primary-hover hover:text-navy" @click="resetFilters()">Atur Ulang Filter</button>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</div>
