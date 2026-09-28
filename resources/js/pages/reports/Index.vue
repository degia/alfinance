<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { FileBarChart, Search, X } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
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
import { formatCurrency, toNumber } from '@/lib/format';
import { index as reportsIndex } from '@/routes/reports';
import type {
    BudgetMatrixCategory,
    ReportCashFlowPoint,
    ReportExpenseItem,
    ReportIndexProps,
} from '@/types';

defineOptions({
    layout: {
        title: 'Laporan',
        description:
            'Cash flow, budget vs realisasi, dan komposisi pengeluaran',
        breadcrumbs: [
            {
                title: 'Laporan',
                href: reportsIndex(),
            },
        ],
    },
});

const props = defineProps<ReportIndexProps>();

/*
|--------------------------------------------------------------------------
| Filter
|--------------------------------------------------------------------------
| Hanya tombol "Terapkan" yang memicu GET baru. Server menormalkan rentang
| ke bulan dan menguncinya di cache key, jadi berpindah filter tidak pernah
| men-trigger agregasi manual.
*/
type ReportTab = 'cash_flow' | 'budget' | 'expense';

const tabs: { key: ReportTab; label: string }[] = [
    { key: 'cash_flow', label: 'Arus kas' },
    { key: 'budget', label: 'Budget vs realisasi' },
    { key: 'expense', label: 'Komposisi pengeluaran' },
];

const activeTab = ref<ReportTab>('cash_flow');

const fromInput = ref(monthToDate(props.filters.from));
const toInput = ref(monthToDate(props.filters.to));
const accountInput = ref(props.filters.account_id ?? '');
const categoryInput = ref(props.filters.category_id ?? '');

function monthToDate(month: string): string {
    return /^\d{4}-\d{2}$/.test(month) ? `${month}-01` : month;
}

function dateToMonth(value: string): string {
    return value.slice(0, 7);
}

const hasActiveFilters = computed(
    () =>
        props.filters.account_id !== null || props.filters.category_id !== null,
);

function applyFilters(): void {
    const query: Record<string, string | number> = {
        from: dateToMonth(fromInput.value),
        to: dateToMonth(toInput.value),
    };

    if (accountInput.value !== '') {
        query.account_id = Number(accountInput.value);
    }

    if (categoryInput.value !== '') {
        query.category_id = Number(categoryInput.value);
    }

    router.get(reportsIndex({ query }).url, {
        preserveState: true,
        preserveScroll: true,
    });
}

function clearFilters(): void {
    router.get(reportsIndex().url, {
        preserveState: true,
        preserveScroll: true,
    });
}

/*
|--------------------------------------------------------------------------
| Arus kas
|--------------------------------------------------------------------------
*/
const cashFlowRows = computed<ReportCashFlowPoint[]>(
    () => props.cash_flow.points,
);

/*
|--------------------------------------------------------------------------
| Budget vs realisasi
|--------------------------------------------------------------------------
*/
function percentLabel(value: number | null): string {
    return value === null ? '-' : `${value}%`;
}

function cellLabel(category: BudgetMatrixCategory, month: string): string {
    const cell = category.cells[month];

    return `${formatCurrency(cell.used)} / ${formatCurrency(cell.limit)}`;
}

/*
|--------------------------------------------------------------------------
| Komposisi pengeluaran
|--------------------------------------------------------------------------
*/
const expenseRows = computed<ReportExpenseItem[]>(
    () => props.expense_breakdown.items,
);

function expensePercent(item: ReportExpenseItem): string {
    return item.percent === null ? '-' : `${item.percent}%`;
}

function monthTotal(month: string): string {
    return props.expense_breakdown.months_totals[month] ?? '0.00';
}

function expenseChangeTone(value: string): string {
    return (toNumber(value) ?? 0) < 0
        ? 'text-rose-600 dark:text-rose-400'
        : 'text-emerald-600 dark:text-emerald-400';
}
</script>

<template>
    <Head title="Laporan" />

    <div class="flex flex-col gap-6">
        <Heading
            title="Laporan"
            description="Angka dibaca dari snapshot bulanan dan hitungan budget yang sudah di-cache. Bila datanya berubah, snapshot diperbarui lewat antrean — bukan saat halaman ini dibuka."
        />

        <Card class="rounded-2xl border-0 shadow-neu-flat">
            <CardContent class="flex flex-wrap items-end gap-4 p-5">
                <div class="grid gap-2">
                    <Label for="filter-from">Dari bulan</Label>
                    <Input
                        id="filter-from"
                        v-model="fromInput"
                        type="date"
                        class="shadow-neu-inset"
                    />
                </div>

                <div class="grid gap-2">
                    <Label for="filter-to">Sampai bulan</Label>
                    <Input
                        id="filter-to"
                        v-model="toInput"
                        type="date"
                        class="shadow-neu-inset"
                    />
                </div>

                <div class="grid gap-2">
                    <Label for="filter-account">Akun</Label>
                    <Select v-model="accountInput">
                        <SelectTrigger id="filter-account" class="w-48">
                            <SelectValue placeholder="Semua akun" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="">Semua akun</SelectItem>
                            <SelectItem
                                v-for="account in props.options.accounts"
                                :key="account.id"
                                :value="String(account.id)"
                            >
                                {{ account.name }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <div class="grid gap-2">
                    <Label for="filter-category">Kategori</Label>
                    <Select v-model="categoryInput">
                        <SelectTrigger id="filter-category" class="w-48">
                            <SelectValue placeholder="Semua kategori" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="">Semua kategori</SelectItem>
                            <SelectItem
                                v-for="category in props.options.categories"
                                :key="category.id"
                                :value="String(category.id)"
                            >
                                {{ category.name }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <div class="flex items-center gap-2">
                    <Button class="gap-2" @click="applyFilters">
                        <Search class="size-4" />
                        Terapkan filter
                    </Button>

                    <Button
                        v-if="
                            hasActiveFilters ||
                            dateToMonth(fromInput) !== props.filters.from ||
                            dateToMonth(toInput) !== props.filters.to
                        "
                        variant="ghost"
                        class="gap-2"
                        @click="clearFilters"
                    >
                        <X class="size-4" />
                        Reset
                    </Button>
                </div>

                <p class="w-full text-xs text-muted-foreground">
                    Rentang sampai {{ props.max_months }} bulan. Filter akun
                    hanya berlaku untuk laporan arus kas; budget dan komposisi
                    pengeluaran disusun per kategori.
                </p>
            </CardContent>
        </Card>

        <div class="flex flex-wrap items-center gap-1">
            <Button
                v-for="tab in tabs"
                :key="tab.key"
                size="sm"
                :variant="activeTab === tab.key ? 'default' : 'ghost'"
                @click="activeTab = tab.key"
            >
                <FileBarChart class="size-4" />
                {{ tab.label }}
            </Button>
        </div>

        <!-- Arus kas -->
        <Card
            v-if="activeTab === 'cash_flow' && props.cash_flow.has_data"
            class="rounded-2xl border-0 shadow-neu-flat"
        >
            <CardContent class="flex flex-col gap-4 p-5">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h3 class="text-sm font-semibold">Arus kas bulanan</h3>
                    <Badge
                        v-if="props.cash_flow.is_account_filtered"
                        variant="secondary"
                        class="text-[10px]"
                    >
                        khusus akun terpilih
                    </Badge>
                    <span v-else class="text-xs text-muted-foreground">
                        Dibaca dari snapshot bulanan
                    </span>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full min-w-[540px] text-sm">
                        <thead class="text-xs text-muted-foreground uppercase">
                            <tr class="border-b">
                                <th class="px-2 py-2 text-left">Bulan</th>
                                <th class="px-2 py-2 text-right">Pemasukan</th>
                                <th class="px-2 py-2 text-right">
                                    Pengeluaran
                                </th>
                                <th class="px-2 py-2 text-right">
                                    Arus bersih
                                </th>
                                <th class="px-2 py-2 text-right">Transfer</th>
                                <th class="px-2 py-2 text-right">Transaksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="point in cashFlowRows"
                                :key="point.month"
                                class="border-b last:border-0"
                            >
                                <td class="px-2 py-2 font-medium">
                                    {{ point.label }}
                                </td>
                                <td
                                    class="px-2 py-2 text-right text-emerald-600 tabular-nums dark:text-emerald-400"
                                >
                                    {{ formatCurrency(point.income) }}
                                </td>
                                <td
                                    class="px-2 py-2 text-right text-rose-600 tabular-nums dark:text-rose-400"
                                >
                                    {{ formatCurrency(point.expense) }}
                                </td>
                                <td
                                    class="px-2 py-2 text-right font-medium tabular-nums"
                                    :class="
                                        expenseChangeTone(point.net_cash_flow)
                                    "
                                >
                                    {{ formatCurrency(point.net_cash_flow) }}
                                </td>
                                <td
                                    class="px-2 py-2 text-right text-muted-foreground tabular-nums"
                                >
                                    {{ formatCurrency(point.total_transfer) }}
                                </td>
                                <td
                                    class="px-2 py-2 text-right text-muted-foreground tabular-nums"
                                >
                                    {{ point.transaction_count }}
                                </td>
                            </tr>
                        </tbody>
                        <tfoot class="text-sm font-semibold">
                            <tr class="border-t">
                                <td class="px-2 py-2">Total</td>
                                <td class="px-2 py-2 text-right tabular-nums">
                                    {{
                                        formatCurrency(
                                            props.cash_flow.totals.income,
                                        )
                                    }}
                                </td>
                                <td class="px-2 py-2 text-right tabular-nums">
                                    {{
                                        formatCurrency(
                                            props.cash_flow.totals.expense,
                                        )
                                    }}
                                </td>
                                <td
                                    class="px-2 py-2 text-right tabular-nums"
                                    :class="
                                        expenseChangeTone(
                                            props.cash_flow.totals
                                                .net_cash_flow,
                                        )
                                    "
                                >
                                    {{
                                        formatCurrency(
                                            props.cash_flow.totals
                                                .net_cash_flow,
                                        )
                                    }}
                                </td>
                                <td
                                    class="px-2 py-2 text-right text-muted-foreground tabular-nums"
                                >
                                    {{
                                        formatCurrency(
                                            props.cash_flow.totals
                                                .total_transfer,
                                        )
                                    }}
                                </td>
                                <td />
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <p
                    v-if="
                        !props.cash_flow.points.some((point) => point.has_data)
                    "
                    class="text-xs text-muted-foreground"
                >
                    Beberapa bulan dalam rentang belum punya snapshot. Halaman
                    ini menampilkan nol, bukan bolong, supaya totalnya tetap
                    bisa dipertanggungjawabkan.
                </p>
            </CardContent>
        </Card>

        <Card
            v-else-if="activeTab === 'cash_flow'"
            class="rounded-2xl border-0 shadow-neu-flat"
        >
            <CardContent class="p-5">
                <p
                    class="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground"
                >
                    Belum ada transaksi di rentang ini untuk ditampilkan.
                </p>
            </CardContent>
        </Card>

        <!-- Budget vs realisasi -->
        <Card
            v-if="activeTab === 'budget' && props.budget_vs_actual.has_data"
            class="rounded-2xl border-0 shadow-neu-flat"
        >
            <CardContent class="flex flex-col gap-4 p-5">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h3 class="text-sm font-semibold">
                        Limit vs pemakaian per kategori
                    </h3>
                    <span
                        :class="
                            props.budget_vs_actual.totals.over_count > 0
                                ? 'text-rose-600 dark:text-rose-400'
                                : 'text-muted-foreground'
                        "
                        class="text-xs"
                    >
                        {{
                            props.budget_vs_actual.totals.over_count > 0
                                ? `${props.budget_vs_actual.totals.over_count} kategori melewati limit`
                                : 'Semua kategori dalam batas'
                        }}
                    </span>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full min-w-[640px] text-sm">
                        <thead class="text-xs text-muted-foreground uppercase">
                            <tr class="border-b">
                                <th class="px-2 py-2 text-left">Kategori</th>
                                <th class="px-2 py-2 text-right">
                                    Total budget
                                </th>
                                <th class="px-2 py-2 text-right">
                                    Total realisasi
                                </th>
                                <th class="px-2 py-2 text-right">Sisa</th>
                                <th class="px-2 py-2 text-right">%</th>
                                <th
                                    v-for="month in props.budget_vs_actual
                                        .months"
                                    :key="month"
                                    class="px-2 py-2 text-right"
                                >
                                    {{ month }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="category in props.budget_vs_actual
                                    .categories"
                                :key="category.category_id"
                                class="border-b last:border-0"
                            >
                                <td class="max-w-48 px-2 py-2">
                                    <div class="flex items-center gap-2">
                                        <span
                                            class="size-2.5 shrink-0 rounded-full"
                                            :style="{
                                                backgroundColor: category.color,
                                            }"
                                        />
                                        <span class="truncate font-medium">
                                            {{ category.name }}
                                        </span>
                                        <Badge
                                            v-if="category.is_over"
                                            variant="destructive"
                                            class="text-[10px]"
                                        >
                                            lebih
                                        </Badge>
                                    </div>
                                </td>
                                <td class="px-2 py-2 text-right tabular-nums">
                                    {{ formatCurrency(category.limit_total) }}
                                </td>
                                <td class="px-2 py-2 text-right tabular-nums">
                                    {{ formatCurrency(category.used_total) }}
                                </td>
                                <td
                                    class="px-2 py-2 text-right tabular-nums"
                                    :class="
                                        category.is_over
                                            ? 'text-rose-600 dark:text-rose-400'
                                            : 'text-muted-foreground'
                                    "
                                >
                                    {{
                                        formatCurrency(category.remaining_total)
                                    }}
                                </td>
                                <td class="px-2 py-2 text-right tabular-nums">
                                    {{ percentLabel(category.percent_total) }}
                                </td>
                                <td
                                    v-for="month in props.budget_vs_actual
                                        .months"
                                    :key="month"
                                    class="px-2 py-2 text-right text-xs tabular-nums"
                                    :class="
                                        category.cells[month].is_over
                                            ? 'text-rose-600 dark:text-rose-400'
                                            : 'text-muted-foreground'
                                    "
                                >
                                    {{ cellLabel(category, month) }}
                                </td>
                            </tr>
                        </tbody>
                        <tfoot class="text-sm font-semibold">
                            <tr class="border-t">
                                <td class="px-2 py-2">Total</td>
                                <td class="px-2 py-2 text-right tabular-nums">
                                    {{
                                        formatCurrency(
                                            props.budget_vs_actual.totals.limit,
                                        )
                                    }}
                                </td>
                                <td class="px-2 py-2 text-right tabular-nums">
                                    {{
                                        formatCurrency(
                                            props.budget_vs_actual.totals.used,
                                        )
                                    }}
                                </td>
                                <td
                                    class="px-2 py-2 text-right tabular-nums"
                                    :class="
                                        (toNumber(
                                            props.budget_vs_actual.totals
                                                .remaining,
                                        ) ?? 0 < 0)
                                            ? 'text-rose-600 dark:text-rose-400'
                                            : ''
                                    "
                                >
                                    {{
                                        formatCurrency(
                                            props.budget_vs_actual.totals
                                                .remaining,
                                        )
                                    }}
                                </td>
                                <td class="px-2 py-2 text-right tabular-nums">
                                    {{
                                        percentLabel(
                                            props.budget_vs_actual.totals
                                                .percent,
                                        )
                                    }}
                                </td>
                                <td class="px-2 py-2" />
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </CardContent>
        </Card>

        <Card
            v-else-if="activeTab === 'budget'"
            class="rounded-2xl border-0 shadow-neu-flat"
        >
            <CardContent class="p-5">
                <p
                    class="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground"
                >
                    Belum ada budget di rentang ini. Buat budget lewat menu
                    Anggaran agar matriks ini terisi.
                </p>
            </CardContent>
        </Card>

        <!-- Komposisi pengeluaran -->
        <Card
            v-if="activeTab === 'expense' && props.expense_breakdown.has_data"
            class="rounded-2xl border-0 shadow-neu-flat"
        >
            <CardContent class="flex flex-col gap-4 p-5">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h3 class="text-sm font-semibold">
                        Pengeluaran per kategori induk
                    </h3>
                    <span class="text-xs text-muted-foreground">
                        Total
                        {{ formatCurrency(props.expense_breakdown.total) }}
                    </span>
                </div>

                <div class="grid gap-4 lg:grid-cols-2">
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[420px] text-sm">
                            <tbody>
                                <tr
                                    v-for="item in expenseRows"
                                    :key="item.category_id"
                                    class="border-b last:border-0"
                                >
                                    <td class="px-2 py-2">
                                        <div class="flex items-center gap-2">
                                            <span
                                                class="size-2.5 shrink-0 rounded-full"
                                                :style="{
                                                    backgroundColor: item.color,
                                                }"
                                            />
                                            <span class="truncate font-medium">
                                                {{ item.name }}
                                            </span>
                                        </div>
                                    </td>
                                    <td
                                        class="px-2 py-2 text-right tabular-nums"
                                    >
                                        {{ formatCurrency(item.total) }}
                                    </td>
                                    <td
                                        class="px-2 py-2 text-right text-muted-foreground tabular-nums"
                                    >
                                        {{ expensePercent(item) }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[420px] text-sm">
                            <thead
                                class="text-xs text-muted-foreground uppercase"
                            >
                                <tr class="border-b">
                                    <th class="px-2 py-2 text-left">Bulan</th>
                                    <th
                                        v-for="month in props.expense_breakdown
                                            .months"
                                        :key="month"
                                        class="px-2 py-2 text-right"
                                    >
                                        {{ month }}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="item in expenseRows"
                                    :key="item.category_id"
                                    class="border-b last:border-0"
                                >
                                    <td
                                        class="px-2 py-2 text-xs text-muted-foreground"
                                    >
                                        {{ item.name }}
                                    </td>
                                    <td
                                        v-for="month in props.expense_breakdown
                                            .months"
                                        :key="month"
                                        class="px-2 py-2 text-right text-xs text-muted-foreground tabular-nums"
                                    >
                                        {{
                                            formatCurrency(
                                                item.by_month[month] ?? '0.00',
                                            )
                                        }}
                                    </td>
                                </tr>
                                <tr class="border-t font-medium">
                                    <td class="px-2 py-2 text-xs">Total</td>
                                    <td
                                        v-for="month in props.expense_breakdown
                                            .months"
                                        :key="month"
                                        class="px-2 py-2 text-right text-xs tabular-nums"
                                    >
                                        {{ formatCurrency(monthTotal(month)) }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <p class="text-xs text-muted-foreground">
                    Pemasukan dan transfer tidak masuk hitungan ini; kategori
                    anak sudah tercakup di baris induknya.
                </p>
            </CardContent>
        </Card>

        <Card
            v-else-if="activeTab === 'expense'"
            class="rounded-2xl border-0 shadow-neu-flat"
        >
            <CardContent class="p-5">
                <p
                    class="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground"
                >
                    Belum ada pengeluaran tercatat di rentang ini.
                </p>
            </CardContent>
        </Card>
    </div>
</template>
