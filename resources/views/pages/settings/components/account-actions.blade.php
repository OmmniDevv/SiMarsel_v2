<div class="inline-grid w-32 grid-cols-2 items-center gap-2 align-middle">
    <button
        type="button"
        class="inline-flex h-11 lg:h-9 items-center justify-center rounded-md px-2 font-semibold text-primary-hover hover:text-navy focus-visible:outline-2 focus-visible:outline-primary"
        :aria-label="'Ubah akun ' + user.fullname + ' (@' + user.username + ')'"
        @click="openEdit(user)"
    >Ubah</button>
    <div class="min-w-0">
        <button
            type="button"
            x-show="!isSelf(user)"
            class="inline-flex h-11 lg:h-9 w-full items-center justify-center rounded-md px-2 font-semibold text-red-600 hover:text-red-700 focus-visible:outline-2 focus-visible:outline-red-600"
            :aria-label="'Hapus akun ' + user.fullname + ' (@' + user.username + ')'"
            @click="openDelete(user)"
        >Hapus</button>
        <span x-show="isSelf(user)" class="inline-flex h-11 lg:h-9 w-full items-center justify-center text-muted" title="Anda tidak dapat menghapus akun sendiri">
            <span aria-hidden="true">—</span>
            <span class="sr-only">Anda tidak dapat menghapus akun sendiri.</span>
        </span>
    </div>
</div>
