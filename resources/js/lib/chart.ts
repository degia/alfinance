/**
 * Konfigurasi bersama untuk Chart.js (AGENT.md aturan 7 & Fase 8).
 *
 * Chart digambar ke `<canvas>`, jadi warnanya tidak bisa datang dari class
 * Tailwind. Semua warna yang bukan data (warna status, garis tren, grid
 * sumbu) dibaca dari design token di `resources/css/app.css`, sehingga
 * light/dark mode ikut berubah dan tidak ada hex atau rgb hardcode di
 * halaman.
 *
 * Modul ini juga menjadi satu-satunya sumber durasi animasi chart dan
 * menghormati `prefers-reduced-motion`.
 */

/**
 * Peta nama token -> custom property CSS.
 */
const TOKENS = {
    income: '--income',
    expense: '--expense',
    incomeSoft: '--income-soft',
    expenseSoft: '--expense-soft',
    /** Warna netral untuk irisan "Lainnya". */
    neutral: '--muted-foreground',
    /** Garis grid sumbu Y. */
    grid: '--border',
    /** Warna progress bar Inertia (dibaca juga oleh `app.ts`). */
    progressBar: '--progress-bar',
} as const;

export type ChartToken = keyof typeof TOKENS;

/**
 * Warna cadangan kalau token tidak terbaca (mis. saat render di luar DOM).
 * Mengambil `color` yang sudah dihitung browser, jadi tidak ada hex hardcode
 * di JS.
 */
function fallbackColor(): string {
    if (typeof window === 'undefined') {
        return 'currentColor';
    }

    return window.getComputedStyle(document.documentElement).color;
}

/**
 * Baca nilai token CSS yang sedang aktif (light atau dark).
 */
export function chartToken(token: ChartToken): string {
    if (typeof window === 'undefined') {
        return fallbackColor();
    }

    const value = window
        .getComputedStyle(document.documentElement)
        .getPropertyValue(TOKENS[token])
        .trim();

    return value === '' ? fallbackColor() : value;
}

/**
 * True bila pengguna meminta pengurangan gerakan (a11y).
 */
function prefersReducedMotion(): boolean {
    if (typeof window === 'undefined' || !window.matchMedia) {
        return false;
    }

    return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

/**
 * Durasi animasi dalam ms, dibaca dari token motion di CSS supaya chart dan
 * komponen DOM bergerak dengan skala waktu yang sama.
 */
function motionMs(token: '--motion-slow'): number {
    const fallback = 300;

    if (typeof window === 'undefined') {
        return fallback;
    }

    const parsed = Number.parseFloat(
        window
            .getComputedStyle(document.documentElement)
            .getPropertyValue(token),
    );

    return Number.isFinite(parsed) ? parsed : fallback;
}

/**
 * Konfigurasi `options.animation` yang seragam untuk semua chart.
 *
 * Mengembalikan `false` (animasi dimatikan) bila pengguna memilih reduced
 * motion.
 */
export function chartAnimation():
    | false
    | {
          duration: number;
          easing: 'easeOutQuart';
      } {
    if (prefersReducedMotion()) {
        return false;
    }

    return {
        duration: motionMs('--motion-slow'),
        easing: 'easeOutQuart',
    };
}
