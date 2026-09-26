import { usePage } from '@inertiajs/vue3';
import type { ComputedRef, DeepReadonly } from 'vue';
import { computed, readonly } from 'vue';
import type { ActiveWorkspace, WorkspaceSummary } from '@/types/workspace';

/**
 * Akses read-only ke workspace aktif & daftar workspace milik user.
 *
 * Data berasal dari shared props Inertia (lihat HandleInertiaRequests), jadi
 * selalu sinkron dengan state server tanpa request tambahan.
 */
export type UseWorkspaceReturn = {
    workspace: DeepReadonly<ComputedRef<ActiveWorkspace | null>>;
    workspaces: DeepReadonly<ComputedRef<WorkspaceSummary[]>>;
    hasWorkspace: DeepReadonly<ComputedRef<boolean>>;
};

export function useWorkspace(): UseWorkspaceReturn {
    const workspace = computed(
        () => (usePage().props.workspace ?? null) as ActiveWorkspace | null,
    );

    const workspaces = computed(
        () => (usePage().props.workspaces ?? []) as WorkspaceSummary[],
    );

    return {
        workspace: readonly(workspace),
        workspaces: readonly(workspaces),
        hasWorkspace: readonly(computed(() => workspace.value !== null)),
    };
}
