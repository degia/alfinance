export type WorkspaceRole = 'owner' | 'admin' | 'member' | 'viewer';

export type ActiveWorkspace = {
    id: number;
    name: string;
    role: WorkspaceRole;
    is_owner: boolean;
};

export type WorkspaceSummary = ActiveWorkspace & {
    is_active: boolean;
};

export type WorkspaceListItem = ActiveWorkspace & {
    is_active: boolean;
    members_count: number;
};

/**
 * Workspace yang berada di "Sampah" — sudah di-soft delete, masih bisa
 * dipulihkan atau dihapus permanen oleh owner-nya.
 */
export type TrashedWorkspace = {
    id: number;
    name: string;
    members_count: number;
    deleted_at: string;
    deleted_at_diff: string;
};
