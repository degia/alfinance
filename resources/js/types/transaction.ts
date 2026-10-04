export type TransactionType = 'income' | 'expense' | 'transfer';

export type TransactionStatus = 'posted' | 'pending';

/**
 * Bentuk ringkas untuk select akun/kategori/tag di form transaksi.
 */
export type TransactionOption = {
    id: number;
    name: string;
};

export type TransactionAccountOption = TransactionOption & {
    type: string;
    type_label: string;
};

export type TransactionTag = TransactionOption;

/**
 * Kategori milik sebuah transaksi. `parent_id`/`parent_name` terisi hanya
 * kalau transaksi memakai sub-kategori, supaya tabel bisa menulis
 * "Kategori / Sub" dan form edit bisa mengisi dua select terpisah.
 */
export type TransactionCategory = TransactionOption & {
    color: string;
    parent_id: number | null;
    parent_name: string | null;
};

/**
 * Utang yang harus dibayar untuk select "Bayar utang" di form transaksi.
 * Server sudah menyaring yang lunas dan mengurutkan yang paling mendesak dulu,
 * jadi frontend tinggal memaparkannya.
 */
export type TransactionDebtOption = {
    id: number;
    counterparty: string;
    remaining: string;
    due_date: string | null;
    status_label: string;
};

/**
 * Opsi kategori untuk select form dan filter: daftar datar dengan kategori
 * utama lebih dulu lalu sub-kategorinya, ditandai lewat `parent_id`.
 */
export type TransactionCategoryOption = {
    id: number;
    name: string;
    color: string;
    parent_id: number | null;
};

export type TransactionAttachment = {
    id: number;
    original_name: string;
    mime_type: string | null;
    human_size: string | null;
    url: string;
};

export type TransactionListItem = {
    id: number;
    type: TransactionType;
    type_label: string;
    status: TransactionStatus;
    status_label: string;
    is_pending: boolean;
    is_transfer: boolean;
    amount: string;
    /**
     * Nominal bertanda dari sudut pandang akun sumber: income `+`, expense/transfer
     * `-`. Disiapkan server supaya tabel tidak perlu tahu aturan tipe.
     */
    signed_amount: string;
    note: string | null;
    occurred_at: string;
    occurred_at_iso: string;
    account: { id: number; name: string; type: string } | null;
    transfer_to_account: { id: number; name: string; type: string } | null;
    category: TransactionCategory | null;
    tags: TransactionTag[];
    tag_ids: number[];
    attachments: TransactionAttachment[];
    is_recurring_instance: boolean;
    /**
     * Nominal potongan admin milik transfer ini, atau null kalau tidak punya.
     * Dipakai form edit untuk mengisi ulang field potongan admin.
     */
    admin_fee: string | null;
    /**
     * Kategori baris potongan admin milik transfer ini, untuk mengisi ulang
     * select kategori di form edit. `null` berarti memakai kategori otomatis
     * "Biaya Admin".
     */
    admin_fee_category_id: number | null;
    /** Baris ini adalah pengeluaran "Biaya Admin" hasil dari sebuah transfer. */
    is_admin_fee: boolean;
    /** Utang yang dilunasi expense ini, untuk mengisi select di form edit. */
    debt_id: number | null;
    debt: { id: number; counterparty: string } | null;
    /** Baris ini adalah pembayaran utang, bukan pengeluaran biasa. */
    is_debt_payment: boolean;
};

export type TransactionFilters = {
    from: string | null;
    to: string | null;
    account_id: number | null;
    category_id: number | null;
    tag_id: number | null;
    type: TransactionType | null;
    status: TransactionStatus | null;
    amount_min: string | null;
    amount_max: string | null;
    search: string | null;
};

export type TransactionSummary = {
    count: number;
    income: string;
    expense: string;
    /**
     * `income - expense` pada rentang filter aktif. Transfer tidak ikut karena
     * perpindahan uang internal bukan pendapatan.
     */
    net: string;
    pending_count: number;
};

export type TransactionPagination = {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
    prev_page_url: string | null;
    next_page_url: string | null;
};

export type TransactionOptions = {
    accounts: TransactionAccountOption[];
    categories: TransactionCategoryOption[];
    tags: TransactionTag[];
    /** Utang yang masih punya sisa, untuk select "Bayar utang". */
    debts: TransactionDebtOption[];
};

/**
 * Data form. `category_id` dan `transfer_to_account_id` sengaja berupa
 * `number | ''`: server menolak salah satunya per tipe transaksi, jadi form
 * harus benar-benar mengosongkan yang tidak relevan (bukan hanya menyembunyikan).
 */
export type TransactionFormData = {
    account_id: number | '';
    transfer_to_account_id: number | '';
    category_id: number | '';
    type: TransactionType;
    amount: string;
    /**
     * Potongan admin transfer dalam rupiah penuh (string desimal), bukan sen.
     * Hanya dikirim untuk tipe `transfer`; server menolaknya untuk tipe lain.
     */
    admin_fee: string;
    /**
     * Kategori untuk baris potongan admin. `''` berarti pakai kategori otomatis
     * "Biaya Admin", jadi tidak wajib diisi.
     */
    admin_fee_category_id: number | '';
    /**
     * Utang yang dilunasi expense ini. `''` berarti pengeluaran biasa; server
     * menolak field ini untuk income/transfer.
     */
    debt_id: number | '';
    occurred_at: string;
    note: string;
    tag_ids: number[];
    /**
     * Berkas baru. `null` berarti tidak ada berkas yang dipilih; lampiran lama
     * memakai `remove_attachment`.
     */
    attachment: File | null;
    remove_attachment: boolean;
};

export type RecurringFrequency = 'daily' | 'weekly' | 'monthly' | 'yearly';

export type RecurringRuleListItem = {
    id: number;
    type: TransactionType;
    type_label: string;
    amount: string;
    note: string | null;
    frequency: RecurringFrequency;
    frequency_label: string;
    next_run_at: string;
    end_date: string | null;
    requires_confirmation: boolean;
    is_active: boolean;
    last_generated_at: string | null;
    account: TransactionOption | null;
    transfer_to_account: TransactionOption | null;
    category: TransactionOption | null;
    tag_ids: number[];
    tags: TransactionTag[];
};

export type PendingTransaction = {
    id: number;
    type: TransactionType;
    type_label: string;
    amount: string;
    note: string | null;
    occurred_at: string;
    account: TransactionOption | null;
    category: TransactionOption | null;
    tags: string[];
    recurring_rule_id: number | null;
};

export type RecurringRuleOptions = {
    accounts: TransactionOption[];
    categories: TransactionOption[];
    tags: TransactionTag[];
    frequencies: { value: RecurringFrequency; label: string }[];
};

export type RecurringRuleFormData = {
    account_id: number | '';
    transfer_to_account_id: number | '';
    category_id: number | '';
    type: TransactionType;
    amount: string;
    note: string;
    tag_ids: number[];
    frequency: RecurringFrequency;
    next_run_at: string;
    end_date: string;
    requires_confirmation: boolean;
    is_active: boolean;
};
