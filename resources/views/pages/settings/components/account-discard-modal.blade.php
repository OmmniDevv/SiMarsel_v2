<div x-show="discardConfirmation" x-dialog="discardConfirmation" x-cloak
    class="fixed inset-0 z-60 flex items-center justify-center p-4"
    role="alertdialog" aria-modal="true" aria-labelledby="discard-account-title" aria-describedby="discard-account-desc"
    @keydown.escape.stop="discardConfirmation = false">
    <div class="absolute inset-0 bg-slate-900/50" @click="discardConfirmation = false"></div>
    <div class="relative max-h-[calc(100dvh-2rem)] w-full max-w-md overflow-y-auto rounded-panel border border-line bg-surface p-5 shadow-lg sm:p-6">
        <h2 id="discard-account-title" class="text-xl font-semibold text-navy">Buang perubahan?</h2>
        <p id="discard-account-desc" class="mt-3 text-sm text-muted">Perubahan yang belum disimpan akan hilang.</p>
        <div class="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <button type="button" data-dialog-initial class="inline-flex min-h-11 items-center justify-center rounded-lg border border-line bg-surface px-4 text-sm font-semibold text-ink hover:bg-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-hover" @click="discardConfirmation = false">Lanjut mengisi</button>
            <button type="button" class="inline-flex min-h-11 items-center justify-center rounded-lg bg-red-600 px-4 text-sm font-semibold text-white hover:bg-red-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-600" @click="closeModal()">Buang perubahan</button>
        </div>
    </div>
</div>
