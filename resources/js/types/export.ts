/**
 * Tipe payload halaman Ekspor & Backup (PRD.md §3.10–3.11).
 *
 * Ekspor diproses lewat antrean (`export_jobs`), backup likewise (`backups`).
 * Halaman hanya mengirim permintaan dan mem-poll status; berkas diunduh lewat
 * route download masing-masing ketika statusnya `done`.
 */

/** Status satu proses ekspor (cerminan `App\Enums\ExportStatus`). */
export type ExportStatusValue = 'queued' | 'processing' | 'done' | 'failed';

/** Cakupan satu backup (cerminan `App\Enums\BackupScope`). */
export type BackupScopeValue = 'full' | 'range';

/** Status satu proses backup (cerminan `App\Enums\BackupStatus`). */
export type BackupStatusValue = 'queued' | 'done' | 'failed';

export type ExportOption = {
    value: string;
    label: string;
    is_pdf: boolean;
};

export type ExportRow = {
    id: number;
    type: string;
    label: string;
    is_pdf: boolean;
    status: ExportStatusValue;
    status_label: string;
    file_name: string | null;
    size_bytes: number | null;
    error: string | null;
    completed_at: string | null;
    created_at: string | null;
};

export type BackupRow = {
    id: number;
    scope: BackupScopeValue;
    scope_label: string;
    from: string | null;
    to: string | null;
    status: BackupStatusValue;
    status_label: string;
    file_name: string | null;
    size_bytes: number | null;
    error: string | null;
    summary: Record<string, number> | null;
    completed_at: string | null;
    created_at: string | null;
    can_restore: boolean;
};

/**
 * Statistik satu bagian data dari dry-run diff restore (dry-run sebelum apply).
 * `create` = id baru, `replace` = menimpa baris yang sudah ada, `skip` =
 * baris milik workspace lain yang dilewati, `retained` = baris sekarang yang
 * tidak ada di payload (tetap dipertahankan).
 */
export type BackupDiffSection = {
    current: number;
    payload: number;
    create: number;
    replace: number;
    skip: number;
    retained: number;
};

export type BackupDiff = {
    ok: boolean;
    errors?: string[];
    schema_version?: number;
    scope?: string;
    range?: { from: string | null; to: string | null } | null;
    workspace_id?: number;
    sections?: Record<string, BackupDiffSection>;
    overwrites_any?: boolean;
};

export type BackupResult = {
    schema_version: number;
    scope: string;
    sections: Record<string, BackupDiffSection>;
};

export type ExportsIndexProps = {
    exports: ExportRow[];
    backups: BackupRow[];
    export_options: ExportOption[];
    options: {
        accounts: { id: number; name: string }[];
        categories: { id: number; name: string }[];
        tags: { id: number; name: string }[];
    };
    max_months: number;
};
