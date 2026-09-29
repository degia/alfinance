<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import {
    Database,
    Download,
    FileSpreadsheet,
    FileText,
    History,
    RotateCcw,
    Trash2,
} from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { useWorkspace } from '@/composables/useWorkspace';
import {
    download as downloadExport,
    index as exportsIndex,
    store as storeExport,
} from '@/routes/exports';
import {
    download as downloadBackup,
    preview as previewBackup,
    restore as restoreBackup,
    store as storeBackup,
} from '@/routes/backups';
import type {
    BackupDiff,
    BackupDiffSection,
    BackupResult,
    BackupRow,
    ExportRow,
    ExportsIndexProps,
    ExportStatusValue,
} from '@/types';

defineOptions({
    layout: {
        title: 'Ekspor & Backup',
        description:
            'Unduh transaksi & laporan, atau cadangkan data workspace sebagai JSON',
        breadcrumbs: [
            {
                title: 'Ekspor & Backup',
                href: exportsIndex(),
            },
        ],
    },
});

const props = defineProps<ExportsIndexProps>();

const page = usePage();
const { workspace } = useWorkspace();

/** Viewer boleh melihat & mengunduh, tapi tidak membuat proses baru. */
const canEdit = computed(() => workspace.value?.role !== 'viewer');

const NONE = '__none__';

type StatusTone = {
    badge: string;
    dot: string;
};

const statusTone: Record<ExportStatusValue, StatusTone> = {
    queued: {
        badge: 'border-amber-500/30 bg-amber-500/10 text-amber-700 dark:text-amber-400',
        dot: 'bg-amber-500',
    },
    processing: {
        badge: 'border-sky-500/30 bg-sky-500/10 text-sky-700 dark:text-sky-400',
        dot: 'bg-sky-500',
    },
    done: {
        badge: 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-400',
        dot: 'bg-emerald-500',
    },
    failed: {
        badge: 'border-rose-500/30 bg-rose-500/10 text-rose-700 dark:text-rose-400',
        dot: 'bg-rose-500',
    },
};

/** Label rapi untuk kunci tabel pada payload backup. */
const sectionLabels: Record<string, string> = {
    accounts: 'Akun',
    categories: 'Kategori',
    tags: 'Tag',
    recurring_rules: 'Transaksi berulang',
    transactions: 'Transaksi',
    budgets: 'Anggaran',
    net_worth_items: 'Aset & liability net worth',
    debts: 'Utang & piutang',
    debt_payments: 'Pembayaran utang',
    attachments: 'Lampiran',
    transaction_tag: 'Tag transaksi',
};

const exportForm = useForm({
    type: 'transactions_excel',
    from: '',
    to: '',
    account_id: NONE,
    category_id: NONE,
    tag_id: NONE,
    tx_type: NONE,
    tx_status: NONE,
    amount_min: '',
    amount_max: '',
});

/** Filter tipe & status hanya relevan untuk ekspor transaksi. */
const isTransactionExport = computed(() =>
    exportForm.type.startsWith('transactions_'),
);

const backupForm = useForm({
    scope: 'full',
    from: '',
    to: '',
});

function submitExport(): void {
    exportForm
        .transform((data) => ({
            ...data,
            account_id: data.account_id === NONE ? null : data.account_id,
            category_id: data.category_id === NONE ? null : data.category_id,
            tag_id: data.tag_id === NONE ? null : data.tag_id,
            tx_type: data.tx_type === NONE ? null : data.tx_type,
            tx_status: data.tx_status === NONE ? null : data.tx_status,
            amount_min: data.amount_min === '' ? null : data.amount_min,
            amount_max: data.amount_max === '' ? null : data.amount_max,
        }))
        .post(storeExport().url, {
            preserveScroll: true,
            onSuccess: () =>
                exportForm.reset('type', 'amount_min', 'amount_max'),
        });
}

function submitBackup(): void {
    backupForm
        .transform((data) =>
            data.scope === 'full' ? { scope: data.scope } : data,
        )
        .post(storeBackup().url, {
            preserveScroll: true,
            onSuccess: () => backupForm.defaults(),
        });
}

/**
 * Status proses berubah di worker, jadi daftar di-poll selama masih ada yang
 * `queued`/`processing`. Hanya dua prop yang di-refresh supaya filter di form
 * tidak ikut ter-reset.
 */
const hasPending = computed(
    () =>
        props.exports.some(
            (row) => row.status === 'queued' || row.status === 'processing',
        ) || props.backups.some((row) => row.status === 'queued'),
);

let poller: number | null = null;

function stopPolling(): void {
    if (poller !== null) {
        window.clearInterval(poller);
        poller = null;
    }
}

function startPolling(): void {
    stopPolling();
    poller = window.setInterval(() => {
        if (document.visibilityState === 'hidden') {
            return;
        }

        router.reload({ only: ['exports', 'backups'] });
    }, 4000);
}

watch(hasPending, (pending) => (pending ? startPolling() : stopPolling()), {
    immediate: true,
});

onBeforeUnmount(stopPolling);

// Dry-run diff & ringkasan hasil restore datang sebagai shared prop yang di-pull
// satu kali dari session, jadi cukup dibaca saat berubah.
const diff = ref<BackupDiff | null>(null);
const diffBackup = ref<BackupRow | null>(null);
const result = ref<BackupResult | null>(null);
const isDiffOpen = ref(false);
const isRestoreOpen = ref(false);

watch(
    () => page.props.backup_diff,
    (value) => {
        if (!value) {
            return;
        }

        diff.value = value as BackupDiff;
        isDiffOpen.value = true;
    },
);

watch(
    () => page.props.backup_result,
    (value) => {
        if (!value) {
            return;
        }

        result.value = value as BackupResult;
        isDiffOpen.value = false;
    },
);

function openPreview(backup: { id: number }): void {
    router.post(previewBackup(backup.id).url, {}, { preserveScroll: true });
}

function openRestore(backup: BackupRow): void {
    diffBackup.value = backup;
    isRestoreOpen.value = true;
}

function confirmRestore(): void {
    if (diffBackup.value === null) {
        return;
    }

    router.post(
        restoreBackup(diffBackup.value.id).url,
        {},
        {
            preserveScroll: true,
            onSuccess: () => (isRestoreOpen.value = false),
        },
    );
}

const diffSections = computed<
    { key: string; label: string; stats: BackupDiffSection }[]
>(() => {
    const sections = diff.value?.sections ?? {};

    return Object.entries(sections)
        .filter(([, stats]) => stats.payload > 0 || stats.current > 0)
        .map(([key, stats]) => ({
            key,
            label: sectionLabels[key] ?? key,
            stats,
        }));
});

const diffTotals = computed(() =>
    diffSections.value.reduce(
        (acc, section) => ({
            create: acc.create + section.stats.create,
            replace: acc.replace + section.stats.replace,
            skip: acc.skip + section.stats.skip,
        }),
        { create: 0, replace: 0, skip: 0 },
    ),
);

const exportSummary = computed(() => ({
    queued: props.exports.filter(
        (row) => row.status === 'queued' || row.status === 'processing',
    ).length,
    done: props.exports.filter((row) => row.status === 'done').length,
    failed: props.exports.filter((row) => row.status === 'failed').length,
}));

function formatBytes(bytes: number | null): string {
    if (bytes === null || bytes <= 0) {
        return '-';
    }

    const units = ['B', 'KB', 'MB', 'GB'];
    const exponent = Math.min(
        Math.floor(Math.log(bytes) / Math.log(1024)),
        units.length - 1,
    );
    const value = bytes / 1024 ** exponent;

    return `${value.toFixed(exponent === 0 ? 0 : 1)} ${units[exponent]}`;
}

function formatDateTime(value: string | null): string {
    if (!value) {
        return '-';
    }

    return new Intl.DateTimeFormat('id-ID', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    }).format(new Date(value));
}

function exportIcon(row: ExportRow) {
    return row.is_pdf ? FileText : FileSpreadsheet;
}

function isPendingRow(row: ExportRow): boolean {
    return row.status === 'queued' || row.status === 'processing';
}
</script>

<template>
    <Head title="Ekspor & Backup" />

    <div class="flex flex-col gap-6">
        <Heading
            title="Ekspor & Backup"
            description="Semua berkas dibuat di antrean. Halaman ini memantau progres, lalu mengunduh hasilnya ketika selesai."
        />

        <Alert
            v-if="!canEdit"
            class="rounded-2xl border-amber-500/30 bg-amber-500/10"
        >
            <AlertTitle class="text-amber-700 dark:text-amber-400">
                Mode read-only
            </AlertTitle>
            <AlertDescription>
                Peran viewer bisa mengunduh berkas yang sudah jadi, tetapi tidak
                bisa membuat ekspor atau backup baru.
            </AlertDescription>
        </Alert>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <Card class="rounded-2xl border-0 shadow-neu-flat">
                <CardContent>
                    <p
                        class="text-xs tracking-wide text-muted-foreground uppercase"
                    >
                        Sedang diproses
                    </p>
                    <p class="mt-1 text-xl font-semibold tabular-nums">
                        {{ exportSummary.queued }}
                    </p>
                </CardContent>
            </Card>
            <Card class="rounded-2xl border-0 shadow-neu-flat">
                <CardContent>
                    <p
                        class="text-xs tracking-wide text-muted-foreground uppercase"
                    >
                        Selesai
                    </p>
                    <p
                        class="mt-1 text-xl font-semibold text-income tabular-nums"
                    >
                        {{ exportSummary.done }}
                    </p>
                </CardContent>
            </Card>
            <Card class="rounded-2xl border-0 shadow-neu-flat">
                <CardContent>
                    <p
                        class="text-xs tracking-wide text-muted-foreground uppercase"
                    >
                        Gagal
                    </p>
                    <p
                        class="mt-1 text-xl font-semibold text-expense tabular-nums"
                    >
                        {{ exportSummary.failed }}
                    </p>
                </CardContent>
            </Card>
        </div>

        <div class="grid gap-6 xl:grid-cols-2">
            <Card class="rounded-2xl border-0 shadow-neu-flat">
                <CardContent class="flex flex-col gap-4">
                    <div class="flex items-center gap-2">
                        <Download class="size-4 text-muted-foreground" />
                        <h2 class="text-sm font-semibold">Buat ekspor</h2>
                    </div>

                    <form class="grid gap-4" @submit.prevent="submitExport">
                        <div class="grid gap-2">
                            <Label>Jenis berkas</Label>
                            <Select v-model="exportForm.type">
                                <SelectTrigger class="w-full shadow-neu-inset">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="option in props.export_options"
                                        :key="option.value"
                                        :value="option.value"
                                    >
                                        {{ option.label }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <InputError :message="exportForm.errors.type" />
                        </div>

                        <div class="grid gap-2 sm:grid-cols-2">
                            <div class="grid gap-2">
                                <Label for="export-from">Dari tanggal</Label>
                                <Input
                                    id="export-from"
                                    v-model="exportForm.from"
                                    type="date"
                                    class="shadow-neu-inset"
                                />
                                <InputError :message="exportForm.errors.from" />
                            </div>
                            <div class="grid gap-2">
                                <Label for="export-to">Sampai tanggal</Label>
                                <Input
                                    id="export-to"
                                    v-model="exportForm.to"
                                    type="date"
                                    class="shadow-neu-inset"
                                />
                                <InputError :message="exportForm.errors.to" />
                            </div>
                        </div>

                        <div class="grid gap-2 sm:grid-cols-2">
                            <div class="grid gap-2">
                                <Label>Akun</Label>
                                <Select v-model="exportForm.account_id">
                                    <SelectTrigger
                                        class="w-full shadow-neu-inset"
                                    >
                                        <SelectValue placeholder="Semua akun" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem :value="NONE">
                                            Semua akun
                                        </SelectItem>
                                        <SelectItem
                                            v-for="account in props.options
                                                .accounts"
                                            :key="account.id"
                                            :value="String(account.id)"
                                        >
                                            {{ account.name }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                <InputError
                                    :message="exportForm.errors.account_id"
                                />
                            </div>
                            <div class="grid gap-2">
                                <Label>Kategori</Label>
                                <Select v-model="exportForm.category_id">
                                    <SelectTrigger
                                        class="w-full shadow-neu-inset"
                                    >
                                        <SelectValue
                                            placeholder="Semua kategori"
                                        />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem :value="NONE">
                                            Semua kategori
                                        </SelectItem>
                                        <SelectItem
                                            v-for="category in props.options
                                                .categories"
                                            :key="category.id"
                                            :value="String(category.id)"
                                        >
                                            {{ category.name }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                <InputError
                                    :message="exportForm.errors.category_id"
                                />
                            </div>
                        </div>

                        <div class="grid gap-2 sm:grid-cols-2">
                            <div class="grid gap-2">
                                <Label>Tag</Label>
                                <Select v-model="exportForm.tag_id">
                                    <SelectTrigger
                                        class="w-full shadow-neu-inset"
                                    >
                                        <SelectValue placeholder="Semua tag" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem :value="NONE">
                                            Semua tag
                                        </SelectItem>
                                        <SelectItem
                                            v-for="tag in props.options.tags"
                                            :key="tag.id"
                                            :value="String(tag.id)"
                                        >
                                            {{ tag.name }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                <InputError
                                    :message="exportForm.errors.tag_id"
                                />
                            </div>
                            <div class="grid gap-2">
                                <Label for="export-amount-min">
                                    Nominal minimum
                                </Label>
                                <Input
                                    id="export-amount-min"
                                    v-model="exportForm.amount_min"
                                    inputmode="decimal"
                                    placeholder="0"
                                    class="shadow-neu-inset"
                                />
                                <InputError
                                    :message="exportForm.errors.amount_min"
                                />
                            </div>
                        </div>

                        <div
                            v-if="isTransactionExport"
                            class="grid gap-2 sm:grid-cols-2"
                        >
                            <div class="grid gap-2">
                                <Label>Tipe transaksi</Label>
                                <Select v-model="exportForm.tx_type">
                                    <SelectTrigger
                                        class="w-full shadow-neu-inset"
                                    >
                                        <SelectValue placeholder="Semua tipe" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem :value="NONE">
                                            Semua tipe
                                        </SelectItem>
                                        <SelectItem value="income">
                                            Pemasukan
                                        </SelectItem>
                                        <SelectItem value="expense">
                                            Pengeluaran
                                        </SelectItem>
                                        <SelectItem value="transfer">
                                            Transfer
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                <InputError
                                    :message="exportForm.errors.tx_type"
                                />
                            </div>
                            <div class="grid gap-2">
                                <Label>Status transaksi</Label>
                                <Select v-model="exportForm.tx_status">
                                    <SelectTrigger
                                        class="w-full shadow-neu-inset"
                                    >
                                        <SelectValue
                                            placeholder="Semua status"
                                        />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem :value="NONE">
                                            Semua status
                                        </SelectItem>
                                        <SelectItem value="posted">
                                            Diposting
                                        </SelectItem>
                                        <SelectItem value="pending">
                                            Menunggu
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                <InputError
                                    :message="exportForm.errors.tx_status"
                                />
                            </div>
                        </div>

                        <div class="flex items-center justify-end gap-2">
                            <Button
                                type="submit"
                                :disabled="!canEdit || exportForm.processing"
                            >
                                <Spinner v-if="exportForm.processing" />
                                <Download v-else class="size-4" />
                                Buat ekspor
                            </Button>
                        </div>
                    </form>
                </CardContent>
            </Card>

            <Card class="rounded-2xl border-0 shadow-neu-flat">
                <CardContent class="flex flex-col gap-4">
                    <div class="flex items-center gap-2">
                        <Database class="size-4 text-muted-foreground" />
                        <h2 class="text-sm font-semibold">Buat backup</h2>
                    </div>

                    <p class="text-xs text-muted-foreground">
                        Backup menyimpan data mentah workspace sebagai JSON,
                        jadi enum dan nominal desimalnya tetap utuh. File bisa
                        diunduh dan dipulihkan kembali lewat dry-run diff.
                    </p>

                    <form class="grid gap-4" @submit.prevent="submitBackup">
                        <div class="grid gap-2">
                            <Label>Cakupan</Label>
                            <Select v-model="backupForm.scope">
                                <SelectTrigger class="w-full shadow-neu-inset">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="full">
                                        Semua data
                                    </SelectItem>
                                    <SelectItem value="range">
                                        Rentang tanggal
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <InputError :message="backupForm.errors.scope" />
                        </div>

                        <div
                            v-if="backupForm.scope === 'range'"
                            class="grid gap-2 sm:grid-cols-2"
                        >
                            <div class="grid gap-2">
                                <Label for="backup-from">Dari tanggal</Label>
                                <Input
                                    id="backup-from"
                                    v-model="backupForm.from"
                                    type="date"
                                    class="shadow-neu-inset"
                                />
                                <InputError :message="backupForm.errors.from" />
                            </div>
                            <div class="grid gap-2">
                                <Label for="backup-to">Sampai tanggal</Label>
                                <Input
                                    id="backup-to"
                                    v-model="backupForm.to"
                                    type="date"
                                    class="shadow-neu-inset"
                                />
                                <InputError :message="backupForm.errors.to" />
                            </div>
                        </div>

                        <div class="flex items-center justify-end gap-2">
                            <Button
                                type="submit"
                                :disabled="!canEdit || backupForm.processing"
                            >
                                <Spinner v-if="backupForm.processing" />
                                <Database v-else class="size-4" />
                                Buat backup
                            </Button>
                        </div>
                    </form>
                </CardContent>
            </Card>
        </div>

        <Card class="rounded-2xl border-0 py-0 shadow-neu-flat">
            <CardContent class="p-0">
                <div class="flex items-center gap-2 px-6 pt-6">
                    <FileSpreadsheet class="size-4 text-muted-foreground" />
                    <h2 class="text-sm font-semibold">Riwayat ekspor</h2>
                </div>

                <div
                    v-if="props.exports.length === 0"
                    class="mt-6 px-6 pb-6 text-center text-sm text-muted-foreground"
                >
                    Belum ada ekspor. Pilih jenis berkas di atas untuk membuat
                    yang pertama.
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="w-full min-w-3xl text-sm">
                        <thead>
                            <tr
                                class="border-y text-left text-xs text-muted-foreground"
                            >
                                <th class="px-4 py-3 font-medium">Berkas</th>
                                <th class="px-4 py-3 font-medium">Status</th>
                                <th class="px-4 py-3 font-medium">Ukuran</th>
                                <th class="px-4 py-3 font-medium">Dibuat</th>
                                <th class="px-4 py-3 font-medium">Selesai</th>
                                <th class="px-4 py-3 text-right font-medium">
                                    Aksi
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in props.exports"
                                :key="row.id"
                                class="border-b last:border-0"
                            >
                                <td class="px-4 py-3">
                                    <span class="flex items-center gap-2">
                                        <component
                                            :is="exportIcon(row)"
                                            class="size-4 text-muted-foreground"
                                        />
                                        <span class="font-medium">
                                            {{ row.label }}
                                        </span>
                                    </span>
                                    <span
                                        v-if="row.error"
                                        class="mt-1 block text-xs text-expense"
                                    >
                                        {{ row.error }}
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="flex items-center gap-1.5">
                                        <span
                                            class="size-2 rounded-full"
                                            :class="statusTone[row.status].dot"
                                        />
                                        <Badge
                                            variant="outline"
                                            :class="
                                                statusTone[row.status].badge
                                            "
                                        >
                                            {{ row.status_label }}
                                        </Badge>
                                        <Spinner
                                            v-if="isPendingRow(row)"
                                            class="size-3"
                                        />
                                    </span>
                                </td>
                                <td class="px-4 py-3 tabular-nums">
                                    {{ formatBytes(row.size_bytes) }}
                                </td>
                                <td class="px-4 py-3 text-muted-foreground">
                                    {{ formatDateTime(row.created_at) }}
                                </td>
                                <td class="px-4 py-3 text-muted-foreground">
                                    {{ formatDateTime(row.completed_at) }}
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <Button
                                        v-if="row.status === 'done'"
                                        as-child
                                        size="sm"
                                        variant="ghost"
                                    >
                                        <a :href="downloadExport(row.id).url">
                                            <Download class="size-4" />
                                            Unduh
                                        </a>
                                    </Button>
                                    <span
                                        v-else
                                        class="text-xs text-muted-foreground"
                                    >
                                        -
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </CardContent>
        </Card>

        <Card class="rounded-2xl border-0 py-0 shadow-neu-flat">
            <CardContent class="p-0">
                <div class="flex items-center gap-2 px-6 pt-6">
                    <Database class="size-4 text-muted-foreground" />
                    <h2 class="text-sm font-semibold">Riwayat backup</h2>
                </div>

                <div
                    v-if="props.backups.length === 0"
                    class="mt-6 px-6 pb-6 text-center text-sm text-muted-foreground"
                >
                    Belum ada backup. Buat satu untuk menyimpan salinan data
                    workspace ini.
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="w-full min-w-3xl text-sm">
                        <thead>
                            <tr
                                class="border-y text-left text-xs text-muted-foreground"
                            >
                                <th class="px-4 py-3 font-medium">Cakupan</th>
                                <th class="px-4 py-3 font-medium">Status</th>
                                <th class="px-4 py-3 font-medium">Ukuran</th>
                                <th class="px-4 py-3 font-medium">Dibuat</th>
                                <th class="px-4 py-3 text-right font-medium">
                                    Aksi
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in props.backups"
                                :key="row.id"
                                class="border-b last:border-0"
                            >
                                <td class="px-4 py-3">
                                    <span class="font-medium">
                                        {{ row.scope_label }}
                                    </span>
                                    <span
                                        v-if="row.from && row.to"
                                        class="mt-1 block text-xs text-muted-foreground"
                                    >
                                        {{ row.from }} s.d. {{ row.to }}
                                    </span>
                                    <span
                                        v-if="row.error"
                                        class="mt-1 block text-xs text-expense"
                                    >
                                        {{ row.error }}
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="flex items-center gap-1.5">
                                        <span
                                            class="size-2 rounded-full"
                                            :class="statusTone[row.status].dot"
                                        />
                                        <Badge
                                            variant="outline"
                                            :class="
                                                statusTone[row.status].badge
                                            "
                                        >
                                            {{ row.status_label }}
                                        </Badge>
                                        <Spinner
                                            v-if="row.status === 'queued'"
                                            class="size-3"
                                        />
                                    </span>
                                </td>
                                <td class="px-4 py-3 tabular-nums">
                                    {{ formatBytes(row.size_bytes) }}
                                </td>
                                <td class="px-4 py-3 text-muted-foreground">
                                    {{ formatDateTime(row.created_at) }}
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <div
                                        class="flex items-center justify-end gap-1"
                                    >
                                        <Button
                                            v-if="row.can_restore"
                                            size="sm"
                                            variant="ghost"
                                            @click="openPreview(row)"
                                        >
                                            <History class="size-4" />
                                            Pratinjau
                                        </Button>
                                        <Button
                                            v-if="row.can_restore"
                                            size="sm"
                                            variant="ghost"
                                            @click="openRestore(row)"
                                        >
                                            <RotateCcw class="size-4" />
                                            Pulihkan
                                        </Button>
                                        <Button
                                            v-if="row.status === 'done'"
                                            as-child
                                            size="sm"
                                            variant="ghost"
                                        >
                                            <a
                                                :href="
                                                    downloadBackup(row.id).url
                                                "
                                            >
                                                <Download class="size-4" />
                                                Unduh
                                            </a>
                                        </Button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </CardContent>
        </Card>

        <Alert
            v-if="result !== null"
            class="rounded-2xl border-emerald-500/30 bg-emerald-500/10"
        >
            <History class="size-4" />
            <AlertTitle class="text-emerald-700 dark:text-emerald-400">
                Restore selesai
            </AlertTitle>
            <AlertDescription>
                <ul class="mt-1 grid gap-1 text-xs sm:grid-cols-3">
                    <li>
                        {{ Object.keys(result.sections).length }} bagian data
                        dipulihkan
                    </li>
                    <li>Baris baru ditambahkan, baris yang ada diperbarui</li>
                    <li>Saldo akun dihitung ulang dari mutasi</li>
                </ul>
            </AlertDescription>
        </Alert>

        <Dialog :open="isDiffOpen" @update:open="isDiffOpen = $event">
            <DialogContent class="sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>Pratinjau pemulihan (dry-run)</DialogTitle>
                    <DialogDescription>
                        Belum ada data yang diubah. Periksa dulu berapa baris
                        yang akan ditambahkan, ditimpa, atau dilewati.
                    </DialogDescription>
                </DialogHeader>

                <div
                    v-if="diff !== null && !diff.ok"
                    class="flex flex-col gap-2"
                >
                    <Alert
                        class="rounded-2xl border-rose-500/30 bg-rose-500/10"
                    >
                        <Trash2 class="size-4" />
                        <AlertTitle class="text-rose-700 dark:text-rose-400">
                            Backup tidak bisa dipulihkan
                        </AlertTitle>
                        <AlertDescription>
                            <ul
                                v-for="message in diff.errors ?? []"
                                :key="message"
                                class="list-inside list-disc text-xs"
                            >
                                {{
                                    message
                                }}
                            </ul>
                        </AlertDescription>
                    </Alert>
                </div>

                <div v-else class="flex flex-col gap-4">
                    <p class="text-xs text-muted-foreground">
                        Rencana: {{ diffTotals.create }} baris baru,
                        {{ diffTotals.replace }} diperbarui,
                        {{ diffTotals.skip }} dilewati karena milik workspace
                        lain. Baris yang tidak ada di backup tetap
                        dipertahankan.
                    </p>

                    <div class="max-h-80 overflow-y-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr
                                    class="border-b text-left text-xs text-muted-foreground"
                                >
                                    <th class="px-4 py-3 font-medium">
                                        Bagian
                                    </th>
                                    <th
                                        class="px-4 py-3 text-right font-medium"
                                    >
                                        Sekarang
                                    </th>
                                    <th
                                        class="px-4 py-3 text-right font-medium"
                                    >
                                        Di backup
                                    </th>
                                    <th
                                        class="px-4 py-3 text-right font-medium"
                                    >
                                        Baru
                                    </th>
                                    <th
                                        class="px-4 py-3 text-right font-medium"
                                    >
                                        Timpa
                                    </th>
                                    <th
                                        class="px-4 py-3 text-right font-medium"
                                    >
                                        Lewati
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="section in diffSections"
                                    :key="section.key"
                                    class="border-b last:border-0"
                                >
                                    <td class="px-4 py-3 font-medium">
                                        {{ section.label }}
                                    </td>
                                    <td
                                        class="px-4 py-3 text-right tabular-nums"
                                    >
                                        {{ section.stats.current }}
                                    </td>
                                    <td
                                        class="px-4 py-3 text-right tabular-nums"
                                    >
                                        {{ section.stats.payload }}
                                    </td>
                                    <td
                                        class="px-4 py-3 text-right tabular-nums"
                                    >
                                        {{ section.stats.create }}
                                    </td>
                                    <td
                                        class="px-4 py-3 text-right tabular-nums"
                                    >
                                        {{ section.stats.replace }}
                                    </td>
                                    <td
                                        class="px-4 py-3 text-right tabular-nums"
                                    >
                                        {{ section.stats.skip }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <DialogFooter>
                    <Button variant="ghost" @click="isDiffOpen = false">
                        Tutup
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <Dialog :open="isRestoreOpen" @update:open="isRestoreOpen = $event">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Pulihkan backup ini?</DialogTitle>
                    <DialogDescription>
                        Baris yang sudah ada akan ditimpa dengan isi backup
                        {{ diffBackup?.file_name ?? 'backup ini' }}. Data
                        workspace lain tidak tersentuh, dan baris yang tidak ada
                        di backup tetap dipertahankan. Jalankan pratinjau dulu
                        bila belum dilakukan.
                    </DialogDescription>
                </DialogHeader>

                <DialogFooter>
                    <Button variant="ghost" @click="isRestoreOpen = false">
                        Batal
                    </Button>
                    <Button variant="destructive" @click="confirmRestore">
                        <RotateCcw class="size-4" />
                        Ya, pulihkan
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
