export type NetWorthItemType = 'asset' | 'liability';

export type NetWorthSubtype =
    | 'cash'
    | 'deposit'
    | 'investment'
    | 'property'
    | 'vehicle'
    | 'other_asset'
    | 'loan'
    | 'credit_card'
    | 'mortgage'
    | 'other_liability';

export type NetWorthSubtypeOption = {
    value: NetWorthSubtype;
    label: string;
    icon: string;
};

export type NetWorthTypeOption = {
    value: NetWorthItemType;
    label: string;
    subtypes: NetWorthSubtypeOption[];
};

export type NetWorthItem = {
    id: number;
    type: NetWorthItemType;
    subtype: NetWorthSubtype;
    subtype_label: string;
    name: string;
    value: string;
    annual_rate: string | null;
    note: string | null;
    valued_at: string;
    /** Nilai berjalan per hari ini, sudah memperhitungkan `annual_rate`. */
    current_value: string;
    /** Proyeksi satu tahun ke depan dari `annual_rate` yang sama. */
    projected_value: string;
};

export type NetWorthCreditCard = {
    id: number;
    name: string;
    outstanding: string;
    credit_limit: string | null;
    available_credit: string | null;
    usage_percent: number | null;
};

export type NetWorthChange = {
    direction: 'up' | 'down' | 'flat';
    amount: string;
};

export type NetWorthSummary = {
    total_assets: string;
    total_liabilities: string;
    net_worth: string;
    item_assets: string;
    item_liabilities: string;
    credit_card_outstanding: string;
    debt_payable: string;
    debt_receivable: string;
    change: NetWorthChange | null;
};

export type NetWorthPoint = {
    month: string;
    label: string;
    /** `null` selama snapshot bulan itu belum dibuat. */
    net_worth: string | null;
    total_assets: string | null;
    total_liabilities: string | null;
    change: NetWorthChange | null;
    has_snapshot: boolean;
};

export type NetWorthIndexProps = {
    summary: NetWorthSummary;
    items: NetWorthItem[];
    credit_cards: NetWorthCreditCard[];
    series: NetWorthPoint[];
    year: number;
    years: number[];
    options: {
        types: NetWorthTypeOption[];
        months: { month: string; label: string }[];
    };
};

export type NetWorthItemFormData = {
    type: string;
    subtype: string;
    name: string;
    value: string;
    annual_rate: string;
    valued_at: string;
    note: string;
};
