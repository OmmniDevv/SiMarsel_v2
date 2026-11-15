{{-- Shared row actions; $identifier is the local Alpine expression naming the item. --}}
<div class="inline-flex items-center gap-1">
    <button type="button" class="inline-flex min-h-11 items-center rounded-md px-3 font-semibold text-primary hover:bg-primary-soft hover:text-primary-hover focus-visible:outline-2 focus-visible:outline-primary" :aria-label="'Ubah ' + {{ $identifier }}" @click="openEdit(item)">Ubah</button>
    <div x-data="promoRowMenu" @keydown.escape.prevent="close(true)" @click.outside="close()" @scroll.window="close()" @resize.window="close()" class="inline-flex">
        <button type="button" x-ref="trigger" class="inline-grid size-11 place-items-center rounded-md text-muted hover:bg-soft hover:text-ink focus-visible:outline-2 focus-visible:outline-primary" :aria-label="'Tindakan lain untuk ' + {{ $identifier }}" aria-haspopup="menu" :aria-expanded="open.toString()" @click="toggle()" @keydown.arrow-down.prevent="show()">
            <x-icon name="more" class="size-5" />
        </button>
        <div x-ref="menu" x-show="open" x-cloak class="fixed z-[70] min-w-36 rounded-lg border border-line bg-white p-1 shadow-lg" :style="position" role="menu" :aria-label="'Tindakan untuk ' + {{ $identifier }}" @keydown.tab="close()">
            <button type="button" role="menuitem" class="flex min-h-11 w-full items-center rounded-md px-3 text-sm font-semibold text-red-700 hover:bg-red-50 focus-visible:bg-red-50 focus-visible:outline-2 focus-visible:outline-red-600" :aria-label="'Hapus ' + {{ $identifier }}" @click="close(true); openDelete(item)">Hapus</button>
        </div>
    </div>
</div>
