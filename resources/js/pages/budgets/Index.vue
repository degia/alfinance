<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
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
import { formatCurrency } from '@/lib/format';
import { index as budgetsIndex, store as storeBudget } from '@/routes/budgets';
import type { BudgetFormData, BudgetIndexProps, BudgetStatus } from '@/types';

defineOptions({
    layout: {
        title: 'Anggaran',
        description:
            'Limit per kategori per bulan, dibaca dari cache pemakaian',
    },
});

const props = defineProps<BudgetIndexProps>();

/**
 * Warna status mengikuti PRD.md §3.5: hijau < 80%, kuning 80–100%, merah > 100%.
 */
const statusTone: Record<
    BudgetStatus,
    { bar: string; text: string; label: string }
> = {
    unset: {
        bar: 'bg-muted-foreground/30',
        text: 'text-muted-foreground',
        label: 'Belum ada limit',
    },
    healthy: {
        bar: 'bg-emerald-500',
        text: 'text-emerald-600 dark:text-emerald-400',
        label: 'Aman',
    },
    warning: {
        bar: 'bg-amber-500',
        text: 'text-amber-600 dark:text-amber-400',
        label: 'Hampir habis',
    },
    over: {
        bar: 'bg-rose-500',
        text: 'text-rose-600 dark:text-rose-400',
        label: 'Lewat',
    },
};

const trackedStatuses: BudgetStatus[] = ['healthy', 'warning', 'over'];

const isFormOpen = ref(false);
const form = useForm<BudgetFormData>({
    category_id: '',
    month: props.month,
    limit_amount: '',
});

function openCreate(month: string, categoryId?: number): void {
    form.defaults({
        category_id: categoryId === undefined ? '' : String(categoryId),
        month,
        limit_amount: '',
    });
    form.reset();
    form.clearErrors();
    isFormOpen.value = true;
}

function submit(): void {
    form.post(storeBudget().url, {
        preserveScroll: true,
        onSuccess: () => {
            isFormOpen.value = false;
        },
    });
}

function shiftMonth(month: string, delta: number): string {
    const [year, number] = month.split('-').map(Number);
    const shifted = new Date(year, number - 1 + delta, 1);

    return `${shifted.getFullYear()}-${String(shifted.getMonth() + 1).padStart(2, '0')}`;
}

const previousMonth = computed(() => shiftMonth(props.month, -1));
const nextMonth = computed(() => shiftMonth(props.month, 1));

function goTo(month: string): void {
    router.get(
        budgetsIndex({
            query: { month, year: Number(month.slice(0, 4)) },
        }).url,
    );
}

const summaryCards = computed(() => [
    {
        label: 'Total limit',
        value: props.summary.total_limit,
        hint: 'Jumlah limit yang terisi di tahun ini',
    },
    {
        label: 'Total terpakai',
        value: props.summary.total_used,
        hint: 'Dibaca dari budget_progress_cache',
    },
    {
        label: 'Sisa anggaran',
        value: props.summary.total_remaining,
        hint: 'Negatif berarti sudah lewat',
    },
]);
</script>

<template>
    <Head title="Anggaran" />

    <div class="flex flex-col gap-6">
        <Heading
            title="Anggaran"
            description="Batas belanja per kategori dan per bulan. Angka terpakai berasal dari cache, jadi halaman ini tidak pernah menghitung ulang dari ribuan transaksi."
        />

        <div class="grid gap-4 sm:grid-cols-3">
            <Card
                v-for="card in summaryCards"
                :key="card.label"
                class="rounded-2xl border-0 shadow-neu-flat"
            >
                <CardContent class="p-5">
                    <p
                        class="text-xs tracking-wide text-muted-foreground uppercase"
                    >
                        {{ card.label }}
                    </p>
                    <p class="mt-1 text-xl font-semibold">
                        {{ formatCurrency(card.value) }}
                    </p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        {{ card.hint }}
                    </p>
                </CardContent>
            </Card>
        </div>

        <Card class="rounded-2xl border-0 shadow-neu-flat">
            <CardContent class="flex flex-col gap-4 p-5">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="flex items-center gap-2">
                        <Button
                            variant="ghost"
                            size="sm"
                            :aria-label="`Bulan sebelumnya, ${previousMonth}`"
                            @click="goTo(previousMonth)"
                        >
                            <ChevronLeft class="size-4" />
                        </Button>
                        <p class="min-w-40 text-center text-sm font-medium">
                            {{ props.month_label }}
                        </p>
                        <Button
                            variant="ghost"
                            size="sm"
                            :aria-label="`Bulan berikutnya, ${nextMonth}`"
                            @click="goTo(nextMonth)"
                        >
                            <ChevronRight class="size-4" />
                        </Button>
                    </div>

                    <div class="flex items-center gap-3 text-xs">
                        <span
                            v-for="status in trackedStatuses"
                            :key="status"
                            class="flex items-center gap-1.5"
                        >
                            <span
                                class="size-2 rounded-full"
                                :class="statusTone[status].bar"
                            />
                            <span class="text-muted-foreground">
                                {{ statusTone[status].label }}
                                ({{ props.summary.by_status[status] }})
                            </span>
                        </span>
                    </div>

                    <Button
                        size="sm"
                        class="gap-2"
                        @click="openCreate(props.month)"
                    >
                        Tambah limit
                    </Button>
                </div>

                <div
                    v-if="props.categories.length === 0"
                    class="rounded-xl border border-dashed p-10 text-center text-sm text-muted-foreground"
                >
                    Belum ada kategori. Buat kategori dulu di halaman Kategori,
                    baru tentukan limitnya di sini.
                </div>

                <div v-else class="overflow-x-auto">
                    <table
                        class="w-full min-w-[64rem] border-separate border-spacing-0 text-sm"
                    >
                        <thead>
                            <tr>
                                <th
                                    class="sticky left-0 z-10 bg-background px-3 py-2 text-left font-medium text-muted-foreground"
                                >
                                    Kategori
                                </th>
                                <th
                                    v-for="column in props.months"
                                    :key="column.month"
                                    class="px-2 py-2 text-center font-medium"
                                    :class="
                                        column.month === props.month
                                            ? 'text-foreground'
                                            : 'text-muted-foreground'
                                    "
                                >
                                    {{ column.label }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in props.categories" :key="row.id">
                                <th
                                    class="sticky left-0 z-10 bg-background px-3 py-1.5 text-left font-medium whitespace-nowrap"
                                    :class="
                                        row.is_nested
                                            ? 'pl-6 font-normal text-muted-foreground'
                                            : ''
                                    "
                                >
                                    <span class="flex items-center gap-2">
                                        <span
                                            class="size-2 shrink-0 rounded-full"
                                            :style="{
                                                backgroundColor:
                                                    row.color ?? 'transparent',
                                            }"
                                        />
                                        {{ row.name }}
                                    </span>
                                </th>

                                <td
                                    v-for="cell in row.months"
                                    :key="cell.month"
                                    class="px-1 py-1 text-center align-middle"
                                >
                                    <div
                                        class="neu-inset flex min-h-16 flex-col items-center justify-center gap-1 rounded-lg px-1 py-1"
                                        :class="
                                            cell.month === props.month
                                                ? 'ring-1 ring-primary/40'
                                                : ''
                                        "
                                    >
                                        <template v-if="cell.limit !== null">
                                            <span
                                                class="text-[11px] text-muted-foreground"
                                            >
                                                {{ formatCurrency(cell.used) }}
                                                /{{
                                                    formatCurrency(cell.limit)
                                                }}
                                            </span>
                                            <span
                                                class="flex h-1.5 w-full overflow-hidden rounded-full bg-muted"
                                            >
                                                <span
                                                    class="h-full rounded-full"
                                                    :class="
                                                        statusTone[cell.status]
                                                            .bar
                                                    "
                                                    :style="{
                                                        width: `${Math.min(
                                                            cell.percent ?? 0,
                                                            100,
                                                        )}%`,
                                                    }"
                                                />
                                            </span>
                                            <span
                                                class="text-[10px] font-medium"
                                                :class="
                                                    statusTone[cell.status].text
                                                "
                                            >
                                                {{
                                                    cell.percent === null
                                                        ? '-'
                                                        : `${cell.percent}%`
                                                }}
                                            </span>
                                        </template>

                                        <template v-else>
                                            <span
                                                class="text-[11px] text-muted-foreground"
                                            >
                                                {{
                                                    cell.used === '0.00'
                                                        ? '—'
                                                        : formatCurrency(
                                                              cell.used,
                                                          )
                                                }}
                                            </span>
                                            <button
                                                type="button"
                                                class="neu-color rounded text-[10px] text-primary hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                                @click="
                                                    openCreate(
                                                        cell.month,
                                                        row.id,
                                                    )
                                                "
                                            >
                                                + limit
                                            </button>
                                        </template>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </CardContent>
        </Card>

        <Dialog v-model:open="isFormOpen">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Tambah limit anggaran</DialogTitle>
                    <DialogDescription>
                        Nominal disimpan sebagai batas kategori untuk bulan
                        tersebut. Pemakaian yang sudah berjalan tidak ikut
                        tersentuh.
                    </DialogDescription>
                </DialogHeader>

                <form class="flex flex-col gap-4" @submit.prevent="submit">
                    <div class="grid gap-2">
                        <Label for="budget-category">Kategori</Label>
                        <Select v-model="form.category_id">
                            <SelectTrigger id="budget-category" class="w-full">
                                <SelectValue placeholder="Pilih kategori" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="category in props.options.categories"
                                    :key="category.id"
                                    :value="String(category.id)"
                                >
                                    {{ category.name }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="form.errors.category_id" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="budget-month">Bulan</Label>
                        <Select v-model="form.month">
                            <SelectTrigger id="budget-month" class="w-full">
                                <SelectValue placeholder="Pilih bulan" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="column in props.options.months"
                                    :key="column.month"
                                    :value="column.month"
                                >
                                    {{ column.label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="form.errors.month" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="budget-limit">Limit (Rp)</Label>
                        <Input
                            id="budget-limit"
                            v-model="form.limit_amount"
                            inputmode="numeric"
                            required
                            placeholder="Contoh: 1500000"
                            class="shadow-neu-inset"
                            :aria-invalid="Boolean(form.errors.limit_amount)"
                        />
                        <InputError :message="form.errors.limit_amount" />
                    </div>

                    <DialogFooter>
                        <Button
                            type="submit"
                            class="gap-2 sm:w-40"
                            :disabled="form.processing"
                        >
                            <Spinner v-if="form.processing" class="size-4" />
                            Simpan
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
