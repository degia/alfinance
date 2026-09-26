/**
 * Format nominal uang untuk v1 (IDR, tanpa desimal currency — lihat
 * PRD.md §6: multi-currency di luar scope).
 *
 * Input selalu string desimal dari server supaya presisi DECIMAL(15,2)
 * tidak hilang, tapi helper ini menerima number|string agar aman dipanggil
 * dengan nilai hasil komputasi lokal.
 */

const CURRENCY = 'IDR';
const LOCALE = 'id-ID';

const formatter = new Intl.NumberFormat(LOCALE, {
    style: 'currency',
    currency: CURRENCY,
    minimumFractionDigits: 0,
    maximumFractionDigits: 0,
});

const preciseFormatter = new Intl.NumberFormat(LOCALE, {
    style: 'currency',
    currency: CURRENCY,
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
});

/**
 * Format sebagai nominal bulat, mis. `Rp1.250.000`.
 */
export function formatCurrency(value: number | string): string {
    const amount = toNumber(value);

    if (amount === null) {
        return 'Rp0';
    }

    return formatter.format(amount);
}

/**
 * Format dengan 2 desimal, dipakai di form & detail akun.
 */
export function formatCurrencyPrecise(value: number | string): string {
    const amount = toNumber(value);

    if (amount === null) {
        return 'Rp0,00';
    }

    return preciseFormatter.format(amount);
}

/**
 * Ubah string desimal dari server menjadi number. Mengembalikan null bila
 * input tidak valid, supaya UI bisa menampilkan placeholder alih-alih NaN.
 */
export function toNumber(
    value: number | string | null | undefined,
): number | null {
    if (value === null || value === undefined || value === '') {
        return null;
    }

    const parsed = typeof value === 'number' ? value : Number(value);

    return Number.isFinite(parsed) ? parsed : null;
}

/**
 * Tanda plus/minus eksplisit untuk saldo — formatter sudah menandai nilai
 * negatif, jadi ini hanya menambahkan tanda `+` untuk nominal positif.
 */
export function formatSignedCurrency(value: number | string): string {
    const amount = toNumber(value) ?? 0;

    return amount > 0 ? `+${formatCurrency(amount)}` : formatCurrency(amount);
}

/**
 * Tanggal `YYYY-MM-DD` dari server → format lokal `9 Sep 2026`.
 * Sengaja tidak memakai `new Date(string)` karena itu di-parse sebagai UTC
 * dan bisa bergeser sehari di zona waktu lokal.
 */
export function formatDate(value: string | null | undefined): string {
    if (!value) {
        return '-';
    }

    const [year, month, day] = value.split('-').map(Number);

    if (!year || !month || !day) {
        return value;
    }

    return new Intl.DateTimeFormat(LOCALE, {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    }).format(new Date(year, month - 1, day));
}
