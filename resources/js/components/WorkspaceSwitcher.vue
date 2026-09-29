<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Check, ChevronsUpDown, Plus } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useWorkspace } from '@/composables/useWorkspace';
import { index as workspacesIndex, switchMethod } from '@/routes/workspaces';
import type { WorkspaceRole } from '@/types';

const { workspace, workspaces } = useWorkspace();

const switching = ref<number | null>(null);

const roleLabels: Record<WorkspaceRole, string> = {
    owner: 'Owner',
    admin: 'Admin',
    member: 'Member',
    viewer: 'Viewer',
};

const hasMultiple = computed(() => workspaces.value.length > 1);

function switchTo(id: number): void {
    if (workspace.value?.id === id || switching.value !== null) {
        return;
    }

    switching.value = id;

    router.post(
        switchMethod(id),
        {},
        {
            preserveScroll: false,
            onFinish: () => {
                switching.value = null;
            },
        },
    );
}

function manageWorkspaces(): void {
    router.visit(workspacesIndex().url);
}
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <Button
                variant="ghost"
                class="neu-highlightable h-9 max-w-56 gap-2 rounded-xl px-2 shadow-neu-flat-sm hover:shadow-neu-hover"
            >
                <span
                    class="grid size-7 shrink-0 place-items-center rounded-lg bg-primary/10 text-xs font-semibold text-primary"
                >
                    {{ workspace?.name?.charAt(0)?.toUpperCase() ?? '?' }}
                </span>
                <span class="hidden truncate text-sm font-medium sm:block">
                    {{ workspace?.name ?? 'Pilih workspace' }}
                </span>
                <ChevronsUpDown class="size-4 shrink-0 opacity-60" />
            </Button>
        </DropdownMenuTrigger>

        <DropdownMenuContent align="start" class="w-72">
            <DropdownMenuLabel class="font-normal">
                <span class="text-xs text-muted-foreground">
                    Workspace aktif
                </span>
            </DropdownMenuLabel>

            <DropdownMenuSeparator />

            <div class="max-h-72 overflow-y-auto">
                <DropdownMenuItem
                    v-for="item in workspaces"
                    :key="item.id"
                    :disabled="switching !== null"
                    class="flex items-center gap-2"
                    @select="switchTo(item.id)"
                >
                    <span
                        class="grid size-7 shrink-0 place-items-center rounded-lg bg-primary/10 text-xs font-semibold text-primary"
                    >
                        {{ item.name.charAt(0).toUpperCase() }}
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-medium">
                            {{ item.name }}
                        </span>
                        <span class="block text-xs text-muted-foreground">
                            {{ roleLabels[item.role] }}
                        </span>
                    </span>
                    <Badge
                        v-if="item.is_active"
                        variant="secondary"
                        class="text-2xs"
                    >
                        Aktif
                    </Badge>
                    <Check
                        v-else-if="switching === item.id"
                        class="size-4 animate-pulse text-primary"
                    />
                </DropdownMenuItem>

                <p
                    v-if="!hasMultiple"
                    class="px-2 py-3 text-center text-xs text-muted-foreground"
                >
                    Kamu baru punya satu workspace.
                </p>
            </div>

            <DropdownMenuSeparator />

            <DropdownMenuItem
                :disabled="switching !== null"
                class="gap-2"
                @select="manageWorkspaces"
            >
                <Plus class="size-4" />
                <span class="flex-1">Kelola workspace</span>
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
