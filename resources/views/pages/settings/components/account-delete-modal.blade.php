<div
    x-show="modal === 'delete'"
    x-dialog="modal === 'delete'"
    x-cloak
    class="fixed inset-0 z-50 flex items-center justify-center p-4"
    role="alertdialog"
    aria-modal="true"
    aria-labelledby="delete-user-title"
    aria-describedby="delete-user-desc"
    @keydown.escape.window="modal === 'delete' && closeModal()"
>
    <div x-show="modal === 'delete'" x-transition.opacity class="absolute inset-0 bg-slate-900/50" @click="closeModal()"></div>

    <div
        x-show="modal === 'delete'"
        x-transition
        class="relative max-h-[calc(100dvh-2rem)] overflow-y-auto w-full max-w-md rounded-panel border border-line bg-surface p-5 shadow-lg"
    >
        <h2 id="delete-user-title" class="text-base font-semibold text-ink">Hapus Akun?</h2>
        <p id="delete-user-desc" class="mt-1 text-sm text-muted">
            Apakah Anda yakin ingin menghapus akun
            <strong class="font-semibold text-ink" x-text="deletingUser?.fullname"></strong>
            (<span x-text="'@' + (deletingUser?.username ?? '')"></span>)?
            Tindakan ini tidak dapat dibatalkan.
        </p>

        <p class="mt-3 text-sm text-muted">Akun yang memiliki riwayat tugas tidak dapat dihapus.</p>

        <p role="alert" x-show="formError" x-text="formError" class="mt-4 text-xs text-red-600"></p>

        <div class="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <button type="button" data-dialog-initial class="inline-flex h-9 items-center justify-center gap-2 rounded-lg border border-line bg-white px-3 text-sm font-semibold text-ink hover:bg-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:opacity-50" :disabled="saving" @click="closeModal()">Batal</button>
            <button type="button" class="inline-flex h-9 items-center justify-center gap-2 rounded-lg bg-red-600 px-4 text-sm font-semibold text-white hover:bg-red-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-600 disabled:cursor-not-allowed disabled:opacity-50" :disabled="saving" @click="confirmDelete()" x-text="saving ? 'Menghapus…' : 'Hapus akun'"></button>
        </div>
    </div>
</div>
