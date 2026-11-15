import { download } from '../../export';
import { errorMessage, validationErrors } from '../../http';
import { showToast } from '../../components/toast';
import { exportAnalysis } from './api';

const FALLBACK_FILE_NAME = 'promo-analisis.xlsx';

/**
 * Export the applied analysis filter. Components contribute chart period and code sort through
 * `promo-analysis-export-params` before the backend file is downloaded.
 */
export default function promoAnalysisActions() {
    return {
        exporting: false,
        status: null,

        get actionsDisabled() {
            return this.query === null;
        },

        clearStatus() {
            if (!this.exporting) this.status = null;
        },

        exportQuery() {
            const detail = { params: new URLSearchParams(this.query ?? '') };
            this.$dispatch('promo-analysis-export-params', detail);

            return detail.params.toString();
        },

        async exportExcel() {
            if (this.exporting || this.actionsDisabled) return;

            this.exporting = true;
            this.status = { type: 'loading', message: 'Menyiapkan file Excel sesuai filter aktif…' };

            try {
                const { blob, fileName } = await exportAnalysis(this.exportQuery());
                const name = fileName ?? FALLBACK_FILE_NAME;

                download(blob, name);
                this.status = null;
                showToast({ title: 'Ekspor Berhasil', text: `File ${name} berhasil diunduh.` });
            } catch (error) {
                if (error?.status === 422 && error.data?.errors) {
                    this.$dispatch('promo-analysis-errors', validationErrors(error));
                }

                this.status = { type: 'error', message: errorMessage(error, 'Gagal mengekspor data analisis promo. Silakan coba lagi.') };
            } finally {
                this.exporting = false;
            }
        },
    };
}
