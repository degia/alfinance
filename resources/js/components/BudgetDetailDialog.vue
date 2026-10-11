<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { Trash2 } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatCurrency, formatDate } from '@/lib/format';
import {
    destroy as destroyBudget,
    show as showBudget,
    update as updateBudget,
} from '@/routes/budgets';
import type {
    BudgetDetail,
    BudgetDetailResponse,
    BudgetDetailTransaction,
    BudgetStatus,
} from '@/types';

const props = defineProps<{
    open: boolean;
    budgetId: number | null;
    canEdit: boolean;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
}>();

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

const detail = ref<BudgetDetail | null>(null);
const transactions = ref<BudgetDetailTransaction[]>([]);
const nextCursor = ref<string | null>(null);
const hasMore = ref(false);
const loading = ref(false);
const loadingMore = ref(false);
const error = ref<string | null>(null);
const confirmingDelete = ref(false);

const limitForm = useForm<{ limit_amount: string }>({ limit_amount: '' });

/** Unduh satu halaman transaksi (cursor `null` = halaman pertama). */
async function fetchPage(id: number, cursor: string | null): Promise<void> {
    const url = showBudget(
        { budget: id },
        cursor === null ? {} : { query: { cursor } },
    ).url;

    const response = await fetch(url, {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
    });

    if (!response.ok) {
        throw new Error(
            `Gagal memuat detail anggaran (status ${response.status}).`,
        );
    }

    const payload = (await response.json()) as BudgetDetailResponse;

    detail.value = payload.budget;
    transactions.value =
        cursor === null
            ? payload.transactions
            : [...transactions.value, ...payload.transactions];
    nextCursor.value = payload.next_cursor;
    hasMore.value = payload.has_more;
}

function resetState(): void {
    detail.value = null;
    transactions.value = [];
    nextCursor.value = null;
    hasMore.value = false;
    error.value = null;
    confirmingDelete.value = false;
    limitForm.clearErrors();
}

async function loadFirstPage(id: number): Promise<void> {
    loading.value = true;
    error.value = null;

    try {
        await fetchPage(id, null);

        limitForm.defaults({ limit_amount: detail.value?.limit ?? '' });
        limitForm.reset();
    } catch (exception) {
        error.value =
            exception instanceof Error
                ? exception.message
                : 'Gagal memuat detail anggaran.';
    } finally {
        loading.value = false;
    }
}

async function loadMore(): Promise<void> {
    const id = props.budgetId;

    if (id === null || nextCursor.value === null || loadingMore.value) {
        return;
    }

    loadingMore.value = true;

    try {
        await fetchPage(id, nextCursor.value);
    } catch (exception) {
        error.value =
            exception instanceof Error
                ? exception.message
                : 'Gagal memuat transaksi berikutnya.';
    } finally {
        loadingMore.value = false;
    }
}

watch(
    () => [props.open, props.budgetId] as const,
    ([open, id]) => {
        resetState();

        if (open && id !== null) {
            void loadFirstPage(id);
        }
    },
    { immediate: true },
);

function saveLimit(): void {
    const id = props.budgetId;

    if (id === null) {
        return;
    }

    limitForm.put(updateBudget(id).url, {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
    });
}

function removeBudget(): void {
    const id = props.budgetId;

    if (id === null) {
        return;
    }

    router.delete(destroyBudget(id).url, {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
    });
}

const percentLabel = computed(() =>
    detail.value?.percent === null || detail.value?.percent === undefined
        ? '-'
        : `${detail.value.percent}%`,
);
</script>

<template>
    <Dialog :open="open" @update:open="emit('update:open', $event)">
        <DialogContent class="sm:max-w-2xl">
            <DialogHeader>
                <DialogTitle>Detail anggaran</DialogTitle>
                <DialogDescription v-if="detail">
                    {{
                        detail.category.parent_name
                            ? `${detail.category.parent_name} / `
                            : ''
                    }}{{ detail.category.name }} · {{ detail.month_label }}
                </DialogDescription>
                <DialogDescription v-else>
                    Transaksi pengeluaran yang sudah memotong limit kategori
                    ini.
                </DialogDescription>
            </DialogHeader>

            <div
                v-if="loading"
                class="flex items-center justify-center py-10 text-muted-foreground"
            >
                <Spinner class="size-5" />
            </div>

            <div
                v-else-if="error"
                class="rounded-xl border border-destructive/40 bg-destructive/5 p-4 text-sm text-destructive"
            >
                {{ error }}
            </div>

            <div v-else-if="detail" class="flex flex-col gap-5">
                <div class="grid gap-3 sm:grid-cols-3">
                    <div class="neu-inset rounded-xl px-3 py-2">
                        <p class="text-xs text-muted-foreground">Limit</p>
                        <p class="font-semibold">
                            {{ formatCurrency(detail.limit) }}
                        </p>
                    </div>
                    <div class="neu-inset rounded-xl px-3 py-2">
                        <p class="text-xs text-muted-foreground">Terpakai</p>
                        <p class="font-semibold">
                            {{ formatCurrency(detail.used) }}
                        </p>
                    </div>
                    <div class="neu-inset rounded-xl px-3 py-2">
                        <p class="text-xs text-muted-foreground">Sisa</p>
                        <p
                            class="font-semibold"
                            :class="statusTone[detail.status].text"
                        >
                            {{ formatCurrency(detail.remaining) }}
                        </p>
                    </div>
                </div>

                <div class="flex flex-col gap-1.5">
                    <div class="flex items-center justify-between text-xs">
                        <span :class="statusTone[detail.status].text">
                            {{ statusTone[detail.status].label }} ·
                            {{ percentLabel }}
                        </span>
                        <span
                            v-if="detail.includes_subcategories"
                            class="text-muted-foreground"
                        >
                            Termasuk sub-kategori
                        </span>
                    </div>
                    <span
                        class="flex h-2 w-full overflow-hidden rounded-full bg-muted"
                    >
                        <span
                            class="h-full rounded-full"
                            :class="statusTone[detail.status].bar"
                            :style="{
                                width: `${Math.min(detail.percent ?? 0, 100)}%`,
                            }"
                        />
                    </span>
                </div>

                <div class="flex flex-col gap-2">
                    <p class="text-sm font-medium">
                        Transaksi yang memotong limit
                    </p>

                    <div
                        v-if="transactions.length === 0"
                        class="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground"
                    >
                        Belum ada pengeluaran yang memotong limit ini.
                    </div>

                    <ul
                        v-else
                        class="neu-inset max-h-64 divide-y overflow-y-auto rounded-xl"
                    >
                        <li
                            v-for="transaction in transactions"
                            :key="transaction.id"
                            class="flex items-center justify-between gap-3 px-3 py-2 text-sm"
                        >
                            <div class="min-w-0">
                                <p class="truncate font-medium">
                                    {{ transaction.note || 'Tanpa catatan' }}
                                </p>
                                <p
                                    class="truncate text-xs text-muted-foreground"
                                >
                                    {{ formatDate(transaction.occurred_at) }}
                                    <template v-if="transaction.category_name">
                                        ·
                                        {{
                                            transaction.category_parent_name
                                                ? `${transaction.category_parent_name} / `
                                                : ''
                                        }}{{ transaction.category_name }}
                                    </template>
                                    <template v-if="transaction.account_name">
                                        · {{ transaction.account_name }}
                                    </template>
                                </p>
                            </div>
                            <span class="shrink-0 font-medium">
                                {{ formatCurrency(transaction.amount) }}
                            </span>
                        </li>
                    </ul>

                    <Button
                        v-if="hasMore"
                        variant="outline"
                        size="sm"
                        class="gap-2"
                        :disabled="loadingMore"
                        @click="loadMore"
                    >
                        <Spinner v-if="loadingMore" class="size-4" />
                        Muat lebih banyak
                    </Button>
                </div>

                <div
                    v-if="canEdit"
                    class="flex flex-col gap-4 border-t border-border/60 pt-4"
                >
                    <form
                        class="flex flex-col gap-2"
                        @submit.prevent="saveLimit"
                    >
                        <Label for="budget-detail-limit">Ubah limit (Rp)</Label>
                        <div class="flex gap-2">
                            <Input
                                id="budget-detail-limit"
                                v-model="limitForm.limit_amount"
                                inputmode="numeric"
                                required
                                class="shadow-neu-inset"
                                :aria-invalid="
                                    Boolean(limitForm.errors.limit_amount)
                                "
                            />
                            <Button
                                type="submit"
                                class="gap-2"
                                :disabled="limitForm.processing"
                            >
                                <Spinner
                                    v-if="limitForm.processing"
                                    class="size-4"
                                />
                                Simpan
                            </Button>
                        </div>
                        <InputError :message="limitForm.errors.limit_amount" />
                    </form>

                    <div class="flex items-center justify-between gap-3">
                        <p class="text-xs text-muted-foreground">
                            Menghapus limit tidak menghapus transaksinya.
                        </p>

                        <Button
                            v-if="!confirmingDelete"
                            variant="ghost"
                            size="sm"
                            class="gap-2 text-destructive"
                            @click="confirmingDelete = true"
                        >
                            <Trash2 class="size-4" />
                            Hapus
                        </Button>

                        <div v-else class="flex gap-2">
                            <Button
                                variant="ghost"
                                size="sm"
                                @click="confirmingDelete = false"
                            >
                                Batal
                            </Button>
                            <Button
                                variant="destructive"
                                size="sm"
                                @click="removeBudget"
                            >
                                Hapus limit
                            </Button>
                        </div>
                    </div>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
