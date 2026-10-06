{{--
    Modal konfirmasi pengganti window.confirm().
    Pemakaian pada form:
      <form data-confirm="Pesan penjelasan"
            data-confirm-title="Hapus tugas?"      (opsional, bawaan "Konfirmasi")
            data-confirm-label="Hapus"             (opsional, bawaan "Lanjutkan")
            data-confirm-tone="danger">            (opsional, tombol merah)
--}}
<dialog id="confirm-modal" aria-labelledby="confirm-modal-title"
    class="m-auto w-[calc(100%-2rem)] max-w-md rounded-md border border-line bg-surface p-0 text-ink shadow-[0_16px_48px_-12px_rgb(0_0_0/0.25)] backdrop:bg-ink/40 backdrop:backdrop-blur-[2px]">
    <form method="dialog" class="p-5">
        <h2 id="confirm-modal-title" class="text-base font-semibold" data-modal-title>Konfirmasi</h2>
        <p class="mt-2 leading-relaxed text-muted" data-modal-message></p>
        <div class="mt-6 flex justify-end gap-2">
            <button type="submit" value="cancel" class="btn btn-secondary" autofocus>Batal</button>
            <button type="submit" value="confirm" class="btn btn-primary" data-modal-confirm>Lanjutkan</button>
        </div>
    </form>
</dialog>

<script>
    (() => {
        const modal = document.getElementById('confirm-modal');
        const title = modal.querySelector('[data-modal-title]');
        const message = modal.querySelector('[data-modal-message]');
        const confirmButton = modal.querySelector('[data-modal-confirm]');
        let pendingForm = null;

        document.addEventListener('submit', (e) => {
            const form = e.target;
            if (!form.dataset?.confirm) return;

            // Sudah dikonfirmasi lewat modal: biarkan form terkirim.
            if (form.dataset.confirmed === '1') {
                delete form.dataset.confirmed;
                return;
            }

            e.preventDefault();
            pendingForm = form;
            title.textContent = form.dataset.confirmTitle || 'Konfirmasi';
            message.textContent = form.dataset.confirm;
            confirmButton.textContent = form.dataset.confirmLabel || 'Lanjutkan';
            confirmButton.className = 'btn ' + (form.dataset.confirmTone === 'danger' ? 'btn-danger-solid' : 'btn-primary');
            modal.returnValue = '';
            modal.showModal();
        });

        modal.addEventListener('close', () => {
            if (modal.returnValue === 'confirm' && pendingForm) {
                pendingForm.dataset.confirmed = '1';
                pendingForm.requestSubmit();
            }
            pendingForm = null;
        });

        // Klik di luar kotak modal = batal.
        modal.addEventListener('click', (e) => {
            if (e.target === modal) modal.close('cancel');
        });
    })();
</script>
