import type { UserRole } from './auth';

export type AdminUserStatus =
    | 'all'
    | 'online'
    | 'admin'
    | 'suspended'
    | 'unverified'
    | 'never_logged_in';

export type AdminUserSort =
    | 'name'
    | 'email'
    | 'created_at'
    | 'last_login_at'
    | 'last_seen_at';

export type AdminUserRow = {
    id: number;
    name: string;
    email: string;
    role: UserRole;
    role_label: string;
    is_online: boolean;
    is_suspended: boolean;
    suspended_reason: string | null;
    is_verified: boolean;
    two_factor_enabled: boolean;
    workspaces_count: number;
    last_login_at_diff: string | null;
    last_seen_at_diff: string | null;
    last_login_ip: string | null;
    created_at: string | null;
    created_at_diff: string | null;
};

export type AdminUserFilters = {
    search: string | null;
    status: AdminUserStatus;
    sort: AdminUserSort;
    direction: 'asc' | 'desc';
};

/**
 * Bentuk paginator Laravel (`LengthAwarePaginator`) sesudah `through()`.
 */
export type AdminUserPagination = {
    data: AdminUserRow[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
    prev_page_url: string | null;
    next_page_url: string | null;
};

export type AdminSummary = {
    users: {
        total: number;
        admins: number;
        verified: number;
        unverified: number;
        suspended: number;
        two_factor: number;
        online: number;
        active_today: number;
        never_logged_in: number;
        new_today: number;
        new_this_week: number;
        new_this_month: number;
    };
    workspaces: {
        total: number;
        trashed: number;
    };
    transactions: {
        total: number;
    };
};

export type AdminTrendPoint = {
    date: string;
    label: string;
    count: number;
};

export type AdminActiveUser = {
    id: number;
    name: string;
    email: string;
    is_online: boolean;
    last_seen_at_diff: string | null;
    last_login_at_diff: string | null;
    last_login_ip: string | null;
};

export type AdminRecentUser = {
    id: number;
    name: string;
    email: string;
    is_verified: boolean;
    created_at_diff: string | null;
};

export type AdminSystemCheck = {
    name: string;
    status: 'ok' | 'down';
    detail: string;
};

export type AdminEmailSegment = 'all' | 'verified' | 'unverified' | 'active';

export type AdminEmailRow = {
    id: number;
    name: string;
    email: string;
    is_verified: boolean;
    is_suspended: boolean;
};
