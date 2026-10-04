/**
 * Tipe payload dashboard (PRD.md §3.1).
 *
 * Semua nominal tiba sebagai string desimal dari `Money` — presisi
 * `DECIMAL(15,2)` harus utuh sampai `formatCurrency` di frontend yang
 * membulatkan ke rupiah penuh.
 */

/** Arah dan besarnya selisih terhadap bulan sebelumnya. */
export type DashboardChange = {
    direction: 'up' | 'down' | 'flat';
    /** Nilai absolut selisih; arahnya ada di `direction`. */
    amount: string;
    /** Null bila pembanding bulan lalu nol — persentase tidak bermakna. */
    percent: number | null;
};

export type DashboardKpi = {
    month: string;
    month_label: string;
    /**
     * True ketika snapshot bulan ini belum ada. Angka masih zero-filled
     * supaya tidak ada null yang bocor ke chart; `generated_at` yang
     * memberi tahu apakah angkanya sudah final.
     */
    is_pending: boolean;
    total_balance: string;
    income: string;
    expense: string;
    net_cash_flow: string;
    total_transfer: string;
    transaction_count: number;
    has_previous: boolean;
    generated_at: string | null;
    income_change: DashboardChange | null;
    expense_change: DashboardChange | null;
    net_cash_flow_change: DashboardChange | null;
};

export type CashFlowPoint = {
    month: string;
    label: string;
    income: string;
    expense: string;
    net_cash_flow: string;
    /** False selama snapshot bulan itu belum dibuat. */
    has_data: boolean;
    is_current: boolean;
};

export type CashFlowTrend = {
    months: number;
    points: CashFlowPoint[];
    total_income: string;
    total_expense: string;
    net_cash_flow: string;
};

/** Satu hari dalam deret harian (sumber line chart dashboard). */
export type DailyCashFlowPoint = {
    /** `YYYY-MM-DD`. */
    date: string;
    /** Angka hari saja ("1".."31") untuk label sumbu X. */
    label: string;
    /** Tanggal lengkap ("9 Sep 2026") untuk tooltip. */
    tooltip_label: string;
    income: string;
    expense: string;
    net_cash_flow: string;
    /** False selama tanggal itu belum punya baris agregat harian. */
    has_data: boolean;
    is_today: boolean;
};

export type DailyCashFlow = {
    month: string;
    /**
     * True selama agregat harian belum pernah dibuat untuk bulan ini. Titik
     * tetap zero-filled supaya chart punya sumbu waktu yang lengkap; `has_data`
     * per titik yang membedakan "nol" dari "belum ada".
     */
    is_pending: boolean;
    /** Jumlah hari dalam bulan — selalu 28–31. */
    days: number;
    points: DailyCashFlowPoint[];
    total_income: string;
    total_expense: string;
    net_cash_flow: string;
};

export type ExpenseBreakdownItem = {
    category_id: number;
    name: string;
    color: string;
    icon: string;
    amount: string;
    /** Persentase dari total pengeluaran; null bila totalnya nol. */
    percent: number | null;
};

export type ExpenseBreakdown = {
    month: string;
    total: string;
    items: ExpenseBreakdownItem[];
    has_data: boolean;
};

export type DashboardRecentTransaction = {
    id: number;
    type: string;
    type_label: string;
    is_transfer: boolean;
    is_pending: boolean;
    amount: string;
    /** Bertanda sesuai arah arus kas: pemasukan positif, pengeluaran negatif. */
    signed_amount: string;
    note: string | null;
    occurred_at: string;
    account: { id: number; name: string } | null;
    category: { id: number; name: string; color: string } | null;
};

export type DashboardProps = {
    month: string;
    month_label: string;
    trend_months: number;
    kpi: DashboardKpi;
    cash_flow: CashFlowTrend;
    daily_cash_flow: DailyCashFlow;
    expense_breakdown: ExpenseBreakdown;
    recent_transactions: DashboardRecentTransaction[];
    health: FinancialHealth;
    trend_options: number[];
};

/** Satu metrik financial health dalam bentuk siap tampil (PRD.md §3.9). */
export type FinancialHealthMetric = {
    key: 'savings_rate' | 'dti' | 'emergency_fund_months';
    label: string;
    /** Null selama metriknya belum bisa dihitung (mis. belum ada pemasukan). */
    value: string | null;
    target: number;
    unit: 'percent' | 'months';
    is_healthy: boolean;
};

export type FinancialHealth = {
    month: string;
    month_label: string;
    /** True selama skor belum pernah dihitung untuk bulan ini. */
    is_pending: boolean;
    /** 0–100; nol saat `is_pending`. */
    score: number;
    label: string;
    label_text: string;
    /** Token warna yang sudah dipetakan server, mis. `bg-success`. */
    label_tone: string;
    /** False selama ada metrik yang belum bisa dihitung. */
    is_complete: boolean;
    metrics: FinancialHealthMetric[];
    recommendations: { metric: string; message: string }[];
    generated_at: string | null;
};
