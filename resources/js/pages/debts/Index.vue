<script setup lang="ts">
import { computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatCurrency, formatDate } from '@/lib/format';
import {
    create as createDebt,
    index as debtsIndex,
    show as showDebt,
} from '@/routes/debts';
import type { DebtIndexProps, DebtStatus } from '@/types';

defineOptions({
    layout: {
        title: 'Utang & piutang',
        description: 'Sisa kewajiban dan tagihan yang harus ditagih',
    },
});

const props = defineProps<DebtIndexProps>();

const toneClass: Record<string, string> = {
    success: 'bg-success text-success-foreground',
    warning: 'bg-warning text-warning-foreground',
    danger: 'bg-destructive-solid text-destructive-foreground',
    neutral: 'bg-secondary text-secondary-foreground',
};

function filterBy(status: string): void {
    router.get(debtsIndex({ query: { status } }).url);
}

const summaryCards = computed(() => [
    {
        label: 'Total sisa',
        value: props.summary.total_remaining,
        hint: `${props.summary.count} catatan`,
    },
    {
        label: 'Telat bayar',
        value: props.summary.total_overdue,
        hint: 'Jatuh tempo sudah lewat',
    },
    {
        label: 'Saya berutang',
        value: props.summary.payable,
        hint: 'Kewajiban',
    },
    {
        label: 'Saya tagih',
        value: props.summary.receivable,
        hint: 'Piutang',
    },
]);

function dueHint(dueDate: string | null, daysUntilDue: number | null): string {
    if (dueDate === null) {
        return 'Tanpa jatuh tempo';
    }

    if (daysUntilDue === null) {
        return formatDate(dueDate);
    }

    if (daysUntilDue < 0) {
        return `Telat ${Math.abs(daysUntilDue)} hari`;
    }

    if (daysUntilDue === 0) {
        return 'Jatuh tempo hari ini';
    }

    return `${daysUntilDue} hari lagi`;
}
</script>

<template>
    <Head title="Utang & piutang" />

    <div class="flex flex-col gap-6">
        <Heading
            title="Utang & piutang"
            description="Sisa dana yang harus dibayar dan yang harus ditagih. Utang yang sudah punya riwayat pembayaran tidak bisa dihapus — tandai lunas dengan mencatat cicilan terakhir."
        />

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
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
                    <p class="mt-1 text-xl font-semibold">
                        {{ formatCurrency(card.value) }}
                    </p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        {{ card.hint }}
                    </p>
                </CardContent>
            </Card>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <span class="text-sm text-muted-foreground">Status</span>
                <Select
                    :model-value="props.status"
                    @update:model-value="filterBy(String($event))"
                >
                    <SelectTrigger class="w-40">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="status in props.statuses"
                            :key="status.value"
                            :value="status.value"
                        >
                            {{ status.label }}
                        </SelectItem>
                    </SelectContent>
                </Select>
            </div>

            <Button as-child size="sm" class="gap-2">
                <Link :href="createDebt().url">
                    <Plus class="size-4" />
                    Catat utang
                </Link>
            </Button>
        </div>

        <div
            v-if="props.debts.length === 0"
            class="rounded-2xl border border-dashed p-10 text-center text-sm text-muted-foreground"
        >
            Belum ada catatan pada status ini.
        </div>

        <Card
            v-for="debt in props.debts"
            :key="debt.id"
            class="rounded-2xl border-0 shadow-neu-flat"
        >
            <CardContent class="flex flex-col gap-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <h3 class="truncate font-medium">
                                {{ debt.counterparty }}
                            </h3>
                            <Badge
                                class="text-2xs"
                                :class="
                                    toneClass[debt.status_tone] ??
                                    toneClass.neutral
                                "
                            >
                                {{ debt.status_label }}
                            </Badge>
                            <Badge variant="outline" class="text-2xs">
                                {{ debt.direction_label }}
                            </Badge>
                        </div>
                        <p class="mt-1 text-xs text-muted-foreground">
                            Pokok {{ formatCurrency(debt.principal) }} · sudah
                            bayar
                            {{ formatCurrency(debt.paid) }}
                            ({{ debt.paid_percent }}%)
                        </p>
                    </div>

                    <div class="text-right">
                        <p class="text-xl font-semibold">
                            {{ formatCurrency(debt.remaining) }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ dueHint(debt.due_date, debt.days_until_due) }}
                        </p>
                    </div>
                </div>

                <div class="flex h-1.5 overflow-hidden rounded-full bg-muted">
                    <span
                        class="h-full rounded-full bg-success"
                        :style="{
                            width: `${Math.min(debt.paid_percent, 100)}%`,
                        }"
                    />
                </div>

                <div class="flex flex-wrap items-center justify-between gap-3">
                    <p class="text-xs text-muted-foreground">
                        <span v-if="debt.installment_amount">
                            Angsuran
                            {{ formatCurrency(debt.installment_amount) }}/bln
                            <template v-if="debt.term_count">
                                · tenor {{ debt.term_count }} bln ({{
                                    debt.paid_term_count
                                }}
                                terbayar)
                            </template>
                        </span>
                        <span v-else-if="debt.interest_rate">
                            Bunga {{ debt.interest_rate }}%/thn
                        </span>
                        <span v-else>Pembayaran bebas</span>
                        <span v-if="debt.account">
                            · {{ debt.account.name }}
                        </span>
                        <span v-if="debt.include_in_net_worth">
                            · masuk net worth
                        </span>
                    </p>

                    <Button as-child variant="outline" size="sm">
                        <Link :href="showDebt(debt.id).url">Detail</Link>
                    </Button>
                </div>
            </CardContent>
        </Card>
    </div>
</template>
