@php
    $isCreate = $mode === 'create';
    $model = $isCreate ? 'draft' : 'form';
    $fieldErrors = $isCreate ? 'draftErrors' : 'errors';
    $generalError = $isCreate ? 'draftError' : 'formError';
    $pending = $isCreate ? 'creating' : 'saving';
    $labelClass = 'mb-1.5 block text-xs font-semibold text-muted';
    $inputClass = 'h-10 w-full rounded-lg border border-line bg-white px-3 text-sm text-ink placeholder:text-muted focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none';
    $errorClass = 'mt-1.5 text-xs text-red-600';
    $helperClass = 'mt-1.5 text-xs text-muted';
    $required = '<span class="text-red-500" aria-hidden="true">*</span>';
@endphp

<div
    x-show="modal === '{{ $mode }}'"
    x-dialog="modal === '{{ $mode }}'"
    x-cloak
    class="fixed inset-0 z-50 flex items-center justify-center p-4"
    role="dialog"
    aria-modal="true"
    aria-labelledby="{{ $mode }}-division-title" aria-describedby="{{ $mode }}-division-desc"
    @keydown.escape.window="modal === '{{ $mode }}' && closeModal()"
>
    <div x-show="modal === '{{ $mode }}'" x-transition.opacity class="absolute inset-0 bg-slate-900/50" @click="closeModal()"></div>

    <div
        x-show="modal === '{{ $mode }}'"
        x-transition
        class="relative max-h-[calc(100dvh-2rem)] w-full max-w-md overflow-y-auto rounded-panel border border-line bg-surface p-5 shadow-lg"
    >
        <h2 id="{{ $mode }}-division-title" class="text-base font-semibold text-ink">{{ $isCreate ? 'Tambah Divisi' : 'Ubah Divisi' }}</h2>
        <p id="{{ $mode }}-division-desc" class="mt-1 text-sm text-muted">{{ $isCreate ? 'Tambahkan divisi baru. Divisi bisa dipilih saat membuat atau mengubah akun.' : 'Perbarui kode atau nama divisi. Anggota divisi tidak berubah.' }}</p>

        <p class="mt-2 text-xs text-muted">Field bertanda * wajib diisi.</p>

        <form class="mt-5 flex flex-col gap-4" novalidate @submit.prevent="{{ $isCreate ? 'submitCreate()' : 'submitEdit()' }}" :aria-busy="{{ $pending }}">
            <div>
                <label for="{{ $mode }}-division-code_division" class="{{ $labelClass }}">Kode Divisi {!! $required !!}</label>
                <input id="{{ $mode }}-division-code_division" aria-required="true" :aria-invalid="Boolean({{ $fieldErrors }}.code_division).toString()" aria-describedby="{{ $mode }}-division-code_division-help {{ $mode }}-division-code_division-error" data-dialog-initial type="text" x-model="{{ $model }}.code_division" @input="{{ $fieldErrors }}.code_division = null"
                    class="{{ $inputClass }}" :class="{{ $fieldErrors }}.code_division && 'border-red-500!'" placeholder="MKT" autocomplete="off" autocapitalize="characters" spellcheck="false" maxlength="50">
                <p id="{{ $mode }}-division-code_division-help" class="{{ $helperClass }}">Kode singkat yang unik, maksimal 50 karakter.</p>
                <p id="{{ $mode }}-division-code_division-error" aria-live="polite" x-show="{{ $fieldErrors }}.code_division" x-text="{{ $fieldErrors }}.code_division" class="{{ $errorClass }}"></p>
            </div>

            <div>
                <label for="{{ $mode }}-division-name" class="{{ $labelClass }}">Nama Divisi {!! $required !!}</label>
                <input id="{{ $mode }}-division-name" aria-required="true" :aria-invalid="Boolean({{ $fieldErrors }}.name).toString()" aria-describedby="{{ $mode }}-division-name-help {{ $mode }}-division-name-error" type="text" x-model="{{ $model }}.name" @input="{{ $fieldErrors }}.name = null"
                    class="{{ $inputClass }}" :class="{{ $fieldErrors }}.name && 'border-red-500!'" placeholder="Marketing" autocomplete="off" maxlength="25">
                <p id="{{ $mode }}-division-name-help" class="{{ $helperClass }}">Nama yang unik, maksimal 25 karakter.</p>
                <p id="{{ $mode }}-division-name-error" aria-live="polite" x-show="{{ $fieldErrors }}.name" x-text="{{ $fieldErrors }}.name" class="{{ $errorClass }}"></p>
            </div>

            <p x-show="{{ $generalError }}" x-text="{{ $generalError }}" role="alert" class="text-xs text-red-600"></p>

            <div class="flex flex-col-reverse gap-2 border-t border-line pt-4 sm:flex-row sm:justify-end">
                <button type="button" class="inline-flex h-10 items-center justify-center gap-2 rounded-lg border border-line bg-white px-3 text-sm font-semibold text-ink hover:bg-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:opacity-50" :disabled="{{ $pending }}" @click="closeModal()">Batal</button>
                <button type="submit" :disabled="{{ $pending }}" class="inline-flex h-10 items-center justify-center gap-2 rounded-lg bg-primary-hover px-4 text-sm font-semibold text-white hover:bg-navy focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:cursor-not-allowed disabled:opacity-50">
                    <span x-text="{{ $pending }} ? 'Menyimpan…' : '{{ $isCreate ? 'Tambah Divisi' : 'Simpan Perubahan' }}'"></span>
                </button>
            </div>
        </form>
    </div>
</div>
