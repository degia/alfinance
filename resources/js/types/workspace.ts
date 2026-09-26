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
