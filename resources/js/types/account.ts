export type AccountType =
    | 'cash'
    | 'bank'
    | 'ewallet'
    | 'saving'
    | 'credit_card';

export type AccountListItem = {
    id: number;
    name: string;
    type: AccountType;
    type_label: string;
    type_icon: string;
    is_credit: boolean;
    initial_balance: string;
    cached_balance: string;
    credit_limit: string | null;
    available_credit: string | null;
    credit_usage_percent: number | null;
    billing_day: number | null;
    due_day: number | null;
    next_billing_date: string | null;
    next_due_date: string | null;
    notes: string | null;
    archived_at: string | null;
    is_archived: boolean;
};

export type AccountFilters = {
    archived: boolean;
};

/**
 * Nominal uang selalu datang dari server sebagai string desimal agar presisi
 * DECIMAL(15,2) tidak hilang saat masuk ke JavaScript (lihat
 * `AccountRequest::accountAttributes`).
 */
export type AccountFormData = {
    name: string;
    type: AccountType;
    initial_balance: string;
    credit_limit: string;
    billing_day: string;
    due_day: string;
    notes: string;
};
