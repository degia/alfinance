export type DebtDirection = 'payable' | 'receivable';

export type DebtStatus = 'ongoing' | 'overdue' | 'settled';

export type DebtAccount = {
    id: number;
    name: string;
};

export type Debt = {
    id: number;
    direction: DebtDirection;
    direction_label: string;
    counterparty: string;
    principal: string;
    /** Sisa yang harus dibayar, selalu diturunkan dari riwayat cicilan. */
    remaining: string;
    paid: string;
    paid_percent: number;
    interest_rate: string | null;
    start_date: string | null;
    due_date: string | null;
    days_until_due: number | null;
    term_count: number | null;
    installment_amount: string | null;
    paid_term_count: number;
    status: DebtStatus;
    status_label: string;
    status_tone: string;
    include_in_net_worth: boolean;
    note: string | null;
    account: DebtAccount | null;
};

export type DebtPayment = {
    id: number;
    amount: string;
    paid_at: string;
    note: string | null;
    /** Transaksi expense/income yang dibuat bersamaan, kalau ada. */
    transaction_id: number | null;
};

export type DebtSummary = {
    count: number;
    total_remaining: string;
    total_overdue: string;
    payable: string;
    receivable: string;
};

export type DebtOption = {
    value: string;
    label: string;
};

export type DebtFormOptions = {
    directions: DebtOption[];
    accounts: DebtAccount[];
    categories: { id: number; name: string; parent_id: number | null }[];
};

export type DebtIndexProps = {
    debts: Debt[];
    status: DebtStatus;
    statuses: DebtOption[];
    summary: DebtSummary;
    options: DebtFormOptions;
};

export type DebtFormData = {
    direction: DebtDirection | '';
    counterparty: string;
    principal: string;
    interest_rate: string;
    start_date: string;
    due_date: string;
    term_count: string;
    include_in_net_worth: boolean;
    note: string;
    account_id: string;
};

export type DebtPaymentFormData = {
    amount: string;
    paid_at: string;
    note: string;
    create_transaction: boolean;
    transaction: {
        account_id: string;
        category_id: string;
        note: string;
    };
};
