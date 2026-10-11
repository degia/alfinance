<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import {
    Plus,
    RotateCcw,
    Save,
    ShieldCheck,
    Trash2,
    TriangleAlert,
} from '@lucide/vue';
import AlertError from '@/components/AlertError.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useWorkspace } from '@/composables/useWorkspace';
import { formatCurrency, formatDate, toNumber } from '@/lib/format';
import { save as saveHitungan } from '@/routes/hitungan';
import type {
    HitunganIndexProps,
    HitunganPlanFormData,
    HitunganPlanRow,
} from '@/types';

defineOptions({
    layout: {
        title: 'Hitungan',
        description: 'Kalkulator rencana anggaran bulanan',
    },
});

const props = defineProps<HitunganIndexProps>();

const { workspace } = useWorkspace();
const canEdit = computed(() => workspace.value?.role !== 'viewer');

/*
|--------------------------------------------------------------------------
| State
|--------------------------------------------------------------------------
| Semua hasil hitungan (saldo aktual, limit harian, dst) dihitung ulang di
| browser, persis seperti spreadsheet "BUDGET PLAN" pada referensi. Yang
| dikirim ke server hanya input rencananya, supaya bisa dibuka kembali.
*/

type PlanRow = {
    id: number;
    name: string;
    plan: string;
    actual: string;
};

let sequence = 0;

const makeRow = (name = ''): PlanRow => ({
    id: ++sequence,
    name,
    plan: '',
    actual: '',
});

/**
 * Nominal tersimpan → teks input. Nol/absen dijadikan string kosong supaya
 * form terbaca bersih, bukan penuh "0.00".
 */
function inputValue(value: string | null | undefined): string {
    const amount = toNumber(value ?? null);

    return amount === null || amount === 0 ? '' : (value as string);
}

function savedRows(): PlanRow[] {
    const saved = props.plan?.rows ?? [];

    if (saved.length === 0) {
        return [makeRow()];
    }

    return saved.map((row: HitunganPlanRow): PlanRow => ({
        id: ++sequence,
        name: row.name,
        plan: inputValue(row.plan),
        actual: inputValue(row.actual),
    }));
}

const dailyLimit = ref(inputValue(props.plan?.daily_limit));
const endDate = ref(props.plan?.end_date ?? '');
const rows = ref<PlanRow[]>(savedRows());

/*
|--------------------------------------------------------------------------
| Perhitungan
|--------------------------------------------------------------------------
| Rumus mengikuti referensi:
| - Rencana pengeluaran = jumlah kolom rencana
| - Saldo aktual        = saldo − rencana pengeluaran
| - Saldo minimum       = limit/hari × jumlah hari
| - Nice to have        = saldo saat ini − (saldo minimum + rencana pengeluaran)
| - Sisa limit hari ini = limit/hari − pengeluaran hari ini
| - Persentase          = sisa limit hari ini ÷ limit/hari
| - Pengeluaran max/hari = saldo saat ini ÷ jumlah hari
| - Persentase harian   = limit/hari ÷ pengeluaran max/hari
*/

const planTotal = computed(() =>
    rows.value.reduce((total, row) => total + (toNumber(row.plan) ?? 0), 0),
);

const actualTotal = computed(() =>
    rows.value.reduce((total, row) => total + (toNumber(row.actual) ?? 0), 0),
);

const countDays = computed(() => {
    const start = toDate(props.today);
    const end = toDate(endDate.value);

    if (start === null || end === null) {
        return null;
    }

    return Math.max(
        0,
        Math.round((end.getTime() - start.getTime()) / 86_400_000),
    );
});

/**
 * Saldo minimum (cadangan) = limit harian dikali jumlah hari. Null bila jumlah
 * hari belum diketahui, supaya tampil "—" alih-alih nol yang menyesatkan.
 */
const minBalance = computed<number | null>(() => {
    const days = countDays.value;

    if (days === null) {
        return null;
    }

    return (toNumber(dailyLimit.value) ?? 0) * days;
});

const actualBalance = computed(
    () => (toNumber(props.balance) ?? 0) - planTotal.value,
);

const niceToHave = computed(
    () =>
        (toNumber(props.balance) ?? 0) -
        ((minBalance.value ?? 0) + planTotal.value),
);

const dayMax = computed(
    () =>
        (toNumber(dailyLimit.value) ?? 0) -
        (toNumber(props.expenses_today) ?? 0),
);

const percentage = computed<number | null>(() => {
    const limit = toNumber(dailyLimit.value) ?? 0;

    if (limit === 0) {
        return null;
    }

    return dayMax.value / limit;
});

/**
 * Batas pengeluaran harian yang "sehat": saldo saat ini dibagi sisa hari.
 * Null bila jumlah hari belum diketahui atau nol (tanggal berakhir kosong atau
 * sama dengan hari ini), supaya tidak membagi dengan nol.
 */
const maxDailySpending = computed<number | null>(() => {
    const days = countDays.value;

    if (days === null || days === 0) {
        return null;
    }

    return (toNumber(props.balance) ?? 0) / days;
});

/**
 * Seberapa besar limit harian yang direncanakan dibanding batas sehat di atas.
 */
const dailyLimitRatio = computed<number | null>(() => {
    const max = maxDailySpending.value;

    if (max === null || max === 0) {
        return null;
    }

    return (toNumber(dailyLimit.value) ?? 0) / max;
});

const isHealthy = computed(() => actualBalance.value >= 0);

type SummaryCard = {
    label: string;
    value: string;
    hint: string;
    tone: string;
    note?: string;
    noteTone?: string;
};

const summaryCards = computed<SummaryCard[]>(() => [
    {
        label: 'Rencana pengeluaran',
        value: formatCurrency(planTotal.value),
        hint: `${rows.value.length} baris rencana`,
        tone: '',
    },
    {
        label: 'Saldo aktual',
        value: formatCurrency(actualBalance.value),
        hint: 'Saldo saat ini dikurangi total rencana',
        tone: isHealthy.value
            ? 'text-emerald-600 dark:text-emerald-400'
            : 'text-rose-600 dark:text-rose-400',
    },
    {
        label: 'Nice to have',
        value: formatCurrency(niceToHave.value),
        hint: 'Saldo saat ini dikurangi saldo minimum dan rencana pengeluaran',
        tone:
            niceToHave.value < 0
                ? 'text-rose-600 dark:text-rose-400'
                : 'text-emerald-600 dark:text-emerald-400',
        note:
            niceToHave.value < 0
                ? `Anda harus mempunyai saldo Nice To Have ${formatCurrency(
                      Math.abs(niceToHave.value),
                  )}`
                : `Anda surplus! Nice To Have ${formatCurrency(
                      niceToHave.value,
                  )}`,
        noteTone:
            niceToHave.value < 0
                ? 'text-rose-600 dark:text-rose-400'
                : 'text-emerald-600 dark:text-emerald-400',
    },
    {
        label: 'Sisa limit hari ini',
        value: formatCurrency(dayMax.value),
        hint: `${formatPercent(percentage.value)} dari limit ${formatCurrency(
            dailyLimit.value,
        )}`,
        tone:
            dayMax.value >= 0
                ? 'text-emerald-600 dark:text-emerald-400'
                : 'text-rose-600 dark:text-rose-400',
    },
    {
        label: 'Pengeluaran max harian',
        value:
            maxDailySpending.value === null
                ? '—'
                : formatCurrency(maxDailySpending.value),
        hint: 'Saldo saat ini dibagi jumlah hari',
        tone: '',
    },
    {
        label: 'Persentase pengeluaran harian',
        value: formatPercent(dailyLimitRatio.value),
        hint: 'Limit harian dibanding pengeluaran max harian',
        tone:
            dailyLimitRatio.value === null
                ? ''
                : dailyLimitRatio.value <= 1
                  ? 'text-emerald-600 dark:text-emerald-400'
                  : 'text-rose-600 dark:text-rose-400',
    },
]);

function toDate(value: string): Date | null {
    if (!value) {
        return null;
    }

    const [year, month, day] = value.split('-').map(Number);

    if (!year || !month || !day) {
        return null;
    }

    return new Date(year, month - 1, day);
}

function formatPercent(value: number | null): string {
    if (value === null) {
        return '—';
    }

    return `${new Intl.NumberFormat('id-ID', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(value * 100)}%`;
}

function rowDifference(row: PlanRow): number {
    return (toNumber(row.plan) ?? 0) - (toNumber(row.actual) ?? 0);
}

function addRow(): void {
    rows.value = [...rows.value, makeRow()];
}

function removeRow(id: number): void {
    const remaining = rows.value.filter((row) => row.id !== id);

    rows.value = remaining.length > 0 ? remaining : [makeRow()];
}

function reset(): void {
    dailyLimit.value = '';
    endDate.value = '';
    rows.value = [makeRow()];
}

/*
|--------------------------------------------------------------------------
| Simpan
|--------------------------------------------------------------------------
| Satu rencana per workspace: POST menimpa baris yang ada. `balance`,
| `expenses_today`, tanggal mulai, dan saldo minimum tidak ikut dikirim karena
| selalu dihitung ulang di server.
*/

const form = useForm<HitunganPlanFormData>({
    daily_limit: '',
    end_date: '',
    rows: [],
});

const formErrors = computed(() => Object.values(form.errors));

const savedLabel = computed(() => {
    const savedAt = props.plan?.saved_at;

    if (!savedAt) {
        return null;
    }

    return new Intl.DateTimeFormat('id-ID', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(savedAt));
});

function save(): void {
    form.daily_limit = dailyLimit.value || '0';
    form.end_date = endDate.value;
    form.rows = rows.value.map((row) => ({
        name: row.name,
        plan: row.plan || '0',
        actual: row.actual || '0',
    }));

    form.post(saveHitungan().url, { preserveScroll: true });
}
</script>

<template>
    <Head title="Hitungan" />

    <div class="flex flex-col gap-6">
        <div
            class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"
        >
            <Heading
                title="Hitungan"
                description="Kalkulator rencana anggaran bulanan: isi saldo, limit harian, dan daftar rencana untuk melihat saldo aktual serta sisa limit hari ini."
                :spaced="false"
            />
            <div class="flex flex-wrap items-center gap-2 sm:justify-end">
                <span
                    v-if="savedLabel"
                    class="w-full text-xs text-muted-foreground sm:w-auto"
                >
                    Tersimpan {{ savedLabel }}
                </span>
                <Button
                    v-if="canEdit"
                    size="sm"
                    class="flex-1 gap-2 sm:flex-none"
                    :disabled="form.processing"
                    @click="save"
                >
                    <Save class="size-4" />
                    Simpan
                </Button>
                <Button
                    variant="outline"
                    size="sm"
                    class="flex-1 gap-2 sm:flex-none"
                    @click="reset"
                >
                    <RotateCcw class="size-4" />
                    Reset
                </Button>
            </div>
        </div>

        <AlertError
            v-if="formErrors.length > 0"
            :errors="formErrors"
            title="Perhitungan gagal disimpan."
        />

        <div
            class="flex flex-wrap items-center gap-2 rounded-2xl px-4 py-3 shadow-neu-flat"
            :class="
                isHealthy
                    ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300'
                    : 'bg-rose-500/10 text-rose-700 dark:text-rose-300'
            "
        >
            <ShieldCheck v-if="isHealthy" class="size-4" />
            <TriangleAlert v-else class="size-4" />
            <span class="text-sm font-semibold tracking-wide uppercase">
                {{ isHealthy ? 'Keep your plan' : 'Plan over budget' }}
            </span>
            <span class="text-sm text-muted-foreground">
                {{
                    isHealthy
                        ? 'Saldo masih cukup untuk menutup semua rencana.'
                        : 'Total rencana melebihi saldo yang tersedia.'
                }}
            </span>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <Card
                v-for="card in summaryCards"
                :key="card.label"
                class="rounded-2xl border-0 shadow-neu-flat"
            >
                <CardContent>
                    <p
                        class="text-xs tracking-wide text-muted-foreground uppercase"
                    >
                        {{ card.label }}
                    </p>
                    <p class="mt-1 text-xl font-semibold" :class="card.tone">
                        {{ card.value }}
                    </p>
                    <p
                        v-if="card.note"
                        class="mt-1 text-xs font-medium"
                        :class="card.noteTone"
                    >
                        {{ card.note }}
                    </p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        {{ card.hint }}
                    </p>
                </CardContent>
            </Card>
        </div>

        <div class="grid gap-4 lg:grid-cols-5">
            <Card class="rounded-2xl border-0 shadow-neu-flat lg:col-span-2">
                <CardContent class="flex flex-col gap-4">
                    <h3 class="text-sm font-semibold">Parameter</h3>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label>Saldo saat ini</Label>
                            <div
                                class="neu-inset flex h-9 items-center justify-between rounded-md px-3 text-sm"
                            >
                                <span class="font-semibold">
                                    {{ formatCurrency(props.balance) }}
                                </span>
                                <span
                                    class="text-2xs font-medium tracking-wide text-muted-foreground uppercase"
                                >
                                    otomatis
                                </span>
                            </div>
                            <p class="text-xs text-muted-foreground">
                                Dari total saldo akun aktif (tanpa tabungan &
                                kartu kredit).
                            </p>
                        </div>

                        <div class="grid gap-2">
                            <Label>Saldo minimum</Label>
                            <div
                                class="neu-inset flex h-9 items-center justify-between rounded-md px-3 text-sm"
                            >
                                <span class="font-semibold">
                                    {{
                                        minBalance === null
                                            ? '—'
                                            : formatCurrency(minBalance)
                                    }}
                                </span>
                                <span
                                    class="text-2xs font-medium tracking-wide text-muted-foreground uppercase"
                                >
                                    otomatis
                                </span>
                            </div>
                            <p class="text-xs text-muted-foreground">
                                Limit harian dikali jumlah hari.
                            </p>
                        </div>

                        <div class="grid gap-2">
                            <Label for="hitungan-daily-limit">
                                Limit pengeluaran / hari
                            </Label>
                            <Input
                                id="hitungan-daily-limit"
                                v-model="dailyLimit"
                                inputmode="decimal"
                                placeholder="0"
                                class="shadow-neu-inset"
                            />
                        </div>

                        <div class="grid gap-2">
                            <Label>Pengeluaran hari ini</Label>
                            <div
                                class="neu-inset flex h-9 items-center justify-between rounded-md px-3 text-sm"
                            >
                                <span class="font-semibold">
                                    {{ formatCurrency(props.expenses_today) }}
                                </span>
                                <span
                                    class="text-2xs font-medium tracking-wide text-muted-foreground uppercase"
                                >
                                    otomatis
                                </span>
                            </div>
                            <p class="text-xs text-muted-foreground">
                                Dari total pengeluaran transaksi hari ini.
                            </p>
                        </div>

                        <div class="grid gap-2">
                            <Label>Tanggal mulai</Label>
                            <div
                                class="neu-inset flex h-9 items-center justify-between rounded-md px-3 text-sm"
                            >
                                <span class="font-semibold">
                                    {{ formatDate(props.today) }}
                                </span>
                                <span
                                    class="text-2xs font-medium tracking-wide text-muted-foreground uppercase"
                                >
                                    otomatis
                                </span>
                            </div>
                            <p class="text-xs text-muted-foreground">
                                Selalu tanggal hari ini.
                            </p>
                        </div>

                        <div class="grid gap-2">
                            <Label for="hitungan-end-date">
                                Tanggal berakhir
                            </Label>
                            <Input
                                id="hitungan-end-date"
                                v-model="endDate"
                                type="date"
                                :min="props.today"
                                class="shadow-neu-inset"
                            />
                        </div>
                    </div>

                    <div
                        class="neu-inset flex items-center justify-between rounded-lg px-3 py-2"
                    >
                        <span class="text-sm text-muted-foreground">
                            Jumlah hari
                        </span>
                        <span class="text-sm font-semibold">
                            {{ countDays === null ? '—' : `${countDays} hari` }}
                        </span>
                    </div>
                </CardContent>
            </Card>

            <Card class="rounded-2xl border-0 shadow-neu-flat lg:col-span-3">
                <CardContent class="flex flex-col gap-4">
                    <div class="flex items-center justify-between gap-2">
                        <h3 class="text-sm font-semibold">
                            Rencana pengeluaran
                        </h3>
                        <Button
                            variant="outline"
                            size="sm"
                            class="gap-2"
                            @click="addRow"
                        >
                            <Plus class="size-4" />
                            Tambah baris
                        </Button>
                    </div>

                    <div class="hidden overflow-x-auto md:block">
                        <table class="w-full min-w-[32rem] text-sm">
                            <thead>
                                <tr
                                    class="text-left text-xs tracking-wide text-muted-foreground uppercase"
                                >
                                    <th class="pb-2 font-medium">Nama</th>
                                    <th class="pb-2 text-right font-medium">
                                        Rencana
                                    </th>
                                    <th class="pb-2 text-right font-medium">
                                        Aktual
                                    </th>
                                    <th class="pb-2 text-right font-medium">
                                        Selisih
                                    </th>
                                    <th class="pb-2"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="row in rows" :key="row.id">
                                    <td class="py-1 pr-2">
                                        <Input
                                            v-model="row.name"
                                            placeholder="Nama pengeluaran"
                                            class="shadow-neu-inset"
                                        />
                                    </td>
                                    <td class="py-1 pr-2">
                                        <Input
                                            v-model="row.plan"
                                            inputmode="decimal"
                                            placeholder="0"
                                            class="w-32 text-right shadow-neu-inset"
                                        />
                                    </td>
                                    <td class="py-1 pr-2">
                                        <Input
                                            v-model="row.actual"
                                            inputmode="decimal"
                                            placeholder="0"
                                            class="w-32 text-right shadow-neu-inset"
                                        />
                                    </td>
                                    <td
                                        class="py-1 pr-2 text-right font-medium whitespace-nowrap"
                                        :class="
                                            rowDifference(row) >= 0
                                                ? 'text-emerald-600 dark:text-emerald-400'
                                                : 'text-rose-600 dark:text-rose-400'
                                        "
                                    >
                                        {{ formatCurrency(rowDifference(row)) }}
                                    </td>
                                    <td class="py-1 pl-1">
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            class="text-muted-foreground"
                                            :aria-label="
                                                row.name
                                                    ? `Hapus ${row.name}`
                                                    : 'Hapus baris'
                                            "
                                            @click="removeRow(row.id)"
                                        >
                                            <Trash2 class="size-4" />
                                        </Button>
                                    </td>
                                </tr>
                            </tbody>
                            <tfoot>
                                <tr class="border-t font-semibold">
                                    <td class="pt-2">Total</td>
                                    <td
                                        class="pt-2 text-right whitespace-nowrap"
                                    >
                                        {{ formatCurrency(planTotal) }}
                                    </td>
                                    <td
                                        class="pt-2 text-right whitespace-nowrap"
                                    >
                                        {{ formatCurrency(actualTotal) }}
                                    </td>
                                    <td
                                        class="pt-2 text-right whitespace-nowrap"
                                        :class="
                                            planTotal - actualTotal >= 0
                                                ? 'text-emerald-600 dark:text-emerald-400'
                                                : 'text-rose-600 dark:text-rose-400'
                                        "
                                    >
                                        {{
                                            formatCurrency(
                                                planTotal - actualTotal,
                                            )
                                        }}
                                    </td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    <div class="grid gap-3 md:hidden">
                        <div
                            v-for="row in rows"
                            :key="row.id"
                            class="grid gap-3 rounded-xl border border-border/60 p-3"
                        >
                            <div class="flex items-center gap-2">
                                <Input
                                    v-model="row.name"
                                    placeholder="Nama pengeluaran"
                                    class="shadow-neu-inset"
                                />
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    class="text-muted-foreground"
                                    :aria-label="
                                        row.name
                                            ? `Hapus ${row.name}`
                                            : 'Hapus baris'
                                    "
                                    @click="removeRow(row.id)"
                                >
                                    <Trash2 class="size-4" />
                                </Button>
                            </div>

                            <div class="grid grid-cols-2 gap-3">
                                <div class="grid gap-1.5">
                                    <Label
                                        class="text-xs text-muted-foreground"
                                    >
                                        Rencana
                                    </Label>
                                    <Input
                                        v-model="row.plan"
                                        inputmode="decimal"
                                        placeholder="0"
                                        class="text-right shadow-neu-inset"
                                    />
                                </div>
                                <div class="grid gap-1.5">
                                    <Label
                                        class="text-xs text-muted-foreground"
                                    >
                                        Aktual
                                    </Label>
                                    <Input
                                        v-model="row.actual"
                                        inputmode="decimal"
                                        placeholder="0"
                                        class="text-right shadow-neu-inset"
                                    />
                                </div>
                            </div>

                            <div
                                class="flex items-center justify-between text-xs"
                            >
                                <span class="text-muted-foreground">
                                    Selisih
                                </span>
                                <span
                                    class="font-semibold"
                                    :class="
                                        rowDifference(row) >= 0
                                            ? 'text-emerald-600 dark:text-emerald-400'
                                            : 'text-rose-600 dark:text-rose-400'
                                    "
                                >
                                    {{ formatCurrency(rowDifference(row)) }}
                                </span>
                            </div>
                        </div>

                        <div
                            class="grid grid-cols-2 gap-3 rounded-xl border border-border/60 bg-muted/40 p-3 text-sm"
                        >
                            <div>
                                <p
                                    class="text-2xs font-medium tracking-wide text-muted-foreground uppercase"
                                >
                                    Total rencana
                                </p>
                                <p
                                    class="mt-0.5 font-semibold whitespace-nowrap"
                                >
                                    {{ formatCurrency(planTotal) }}
                                </p>
                            </div>
                            <div class="text-right">
                                <p
                                    class="text-2xs font-medium tracking-wide text-muted-foreground uppercase"
                                >
                                    Total aktual
                                </p>
                                <p
                                    class="mt-0.5 font-semibold whitespace-nowrap"
                                >
                                    {{ formatCurrency(actualTotal) }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <p class="text-xs text-muted-foreground">
                        Rencana dipakai untuk menghitung saldo aktual. Kolom
                        aktual opsional, hanya untuk membandingkan rencana
                        dengan realisasi.
                    </p>
                </CardContent>
            </Card>
        </div>
    </div>
</template>
