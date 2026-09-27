<?php

namespace App\Services\Cache;

/**
 * Konteks cache per workspace (ARCHITECTURE.md §2.1 butir 1).
 *
 * Nilai enum ini adalah bagian dari kunci cache `ws:{workspace_id}:{context}`
 * yang ditulis {@see WorkspaceCache}, jadi menambah konteks baru di sini
 * otomatis ikut ter-invalidate oleh listener.
 *
 * Nama konteks sengaja berawalan dengan namespace fitur
 * (`dashboard-*`, `report-*`) supaya satu fitur bisa di-invalidate utuh dengan
 * satu prefix tanpa enumerasi kombinasinya — lihat
 * {@see WorkspaceCache::forgetPrefix()}.
 */
enum CacheContext: string
{
    /** KPI utama dashboard: total saldo, income, expense, net cash flow. */
    case Dashboard = 'dashboard';

    /** Deret tren arus kas 6/12 bulan. */
    case DashboardCashFlow = 'dashboard-cash-flow';

    /** Donut/daftar pengeluaran per kategori untuk satu bulan. */
    case DashboardBreakdown = 'dashboard-breakdown';

    /** Tabel 10 transaksi terakhir (query ringan, TTL pendek). */
    case DashboardRecent = 'dashboard-recent';

    /** Laporan arus kas (statement). */
    case ReportCashFlow = 'report-cash-flow';

    /** Laporan budget vs actual. */
    case ReportBudget = 'report-budget-vs-actual';

    /** Laporan expense breakdown + top-N. */
    case ReportExpense = 'report-expense-breakdown';

    /** Skor & rekomendasi kesehatan finansial. */
    case FinancialHealth = 'financial-health';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
