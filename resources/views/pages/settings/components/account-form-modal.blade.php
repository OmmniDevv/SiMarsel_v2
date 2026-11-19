@php
    $isCreate = $mode === 'create';
    $model = $isCreate ? 'draft' : 'form';
    $fieldErrors = $isCreate ? 'draftErrors' : 'errors';
    $pending = $isCreate ? 'creating' : 'saving';
    $generalError = $isCreate ? 'draftError' : 'formError';
    $visibility = $isCreate ? 'showDraftPassword' : 'showPassword';
    $labelClass = 'mb-1.5 block text-xs font-semibold text-muted';
    $inputClass = 'h-11 w-full rounded-lg border border-label bg-surface px-3 text-sm text-ink placeholder:text-muted focus:border-primary-hover focus:ring-2 focus:ring-primary/20 focus:outline-none disabled:cursor-not-allowed disabled:bg-soft disabled:text-muted';
    $selectClass = $inputClass.' appearance-none pr-9';
    $chevron = '<svg class="pointer-events-none absolute top-1/2 right-3 size-4 -translate-y-1/2 text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6" /></svg>';
    $errorClass = 'mt-1.5 text-xs text-red-600';
@endphp

<div
    x-show="modal === '{{ $mode }}'"
    x-dialog="modal === '{{ $mode }}'"
    x-cloak
    class="fixed inset-0 z-50 flex items-center justify-center p-4"
    role="dialog"
    aria-modal="true"
    aria-labelledby="{{ $mode }}-user-title" aria-describedby="{{ $mode }}-user-desc"
    @keydown.escape.stop="modal === '{{ $mode }}' && !discardConfirmation && requestClose()"
>
    <div x-show="modal === '{{ $mode }}'" x-transition.opacity class="absolute inset-0 bg-slate-900/50 motion-reduce:transition-none!" @click="requestClose()"></div>

    <div
        x-show="modal === '{{ $mode }}'"
        x-transition
        class="relative max-h-[calc(100dvh-2rem)] w-full max-w-2xl overflow-y-auto rounded-panel border border-line bg-surface p-5 shadow-lg sm:p-6 motion-reduce:transition-none! motion-reduce:transform-none!"
    >
        <h2 id="{{ $mode }}-user-title" class="text-xl font-semibold text-navy">{{ $isCreate ? 'Tambah Akun' : 'Ubah Akun' }}</h2>
        <p id="{{ $mode }}-user-desc" class="mt-1 text-sm text-muted">{{ $isCreate ? 'Daftarkan anggota tim dan tentukan role serta divisinya.' : 'Perbarui data anggota tim. Password hanya berubah jika diisi.' }}</p>

        <p class="mt-2 text-xs text-muted">Kolom bertanda * wajib diisi.</p>
        <form class="mt-5 flex flex-col gap-5" novalidate @submit.prevent="{{ $isCreate ? 'submitCreate()' : 'submitEdit()' }}" :aria-busy="{{ $pending }}">
            <fieldset class="min-w-0">
                <legend class="mb-3 text-sm font-semibold text-ink">Identitas akun</legend>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="{{ $mode }}-fullname" class="{{ $labelClass }}">Nama Lengkap *</label>
                        <input id="{{ $mode }}-fullname" aria-required="true" :aria-invalid="Boolean({{ $fieldErrors }}.fullname).toString()" aria-describedby="{{ $mode }}-fullname-error" data-dialog-initial type="text" x-model="{{ $model }}.fullname" @input="{{ $fieldErrors }}.fullname = null"
                            class="{{ $inputClass }}" :class="{{ $fieldErrors }}.fullname && 'border-red-500!'" placeholder="Masukkan nama lengkap" autocomplete="off">
                        <p id="{{ $mode }}-fullname-error" aria-live="polite" x-show="{{ $fieldErrors }}.fullname" x-text="{{ $fieldErrors }}.fullname" class="{{ $errorClass }}"></p>
                    </div>

                    <div>
                        <label for="{{ $mode }}-username" class="{{ $labelClass }}">Username *</label>
                        <input id="{{ $mode }}-username" aria-required="true" :aria-invalid="Boolean({{ $fieldErrors }}.username).toString()" aria-describedby="{{ $mode }}-username-help {{ $mode }}-username-error" type="text" x-model="{{ $model }}.username" @input="{{ $fieldErrors }}.username = null"
                            class="{{ $inputClass }}" :class="{{ $fieldErrors }}.username && 'border-red-500!'" placeholder="budi.santoso" autocomplete="off" autocapitalize="none" spellcheck="false">
                        <p id="{{ $mode }}-username-help" class="mt-1.5 text-xs text-muted">4–25 karakter: huruf, angka, titik, atau underscore.</p>
                        <p id="{{ $mode }}-username-error" aria-live="polite" x-show="{{ $fieldErrors }}.username" x-text="{{ $fieldErrors }}.username" class="{{ $errorClass }}"></p>
                    </div>

                </div>
            </fieldset>
            <fieldset class="min-w-0 border-t border-line pt-4">
                <legend class="pr-2 text-sm font-semibold text-ink">Role dan divisi</legend>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="{{ $mode }}-role_id" class="{{ $labelClass }}">Role *</label>
                        <div class="relative">
                            <select id="{{ $mode }}-role_id" aria-required="true" :aria-invalid="Boolean({{ $fieldErrors }}.role_id).toString()" @if ($isCreate) aria-describedby="{{ $mode }}-role_id-error"
                                @else :aria-describedby="editingId === currentUserId ? '{{ $mode }}-role-help {{ $mode }}-role_id-error' : '{{ $mode }}-role_id-error'" @endif x-model="{{ $model }}.role_id" @change="{{ $fieldErrors }}.role_id = null" @unless ($isCreate) :disabled="editingId === currentUserId" @endunless
                                class="{{ $selectClass }}" :class="{{ $fieldErrors }}.role_id && 'border-red-500!'">
                                <template x-for="role in roles" :key="role.id">
                                    <option :value="role.id" x-text="role.name" :selected="role.id == {{ $model }}.role_id"></option>
                                </template>
                            </select>
                            {!! $chevron !!}
                        </div>
                        @unless ($isCreate)
                            <p id="{{ $mode }}-role-help" x-show="editingId === currentUserId" class="mt-1.5 text-xs text-muted">Role akun sendiri tidak dapat diubah.</p>
                        @endunless
                        <p id="{{ $mode }}-role_id-error" aria-live="polite" x-show="{{ $fieldErrors }}.role_id" x-text="{{ $fieldErrors }}.role_id" class="{{ $errorClass }}"></p>
                    </div>

                    <div>
                        <label for="{{ $mode }}-division_id" class="{{ $labelClass }}">Divisi (opsional)</label>
                        <div class="relative">
                            <select id="{{ $mode }}-division_id" :aria-invalid="Boolean({{ $fieldErrors }}.division_id).toString()" :aria-describedby="{{ $model }}.division_id === '' ? '{{ $mode }}-division_id-help {{ $mode }}-division_id-error' : '{{ $mode }}-division_id-error'" x-model="{{ $model }}.division_id" @change="{{ $fieldErrors }}.division_id = null"
                                class="{{ $selectClass }}" :class="{{ $fieldErrors }}.division_id && 'border-red-500!'">
                                <option value="">Tanpa Divisi</option>
                                <template x-for="division in divisions" :key="division.id">
                                    <option :value="division.id" x-text="division.name" :selected="division.id == {{ $model }}.division_id"></option>
                                </template>
                            </select>
                            {!! $chevron !!}
                        </div>
                        <p id="{{ $mode }}-division_id-help" x-show="{{ $model }}.division_id === ''" class="mt-1.5 text-xs text-muted">Tanpa Divisi: tidak terikat pada satu divisi.</p>
                        <p id="{{ $mode }}-division_id-error" aria-live="polite" x-show="{{ $fieldErrors }}.division_id" x-text="{{ $fieldErrors }}.division_id" class="{{ $errorClass }}"></p>
                    </div>
                </div>

                <details class="mt-3 text-xs text-muted">
                    <summary class="min-h-6 w-fit cursor-pointer rounded-sm font-semibold hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">Tentang akses akun</summary>
                    <p class="mt-2 max-w-prose">Hanya Manager yang dapat menambah, mengubah, dan menghapus akun.</p>
                </details>

            </fieldset>
            <fieldset class="min-w-0 border-t border-line pt-4">
                <legend class="pr-2 text-sm font-semibold text-ink">Keamanan akun</legend>
                <div>
                    <label for="{{ $mode }}-password" class="{{ $labelClass }}">{{ $isCreate ? 'Password *' : 'Password baru (opsional)' }}</label>
                    <div class="relative">
                        <input id="{{ $mode }}-password" @if ($isCreate) aria-required="true" @endif :aria-invalid="Boolean({{ $fieldErrors }}.password).toString()" aria-describedby="{{ $mode }}-password-help {{ $mode }}-password-error" :type="{{ $visibility }} ? 'text' : 'password'" x-model="{{ $model }}.password" @input="{{ $fieldErrors }}.password = null"
                            class="{{ $inputClass }} pr-28" :class="{{ $fieldErrors }}.password && 'border-red-500!'" autocomplete="new-password">
                        <button type="button" class="absolute inset-y-0 right-1 rounded-md px-2 text-xs font-semibold text-muted hover:text-ink focus-visible:outline-2 focus-visible:outline-primary-hover"
                            @click="{{ $visibility }} = !{{ $visibility }}" x-text="{{ $visibility }} ? 'Sembunyikan' : 'Tampilkan'" :aria-pressed="{{ $visibility }}.toString()" aria-controls="{{ $mode }}-password"></button>
                    </div>
                    <p id="{{ $mode }}-password-help" class="mt-1.5 text-xs text-muted">{{ $isCreate ? 'Minimal 8 karakter.' : 'Biarkan kosong jika tidak diganti. Minimal 8 karakter untuk password baru.' }}</p>
                    <p id="{{ $mode }}-password-error" aria-live="polite" x-show="{{ $fieldErrors }}.password" x-text="{{ $fieldErrors }}.password" class="{{ $errorClass }}"></p>
                </div>

            </fieldset>
            <p x-show="{{ $generalError }}" x-text="{{ $generalError }}" role="alert" class="text-xs text-red-600"></p>

            <div class="flex flex-col-reverse gap-2 border-t border-line pt-4 sm:flex-row sm:justify-end">
                <button type="button" class="inline-flex h-11 items-center justify-center gap-2 rounded-lg border border-label bg-surface px-3 text-sm font-semibold text-ink hover:bg-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:opacity-50" :disabled="{{ $pending }}" @click="requestClose()">Batal</button>
                <button type="submit" :disabled="{{ $pending }}" class="inline-flex h-11 items-center justify-center gap-2 rounded-lg bg-primary-hover px-4 text-sm font-semibold text-white hover:bg-navy focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:cursor-not-allowed disabled:opacity-50" x-text="{{ $pending }} ? 'Menyimpan…' : '{{ $isCreate ? 'Tambah Akun' : 'Simpan Perubahan' }}'"></button>
            </div>
        </form>
    </div>
</div>
