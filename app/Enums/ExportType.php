<?php

namespace App\Enums;

/**
 * Jenis ekspor (PRD.md §3.10).
 *
 * PDF laporan memakai DomPDF + Blade print-friendly; Excel/CSV memakai
 * Maatwebsite. Menyimpan ekspor sebagai satu daftar tertutup membuat kolom
 * `type` di `export_jobs` terjaga (bukan string bebas) dan memberi tahu
 * `ExportService` cara membangun berkasnya.
 */
enum ExportType: string
{
    case TransactionsExcel = 'transactions_excel';
    case TransactionsCsv = 'transactions_csv';

    case CashFlowPdf = 'cash_flow_pdf';
    case BudgetPdf = 'budget_pdf';
    case ExpensePdf = 'expense_pdf';

    case CashFlowExcel = 'cash_flow_excel';
    case BudgetExcel = 'budget_excel';
    case ExpenseExcel = 'expense_excel';

    case CashFlowCsv = 'cash_flow_csv';
    case BudgetCsv = 'budget_csv';
    case ExpenseCsv = 'expense_csv';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::TransactionsExcel => 'Transaksi (Excel)',
            self::TransactionsCsv => 'Transaksi (CSV)',
            self::CashFlowPdf => 'Arus kas (PDF)',
            self::BudgetPdf => 'Budget vs realisasi (PDF)',
            self::ExpensePdf => 'Komposisi pengeluaran (PDF)',
            self::CashFlowExcel => 'Arus kas (Excel)',
            self::BudgetExcel => 'Budget vs realisasi (Excel)',
            self::ExpenseExcel => 'Komposisi pengeluaran (Excel)',
            self::CashFlowCsv => 'Arus kas (CSV)',
            self::BudgetCsv => 'Budget vs realisasi (CSV)',
            self::ExpenseCsv => 'Komposisi pengeluaran (CSV)',
        };
    }

    public function isPdf(): bool
    {
        return str_ends_with($this->value, '_pdf');
    }

    public function isExcel(): bool
    {
        return str_ends_with($this->value, '_excel');
    }

    /**
     * CSV adalah format kedua yang diminta PRD §3.10 ("Excel/CSV"); keduanya
     * dibangun dari objek `FromArray` yang sama, bedanya hanya writer-nya.
     */
    public function isCsv(): bool
    {
        return str_ends_with($this->value, '_csv');
    }

    /**
     * Excel maupun CSV: semua format tabel yang ditangani Maatwebsite Excel.
     */
    public function isSpreadsheet(): bool
    {
        return $this->isExcel() || $this->isCsv();
    }

    /**
     * Ekspor daftar transaksi (satu-satunya tanpa payload laporan).
     */
    public function isTransactionExport(): bool
    {
        return $this === self::TransactionsExcel || $this === self::TransactionsCsv;
    }

    /**
     * Ekstensi berkas, dipakai `ExportService` untuk nama file.
     */
    public function extension(): string
    {
        return match ($this) {
            self::CashFlowCsv, self::BudgetCsv, self::ExpenseCsv, self::TransactionsCsv => 'csv',
            default => $this->isPdf() ? 'pdf' : 'xlsx',
        };
    }

    /**
     * Bagian laporan yang diekspor, null untuk export transaksi.
     */
    public function reportKind(): ?string
    {
        return match ($this) {
            self::CashFlowPdf, self::CashFlowExcel, self::CashFlowCsv => 'cash-flow',
            self::BudgetPdf, self::BudgetExcel, self::BudgetCsv => 'budget-vs-actual',
            self::ExpensePdf, self::ExpenseExcel, self::ExpenseCsv => 'expense-breakdown',
            self::TransactionsExcel, self::TransactionsCsv => null,
        };
    }

    /**
     * Slug nama berkas.
     */
    public function slug(): string
    {
        return match ($this) {
            self::TransactionsExcel, self::TransactionsCsv => 'transaksi',
            self::CashFlowPdf, self::CashFlowExcel, self::CashFlowCsv => 'arus-kas',
            self::BudgetPdf, self::BudgetExcel, self::BudgetCsv => 'budget-vs-realisasi',
            self::ExpensePdf, self::ExpenseExcel, self::ExpenseCsv => 'komposisi-pengeluaran',
        };
    }
}
