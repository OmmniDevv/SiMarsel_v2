<div
    x-show="modal === 'delete'"
    x-dialog="modal === 'delete'"
    x-cloak
    class="fixed inset-0 z-50 flex items-center justify-center p-4"
    role="alertdialog"
    aria-modal="true"
    aria-labelledby="delete-division-title"
    aria-describedby="delete-division-desc delete-division-impact"
    @keydown.escape.window="modal === 'delete' && closeModal()"
>
    <div x-show="modal === 'delete'" x-transition.opacity class="absolute inset-0 bg-slate-900/50" @click="closeModal()"></div>

    <div
        x-show="modal === 'delete'"
        x-transition
        class="relative max-h-[calc(100dvh-2rem)] w-full overflow-y-auto max-w-md rounded-panel border border-line bg-surface p-5 shadow-lg"
    >
        <h2 id="delete-division-title" class="text-base font-semibold text-ink">Hapus Divisi?</h2>
        <p id="delete-division-desc" class="mt-1 text-sm text-muted">
            Apakah Anda yakin ingin menghapus divisi
            <strong class="font-semibold text-ink" x-text="deletingDivision?.name"></strong>
            (<span x-text="deletingDivision?.code_division"></span>)?
            Tindakan ini tidak dapat dibatalkan.
        </p>

        <p id="delete-division-impact" x-show="deletingDivision?.accounts_count" class="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
            <span class="font-semibold" x-text="deletingDivision?.accounts_count + ' akun'"></span>
            di divisi ini akan menjadi Tanpa Divisi. Akun tetap tersimpan.
        </p>

        <p role="alert" x-show="formError" x-text="formError" class="mt-4 text-xs text-red-600"></p>

        <div class="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <button type="button" data-dialog-initial class="inline-flex h-10 items-center justify-center gap-2 rounded-lg border border-line bg-white px-3 text-sm font-semibold text-ink hover:bg-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:opacity-50" :disabled="saving" @click="closeModal()">Batal</button>
            <button type="button" class="inline-flex h-10 items-center justify-center gap-2 rounded-lg bg-red-600 px-4 text-sm font-semibold text-white hover:bg-red-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-600 disabled:cursor-not-allowed disabled:opacity-50" :disabled="saving" @click="confirmDelete()" x-text="saving ? 'Menghapus…' : 'Hapus divisi'"></button>
        </div>
    </div>
</div>
