<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { LogOut, Plus, RotateCcw, Trash2, Users } from '@lucide/vue';
import AppLogo from '@/components/AppLogo.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import {
    destroy as destroyWorkspace,
    forceDestroy,
    leave as leaveWorkspace,
    restore as restoreWorkspace,
    store,
    switchMethod,
} from '@/routes/workspaces';
import type {
    TrashedWorkspace,
    WorkspaceListItem,
    WorkspaceRole,
} from '@/types';

defineOptions({
    layout: {
        title: 'Pilih workspace',
        description: 'Pilih buku keuangan yang ingin kamu kelola',
    },
});

defineProps<{
    workspaces: WorkspaceListItem[];
    trashed: TrashedWorkspace[];
}>();

const form = useForm<{ name: string }>({ name: '' });

const roleLabels: Record<WorkspaceRole, string> = {
    owner: 'Owner',
    admin: 'Admin',
    member: 'Member',
    viewer: 'Viewer',
};

/**
 * Konfirmasi penghapusan.
 *
 * Satu workspace berisi seluruh buku keuangan, jadi menghapusnya menuntut dua
 * hal: dialog terpisah, dan — khusus hapus permanen — mengetik ulang nama
 * workspace. Mengetik nama memaksa membaca target yang dipilih, sehingga
 * salah klik pada baris yang keliru tidak berujung kehilangan data.
 */
type PendingAction = {
    id: number;
    name: string;
    mode: 'trash' | 'purge';
};

const pending = ref<PendingAction | null>(null);
const confirmName = ref('');
const processing = ref(false);

const isPurge = computed(() => pending.value?.mode === 'purge');

const canConfirm = computed(() => {
    if (!pending.value || processing.value) {
        return false;
    }

    return !isPurge.value || confirmName.value.trim() === pending.value.name;
});

watch(pending, () => {
    confirmName.value = '';
});

function switchTo(id: number): void {
    router.post(switchMethod(id));
}

function submit(): void {
    form.post(store().url);
}

function leave(id: number): void {
    router.delete(leaveWorkspace(id), { preserveScroll: true });
}

function askTrash(item: WorkspaceListItem): void {
    pending.value = { id: item.id, name: item.name, mode: 'trash' };
}

function askPurge(item: TrashedWorkspace): void {
    pending.value = { id: item.id, name: item.name, mode: 'purge' };
}

function restore(id: number): void {
    router.post(restoreWorkspace(id), {}, { preserveScroll: true });
}

function confirmAction(): void {
    const action = pending.value;

    if (!action || !canConfirm.value) {
        return;
    }

    const route =
        action.mode === 'purge'
            ? forceDestroy(action.id)
            : destroyWorkspace(action.id);

    processing.value = true;

    router.delete(route, {
        preserveScroll: true,
        onFinish: () => {
            processing.value = false;
            pending.value = null;
        },
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
                <div
                    v-for="item in workspaces"
                    :key="item.id"
                    class="group neu-highlightable flex w-full items-center gap-3 rounded-xl p-3 text-left hover:shadow-neu-flat-sm"
                    :class="item.is_active ? 'shadow-neu-pressed-sm' : ''"
                >
                    <button
                        type="button"
                        class="flex min-w-0 flex-1 items-center gap-3 rounded-lg text-left focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
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
                    </button>

                    <Button
                        v-if="item.is_owner"
                        variant="ghost"
                        size="sm"
                        class="neu-color shrink-0 text-muted-foreground opacity-0 group-hover:opacity-100 focus-visible:opacity-100"
                        :aria-label="`Hapus workspace ${item.name}`"
                        @click="askTrash(item)"
                    >
                        <Trash2 class="size-4" />
                        Hapus
                    </Button>

                    <Button
                        v-else
                        variant="ghost"
                        size="sm"
                        class="neu-color shrink-0 text-muted-foreground opacity-0 group-hover:opacity-100 focus-visible:opacity-100"
                        :aria-label="`Keluar dari workspace ${item.name}`"
                        @click="leave(item.id)"
                    >
                        <LogOut class="size-4" />
                        Keluar
                    </Button>
                </div>
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

        <!-- Sampah: workspace yang sudah dihapus tapi datanya masih utuh. -->
        <Card
            v-if="trashed.length > 0"
            class="rounded-2xl border-0 shadow-neu-flat"
        >
            <CardContent class="flex flex-col gap-3">
                <div class="flex flex-col gap-1">
                    <h3 class="text-sm font-semibold">Sampah</h3>
                    <p class="text-xs text-muted-foreground">
                        Workspace di sini tidak muncul di daftar aktif, tapi
                        datanya masih utuh dan bisa dipulihkan kapan saja.
                    </p>
                </div>

                <div
                    v-for="item in trashed"
                    :key="item.id"
                    class="flex items-center gap-3 rounded-xl p-3 shadow-neu-inset"
                >
                    <span
                        class="grid size-10 shrink-0 place-items-center rounded-xl bg-muted text-sm font-semibold text-muted-foreground"
                    >
                        {{ item.name.charAt(0).toUpperCase() }}
                    </span>

                    <span class="min-w-0 flex-1">
                        <span class="block truncate font-medium">
                            {{ item.name }}
                        </span>
                        <span class="text-xs text-muted-foreground">
                            Dihapus {{ item.deleted_at_diff }} ·
                            {{ item.members_count }} anggota
                        </span>
                    </span>

                    <Button
                        variant="ghost"
                        size="sm"
                        class="shrink-0"
                        :aria-label="`Pulihkan workspace ${item.name}`"
                        @click="restore(item.id)"
                    >
                        <RotateCcw class="size-4" />
                        Pulihkan
                    </Button>

                    <Button
                        variant="ghost"
                        size="sm"
                        class="shrink-0 text-destructive"
                        :aria-label="`Hapus permanen workspace ${item.name}`"
                        @click="askPurge(item)"
                    >
                        <Trash2 class="size-4" />
                        Hapus permanen
                    </Button>
                </div>
            </CardContent>
        </Card>
    </div>

    <Dialog
        :open="pending !== null"
        @update:open="(open) => !open && (pending = null)"
    >
        <DialogContent>
            <DialogHeader class="space-y-3">
                <DialogTitle>
                    {{
                        isPurge
                            ? `Hapus permanen ${pending?.name}?`
                            : `Pindahkan ${pending?.name} ke Sampah?`
                    }}
                </DialogTitle>
                <DialogDescription v-if="isPurge">
                    Seluruh transaksi, akun, anggaran, utang, lampiran, dan
                    backup milik workspace ini akan dihapus permanen. Tindakan
                    ini tidak bisa dibatalkan. Ketik
                    <strong class="text-foreground">{{ pending?.name }}</strong>
                    untuk mengonfirmasi.
                </DialogDescription>
                <DialogDescription v-else>
                    Workspace akan hilang dari daftar dan tidak bisa dibuka
                    anggotanya, tapi datanya tetap tersimpan. Kamu bisa
                    memulihkannya dari Sampah kapan saja.
                </DialogDescription>
            </DialogHeader>

            <div v-if="isPurge" class="grid gap-2">
                <Label for="confirm-workspace-name" class="sr-only">
                    Nama workspace
                </Label>
                <Input
                    id="confirm-workspace-name"
                    v-model="confirmName"
                    v-focus
                    autocomplete="off"
                    :placeholder="pending?.name"
                    class="shadow-neu-inset"
                />
            </div>

            <DialogFooter class="gap-2">
                <Button variant="secondary" @click="pending = null">
                    Batal
                </Button>
                <Button
                    variant="destructive"
                    :disabled="!canConfirm"
                    @click="confirmAction"
                >
                    <Spinner v-if="processing" class="size-4" />
                    {{ isPurge ? 'Hapus permanen' : 'Pindahkan ke Sampah' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
