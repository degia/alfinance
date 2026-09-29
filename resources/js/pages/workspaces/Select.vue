<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { LogOut, Plus, Users } from '@lucide/vue';
import AppLogo from '@/components/AppLogo.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import {
    index as workspacesIndex,
    leave as leaveWorkspace,
    store,
    switchMethod,
} from '@/routes/workspaces';
import type { WorkspaceListItem, WorkspaceRole } from '@/types';

defineOptions({
    layout: {
        title: 'Pilih workspace',
        description: 'Pilih buku keuangan yang ingin kamu kelola',
    },
});

defineProps<{
    workspaces: WorkspaceListItem[];
}>();

const form = useForm<{ name: string }>({ name: '' });

const roleLabels: Record<WorkspaceRole, string> = {
    owner: 'Owner',
    admin: 'Admin',
    member: 'Member',
    viewer: 'Viewer',
};

function switchTo(id: number): void {
    router.post(switchMethod(id));
}

function submit(): void {
    form.post(store().url);
}

function leave(id: number): void {
    router.delete(leaveWorkspace(id), {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head title="Pilih workspace" />

    <div class="mx-auto flex w-full max-w-3xl flex-col gap-6">
        <div class="flex flex-col items-center gap-4 text-center">
            <AppLogo class="h-auto w-auto" />

            <div class="space-y-1">
                <Heading
                    variant="small"
                    title="Pilih workspace"
                    description="Setiap workspace adalah satu buku keuangan yang berdiri sendiri."
                />
            </div>
        </div>

        <Card
            v-if="workspaces.length > 0"
            class="rounded-2xl border-0 shadow-neu-flat"
        >
            <CardContent class="flex flex-col gap-3">
                <button
                    v-for="item in workspaces"
                    :key="item.id"
                    type="button"
                    class="group neu-highlightable flex w-full items-center gap-3 rounded-xl p-3 text-left hover:shadow-neu-flat-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    :class="item.is_active ? 'shadow-neu-pressed-sm' : ''"
                    @click="switchTo(item.id)"
                >
                    <span
                        class="grid size-10 shrink-0 place-items-center rounded-xl bg-primary/10 text-sm font-semibold text-primary"
                    >
                        {{ item.name.charAt(0).toUpperCase() }}
                    </span>

                    <span class="min-w-0 flex-1">
                        <span class="flex items-center gap-2">
                            <span class="truncate font-medium">
                                {{ item.name }}
                            </span>
                            <Badge variant="secondary" class="text-2xs">
                                {{ roleLabels[item.role] }}
                            </Badge>
                        </span>
                        <span
                            class="mt-0.5 flex items-center gap-1 text-xs text-muted-foreground"
                        >
                            <Users class="size-3" />
                            {{ item.members_count }} anggota
                        </span>
                    </span>

                    <Button
                        v-if="!item.is_owner"
                        variant="ghost"
                        size="sm"
                        class="neu-color text-muted-foreground opacity-0 group-hover:opacity-100 focus-visible:opacity-100"
                        @click.stop="leave(item.id)"
                    >
                        <LogOut class="size-4" />
                        Keluar
                    </Button>
                </button>
            </CardContent>
        </Card>

        <Card v-else class="rounded-2xl border-0 shadow-neu-flat">
            <CardContent class="text-center text-sm text-muted-foreground">
                Kamu belum punya workspace. Buat satu untuk mulai mencatat
                keuangan.
            </CardContent>
        </Card>

        <Card class="rounded-2xl border-0 shadow-neu-flat">
            <CardContent>
                <form class="flex flex-col gap-4" @submit.prevent="submit">
                    <div class="grid gap-2">
                        <Label for="workspace-name">
                            Buat workspace baru
                        </Label>
                        <div class="flex flex-col gap-3 sm:flex-row">
                            <Input
                                id="workspace-name"
                                v-model="form.name"
                                name="name"
                                type="text"
                                required
                                minlength="3"
                                maxlength="60"
                                autocomplete="off"
                                placeholder="Contoh: Usaha Kopi"
                                class="shadow-neu-inset"
                                :aria-invalid="Boolean(form.errors.name)"
                            />
                            <Button
                                type="submit"
                                class="gap-2 sm:w-44"
                                :disabled="form.processing"
                            >
                                <Spinner
                                    v-if="form.processing"
                                    class="size-4"
                                />
                                <Plus v-else class="size-4" />
                                Buat
                            </Button>
                        </div>
                        <InputError :message="form.errors.name" />
                        <p class="text-xs text-muted-foreground">
                            Kamu akan menjadi owner workspace ini.
                        </p>
                    </div>
                </form>
            </CardContent>
        </Card>
    </div>
</template>
