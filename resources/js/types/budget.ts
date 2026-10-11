export type BudgetStatus = 'unset' | 'healthy' | 'warning' | 'over';

export type BudgetCell = {
    /** Kunci bulan "YYYY-MM". */
    month: string;
    /** `null` selama limit bulan ini belum diisi. */
    budget_id: number | null;
    /** Nominal limit sebagai string desimal, `null` kalau belum diisi. */
    limit: string | null;
    /** Selalu string, bahkan tanpa cache: nol berarti belum ada pemakaian. */
    used: string;
    remaining: string | null;
    /** Persentase tanpa cap, jadi bisa di atas 100. */
    percent: number | null;
    status: BudgetStatus;
};

export type BudgetRow = {
    id: number;
    name: string;
    color: string | null;
    is_nested: boolean;
    is_child: boolean;
    months: BudgetCell[];
};

export type BudgetMonthColumn = {
    month: string;
    label: string;
};

export type BudgetCategoryOption = {
    id: number;
    name: string;
    parent_id: number | null;
    color: string | null;
};

export type BudgetSummary = {
    total_limit: string;
    total_used: string;
    total_remaining: string;
    percent: number | null;
    status: BudgetStatus;
    by_status: Record<BudgetStatus, number>;
};

export type BudgetIndexProps = {
    month: string;
    month_label: string;
    year: number;
    categories: BudgetRow[];
    months: BudgetMonthColumn[];
    summary: BudgetSummary;
    options: {
        categories: BudgetCategoryOption[];
        months: BudgetMonthColumn[];
    };
};

export type BudgetFormData = {
    category_id: string;
    month: string;
    limit_amount: string;
};

export type BudgetDetailCategory = {
    id: number;
    name: string;
    color: string | null;
    parent_id: number | null;
    parent_name: string | null;
};

/**
 * Ringkasan limit di modal detail ("cursor view").
 */
export type BudgetDetail = {
    id: number;
    month: string;
    month_label: string;
    category: BudgetDetailCategory;
    limit: string;
    used: string;
    remaining: string;
    percent: number | null;
    status: BudgetStatus;
    /** `true` bila limit induk ikut menghitung transaksi sub-kategorinya. */
    includes_subcategories: boolean;
};

/**
 * Satu transaksi yang memotong limit — cukup untuk daftar di modal detail.
 */
export type BudgetDetailTransaction = {
    id: number;
    occurred_at: string;
    amount: string;
    note: string | null;
    account_name: string | null;
    category_name: string | null;
    category_parent_name: string | null;
};

export type BudgetDetailResponse = {
    budget: BudgetDetail;
    transactions: BudgetDetailTransaction[];
    /** Cursor halaman berikutnya, `null` bila sudah habis. */
    next_cursor: string | null;
    has_more: boolean;
};
