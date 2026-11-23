<div class="inline-grid w-32 grid-cols-2 items-center gap-2 align-middle">
    <button type="button" class="inline-flex h-11 items-center justify-center rounded-md px-2 font-semibold text-primary-hover hover:text-navy focus-visible:outline-2 focus-visible:outline-primary lg:h-9"
        :aria-label="'Ubah divisi ' + division.name + ' (' + division.code_division + ')'" @click="openEdit(division)">Ubah</button>
    <button type="button" class="inline-flex h-11 items-center justify-center rounded-md px-2 font-semibold text-red-600 hover:text-red-700 focus-visible:outline-2 focus-visible:outline-red-600 lg:h-9"
        :aria-label="'Hapus divisi ' + division.name + ' (' + division.code_division + ')'" @click="openDelete(division)">Hapus</button>
</div>
