import { copyText, toTsv } from '../../export';
import { errorMessage } from '../../http';

/** Shared clipboard helpers for the code-usage detail dialog. */
export const USAGE_COLUMNS = [
    { header: 'Tanggal', value: (row) => row.paid_date },
    { header: 'Platform/Aplikasi', value: (row) => row.platform?.name },
    { header: 'Jumlah Penggunaan', value: (row) => row.usages },
    { header: 'Revenue', value: (row) => row.revenue },
];

/**
 * The largest page the paginated analysis endpoints accept.
 */
export const MAX_PER_PAGE = 100;

/**
 * Every row of a paginated endpoint for the query, page after page in the backend's order.
 */
export async function allPages(fetchPage, query, pick = (result) => result.data) {
    const params = new URLSearchParams(query);
    params.set('per_page', String(MAX_PER_PAGE));

    const rows = [];
    let page = 1;
    let lastPage = 1;

    do {
        params.set('page', String(page));
        const result = await fetchPage(params.toString());

        rows.push(...pick(result));
        lastPage = result.meta?.last_page ?? 1;
        page++;
    } while (page <= lastPage);

    return rows;
}

/**
 * A paginated detail snapshot: `total` rows of the active filter, loaded by `loadRows`
 * when the copy runs.
 */
export function copyAllSnapshot({ loading, loadError, isEmpty }, columns, total, loadRows) {
    if (loading) return { status: 'loading' };
    if (loadError) return { status: 'error' };
    if (isEmpty || !total) return { status: 'empty' };

    return { status: 'ready', text: () => loadRows().then((rows) => toTsv(columns, rows)), rows: total };
}

/**
 * Message for a snapshot that cannot be copied, or null when it can.
 */
export function copyProblem(snapshot, label) {
    switch (snapshot?.status) {
        case 'ready':
            return null;
        case 'loading':
            return `Data ${label} masih dimuat. Tunggu sampai selesai, lalu salin lagi.`;
        case 'error':
            return `Data ${label} gagal dimuat, sehingga tidak ada yang disalin. Coba muat ulang komponen tersebut.`;
        case 'empty':
            return `Tidak ada data ${label} untuk filter yang dipilih, sehingga tidak ada yang disalin.`;
        default:
            return 'Komponen yang dipilih tidak tersedia untuk disalin.';
    }
}

/**
 * Copies a snapshot. Resolves { message } to show as the success toast, or { error } to show as the status.
 */
export async function runCopy(snapshot, label) {
    const problem = copyProblem(snapshot, label);
    if (problem) return { error: problem };

    let loadError = null;
    const text = typeof snapshot.text === 'function'
        ? snapshot.text().catch((error) => {
            loadError = error;
            throw error;
        })
        : snapshot.text;

    try {
        await copyText(text);
    } catch {
        return {
            error: loadError
                ? errorMessage(loadError, `Gagal mengambil data ${label}. Silakan coba lagi.`)
                : 'Gagal menyalin ke clipboard. Izinkan akses clipboard di browser, lalu coba lagi.',
        };
    }

    return { message: `${snapshot.rows} baris ${label} berhasil disalin.` };
}
