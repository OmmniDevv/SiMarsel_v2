@php
    $selectClass = 'h-9 w-full rounded-lg border border-line bg-white pr-8 pl-3 sm:w-auto text-sm text-ink focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none disabled:bg-soft disabled:text-muted';
@endphp

{{-- Toolbar ringkas: pilihan tampilan + pencarian + filter dalam satu baris (desktop). Filter berlaku di semua tampilan. --}}
<div class="flex flex-col gap-2 2xl:flex-row 2xl:items-center 2xl:gap-3">
    <div class="inline-flex shrink-0 self-start overflow-x-auto rounded-lg border border-line bg-soft p-1" role="tablist" aria-label="Pilih tampilan">
        <template x-for="item in views" :key="item.key">
            <button
                type="button"
                role="tab"
                class="flex-1 rounded-md px-3.5 py-1 text-sm font-semibold whitespace-nowrap focus-visible:outline-2 focus-visible:outline-primary sm:flex-none"
                :class="view === item.key ? 'bg-white text-ink shadow-sm' : 'text-muted hover:text-ink'"
                :aria-selected="view === item.key"
                @click="view = item.key"
                x-text="item.label"
            ></button>
        </template>
    </div>

    {{-- Cakupan Papan dan Tabel: seluruh tugas lintas divisi, atau tugas dengan akun ini sebagai PIC aktif. --}}
    <div x-show="view === 'board' || view === 'table'" class="inline-flex shrink-0 self-start rounded-lg border border-line bg-soft p-1" role="group" aria-label="Cakupan tugas" data-task-scope>
        <template x-for="item in scopes" :key="item.key">
            <button
                type="button"
                class="rounded-md px-3.5 py-1 text-sm font-semibold whitespace-nowrap focus-visible:outline-2 focus-visible:outline-primary"
                :class="taskScope === item.key ? 'bg-white text-ink shadow-sm' : 'text-muted hover:text-ink'"
                :aria-pressed="taskScope === item.key"
                @click="taskScope = item.key"
                x-text="item.label"
            ></button>
        </template>
    </div>

    {{-- Lebar tiap filter mengikuti isinya agar teks tidak terpotong; baris membungkus bila tidak muat. --}}
    <div class="flex min-w-0 flex-1 flex-wrap items-center gap-2">
        <label class="relative block min-w-0 basis-full sm:min-w-48 sm:flex-1 sm:basis-auto">
            <span class="sr-only">Cari judul tugas</span>
            <x-icon name="search" class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-label" />
            <input id="task-search" type="search" x-model.debounce.200ms="filters.search" maxlength="150" placeholder="Cari judul tugas…"
                class="h-9 w-full rounded-lg border border-line bg-white pr-3 pl-9 text-sm text-ink placeholder:text-label focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none">
        </label>

        <label x-show="view !== 'timeline'" class="block min-w-0 basis-full sm:max-w-60 sm:flex-none sm:basis-auto">
            <span class="sr-only">Divisi</span>
            <select id="task-division" x-model="filters.division" class="{{ $selectClass }}" title="Divisi">
                <option value="">Semua Divisi</option>
                <template x-for="division in divisionOptions" :key="division.code_division">
                    <option :value="division.code_division" x-text="division.name" :selected="filters.division === division.code_division"></option>
                </template>
            </select>
        </label>

        <label class="block min-w-36 flex-1 sm:min-w-0 sm:flex-none">
            <span class="sr-only">Prioritas</span>
            <select id="task-priority" x-model="filters.priority" class="{{ $selectClass }}" title="Prioritas">
                <option value="">Semua Prioritas</option>
                <template x-for="priority in priorities" :key="priority.key">
                    <option :value="priority.key" x-text="priority.label"></option>
                </template>
            </select>
        </label>

        <label class="block min-w-36 flex-1 sm:min-w-0 sm:flex-none">
            <span class="sr-only">Status</span>
            <select id="task-status" x-model="filters.status" class="{{ $selectClass }}" title="Status">
                <option value="">Semua Status</option>
                <template x-for="item in statuses" :key="item.key">
                    <option :value="item.key" x-text="item.label"></option>
                </template>
            </select>
        </label>

        <button type="button" class="{{ $button }} shrink-0 basis-full sm:basis-auto" :disabled="!hasActiveFilter" @click="resetFilters()">Atur Ulang Filter</button>
    </div>
</div>
