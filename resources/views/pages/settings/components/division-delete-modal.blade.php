<div
    x-show="modal === 'delete'"
    x-dialog="modal === 'delete'"
    x-cloak
    class="fixed inset-0 z-50 flex items-center justify-center p-4"
    role="alertdialog"
    aria-modal="true"
    aria-labelledby="delete-division-title"
    aria-describedby="delete-division-identity delete-division-desc delete-division-impact"
    @keydown.escape.window="modal === 'delete' && closeModal()"
>
    <div x-show="modal === 'delete'" x-transition.opacity class="absolute inset-0 bg-slate-900/50 motion-reduce:transition-none!" @click="closeModal()"></div>

    <div
        x-show="modal === 'delete'"
        x-transition
        class="relative max-h-[calc(100dvh-2rem)] w-full overflow-y-auto max-w-md rounded-panel border border-line bg-surface p-5 shadow-lg sm:p-6 motion-reduce:transition-none! motion-reduce:transform-none!"
    >
        <h2 id="delete-division-title" class="text-xl font-semibold text-navy">Hapus Divisi?</h2>
        <div id="delete-division-identity" class="mt-4 min-w-0 space-y-1 wrap-anywhere">
            <p class="text-base font-semibold text-ink" x-text="deletingDivision?.name"></p>
            <p class="text-sm text-muted" x-text="deletingDivision?.code_division"></p>
        </div>
        <p id="delete-division-desc" class="mt-4 text-sm text-ink">Penghapusan tidak dapat dibatalkan.</p>

        <p id="delete-division-impact" class="mt-3 rounded-lg border px-3 py-2 text-sm"
            :class="deletingDivision?.accounts_count ? 'border-amber-200 bg-amber-50 text-amber-800' : 'border-line bg-soft text-muted'"
            x-text="deletingDivision?.accounts_count ? deletingDivision.accounts_count + ' akun di divisi ini akan menjadi Tanpa Divisi. Akun tetap tersimpan.' : 'Divisi ini belum memiliki anggota.'"></p>

        <p role="alert" x-show="formError" x-text="formError" class="mt-4 text-xs text-red-600"></p>

        <div class="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <button type="button" data-dialog-initial class="inline-flex h-11 items-center justify-center gap-2 rounded-lg border border-line bg-surface px-3 text-sm font-semibold text-ink hover:bg-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:opacity-50" :disabled="saving" @click="closeModal()">Batal</button>
            <button type="button" class="inline-flex h-11 items-center justify-center gap-2 rounded-lg bg-red-600 px-4 text-sm font-semibold text-white hover:bg-red-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-600 disabled:cursor-not-allowed disabled:opacity-50" :disabled="saving" @click="confirmDelete()" x-text="saving ? 'Menghapus…' : 'Hapus divisi'"></button>
        </div>
    </div>
</div>
