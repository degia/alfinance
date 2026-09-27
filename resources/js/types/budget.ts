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
