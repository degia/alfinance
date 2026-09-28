/**
 * Tipe payload halaman laporan (PRD.md §3.8).
 *
 * Tiga laporan berbagi satu filter rentang bulan. `months` selalu berisi
 * seluruh bulan dalam rentang — termasuk yang belum punya data — supaya
 * lebar kolom seragam dan user bisa langsung membandingkan bulan ke bulan.
 */

/** Key bulan `Y-m` yang dipakai sebagai indeks `cells` dan `by_month`. */
export type ReportMonth = string;

export type ReportCashFlowPoint = {
    month: ReportMonth;
    label: string;
    income: string;
    expense: string;
    net_cash_flow: string;
    total_transfer: string;
    transaction_count: number;
    has_data: boolean;
};

export type ReportCashFlow = {
    from: ReportMonth;
    to: ReportMonth;
    months: ReportMonth[];
    points: ReportCashFlowPoint[];
    totals: {
        income: string;
        expense: string;
        net_cash_flow: string;
        total_transfer: string;
    };
    /**
     * True bila laporan dibaca dari agregasi khusus akun (bukan snapshot
     * bulanan), sehingga angkanya hanya mencakup satu akun.
     */
    is_account_filtered: boolean;
    has_data: boolean;
};

export type ReportBudgetCell = {
    limit: string;
    used: string;
    remaining: string;
    /** Persentase pemakaian limit; null bila belum ada limit. */
    percent: number | null;
    is_over: boolean;
};

export type BudgetMatrixCategory = {
    category_id: number;
    name: string;
    color: string;
    icon: string;
    cells: Record<ReportMonth, ReportBudgetCell>;
    limit_total: string;
    used_total: string;
    remaining_total: string;
    percent_total: number | null;
    is_over: boolean;
};

export type ReportBudgetVsActual = {
    from: ReportMonth;
    to: ReportMonth;
    months: ReportMonth[];
    categories: BudgetMatrixCategory[];
    totals: {
        limit: string;
        used: string;
        remaining: string;
        percent: number | null;
        /** Berapa kategori yang total pemakaiannya melewati limit. */
        over_count: number;
    };
    has_data: boolean;
};

export type ReportExpenseItem = {
    category_id: number;
    name: string;
    color: string;
    icon: string;
    total: string;
    by_month: Record<ReportMonth, string>;
    /** Persentase dari total pengeluaran rentang; null bila totalnya nol. */
    percent: number | null;
};

export type ReportExpenseBreakdown = {
    from: ReportMonth;
    to: ReportMonth;
    months: ReportMonth[];
    total: string;
    items: ReportExpenseItem[];
    months_totals: Record<ReportMonth, string>;
    top_categories: ReportExpenseItem[];
    has_data: boolean;
};

export type ReportIndexProps = {
    filters: {
        from: ReportMonth;
        to: ReportMonth;
        account_id: number | null;
        category_id: number | null;
    };
    options: {
        accounts: { id: number; name: string }[];
        categories: { id: number; name: string }[];
    };
    max_months: number;
    cash_flow: ReportCashFlow;
    budget_vs_actual: ReportBudgetVsActual;
    expense_breakdown: ReportExpenseBreakdown;
};
