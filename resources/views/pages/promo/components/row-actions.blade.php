{{-- Shared row actions; $identifier is the local Alpine expression naming the item. --}}
<div class="inline-flex items-center gap-1">
    <button type="button" class="inline-flex min-h-11 items-center rounded-md px-3 font-semibold text-primary hover:bg-primary-soft hover:text-primary-hover focus-visible:outline-2 focus-visible:outline-primary" :aria-label="'Ubah ' + {{ $identifier }}" @click="openEdit(item)">Ubah</button>
    <button type="button" class="inline-flex min-h-11 items-center rounded-md px-3 font-semibold text-red-700 hover:bg-red-50 focus-visible:outline-2 focus-visible:outline-red-600" :aria-label="'Hapus ' + {{ $identifier }}" @click="openDelete(item)">Hapus</button>
</div>
