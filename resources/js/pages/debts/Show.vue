<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Pencil, Plus, Trash2 } from '@lucide/vue';
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
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
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
import { formatCurrency, formatDate } from '@/lib/format';
import {
    destroy as destroyDebt,
    edit as editDebt,
    index as debtsIndex,
} from '@/routes/debts';
import { store as storePayment } from '@/routes/debts/payments';
import type {
    Debt,
    DebtFormOptions,
    DebtPayment,
    DebtPaymentFormData,
} from '@/types';

defineOptions({
    layout: {
        title: 'Detail utang',
        description: 'Riwayat cicilan dan sisa tagihan',
    },
});

const props = defineProps<{
    debt: Debt;
    payments: DebtPayment[];
    options: DebtFormOptions;
}>();

const toneClass: Record<string, string> = {
    success: 'bg-success text-success-foreground',
    warning: 'bg-warning text-warning-foreground',
    danger: 'bg-destructive-solid text-destructive-foreground',
    neutral: 'bg-secondary text-secondary-foreground',
};

const isPaymentOpen = ref(false);

const emptyPayment = (): DebtPaymentFormData => ({
    amount: props.debt.remaining,
    paid_at: new Date().toISOString().slice(0, 10),
    note: '',
    create_transaction: true,
    transaction: {
        account_id:
            props.debt.account === null ? '' : String(props.debt.account.id),
        category_id: '',
        note: '',
    },
});

const payment = useForm<DebtPaymentFormData>(emptyPayment());

function openPayment(): void {
    payment.defaults(emptyPayment());
    payment.reset();
    payment.clearErrors();
    isPaymentOpen.value = true;
}

function submitPayment(): void {
    payment.post(storePayment(props.debt.id).url, {
        preserveScroll: true,
        onSuccess: () => {
            isPaymentOpen.value = false;
        },
    });
}

function remove(): void {
    router.delete(destroyDebt(props.debt.id).url, { preserveScroll: true });
}

/** Utang yang sudah punya cicilan tidak bisa dihapus; service yang menolak. */
const hasPayments = computed(() => props.payments.length > 0);
</script>

<template>
    <Head :title="`Utang ${props.debt.counterparty}`" />

    <div class="flex flex-col gap-6">
        <Button as-child variant="ghost" size="sm" class="w-fit">
            <Link :href="debtsIndex().url">
                <ArrowLeft class="size-4" />
                Kembali ke daftar
            </Link>
        </Button>

        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <div class="flex items-center gap-2">
                    <h2 class="text-xl font-semibold">
                        {{ props.debt.counterparty }}
                    </h2>
                    <Badge
                        class="text-[10px]"
                        :class="
                            toneClass[props.debt.status_tone] ??
                            toneClass.neutral
                        "
                    >
                        {{ props.debt.status_label }}
                    </Badge>
                    <Badge variant="outline" class="text-[10px]">
                        {{ props.debt.direction_label }}
                    </Badge>
                </div>
                <p class="mt-1 text-sm text-muted-foreground">
                    Pokok {{ formatCurrency(props.debt.principal) }} · sudah
                    bayar
                    {{ formatCurrency(props.debt.paid) }}
                    ({{ props.debt.paid_percent }}%)
                </p>
            </div>

            <div class="flex items-center gap-2">
                <Button as-child variant="outline" size="sm">
                    <Link :href="editDebt(props.debt.id).url">
                        <Pencil class="size-4" />
                        Ubah
                    </Link>
                </Button>
                <Button
                    variant="ghost"
                    size="sm"
                    class="text-muted-foreground"
                    :disabled="hasPayments"
                    :aria-label="`Hapus utang ${props.debt.counterparty}`"
                    @click="remove"
                >
                    <Trash2 class="size-4" />
                </Button>
            </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-3">
            <Card class="rounded-2xl border-0 shadow-neu-flat">
                <CardContent class="p-5">
                    <p
                        class="text-xs tracking-wide text-muted-foreground uppercase"
                    >
                        Sisa tagihan
                    </p>
                    <p class="mt-1 text-xl font-semibold">
                        {{ formatCurrency(props.debt.remaining) }}
                    </p>
                </CardContent>
            </Card>
            <Card class="rounded-2xl border-0 shadow-neu-flat">
                <CardContent class="p-5">
                    <p
                        class="text-xs tracking-wide text-muted-foreground uppercase"
                    >
                        Jatuh tempo
                    </p>
                    <p class="mt-1 text-xl font-semibold">
                        {{ formatDate(props.debt.due_date) }}
                    </p>
                </CardContent>
            </Card>
            <Card class="rounded-2xl border-0 shadow-neu-flat">
                <CardContent class="p-5">
                    <p
                        class="text-xs tracking-wide text-muted-foreground uppercase"
                    >
                        Cicilan
                    </p>
                    <p class="mt-1 text-xl font-semibold">
                        {{ props.debt.paid_term_count }}
                        <span
                            v-if="props.debt.term_count"
                            class="text-sm font-normal text-muted-foreground"
                        >
                            / {{ props.debt.term_count }}
                        </span>
                    </p>
                </CardContent>
            </Card>
        </div>

        <Card class="rounded-2xl border-0 shadow-neu-flat">
            <CardContent class="flex flex-col gap-4 p-5">
                <div class="flex items-center justify-between gap-3">
                    <h3 class="text-sm font-semibold">Riwayat cicilan</h3>
                    <Button size="sm" class="gap-2" @click="openPayment">
                        <Plus class="size-4" />
                        Catat cicilan
                    </Button>
                </div>

                <p
                    v-if="props.payments.length === 0"
                    class="rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground"
                >
                    Belum ada cicilan.
                </p>

                <ul v-else class="flex flex-col gap-2">
                    <li
                        v-for="row in props.payments"
                        :key="row.id"
                        class="neu-inset flex items-center justify-between gap-3 rounded-lg px-3 py-2"
                    >
                        <div>
                            <p class="text-sm font-medium">
                                {{ formatCurrency(row.amount) }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {{ formatDate(row.paid_at) }}
                                <span v-if="row.note">· {{ row.note }}</span>
                                <span v-if="row.transaction_id">
                                    · transaksi #{{ row.transaction_id }}
                                </span>
                            </p>
                        </div>
                    </li>
                </ul>
            </CardContent>
        </Card>

        <Dialog v-model:open="isPaymentOpen">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Catat cicilan</DialogTitle>
                    <DialogDescription>
                        Sisa saat ini
                        {{ formatCurrency(props.debt.remaining) }}. Cicilan
                        tidak boleh melebihi sisa.
                    </DialogDescription>
                </DialogHeader>

                <form
                    class="flex flex-col gap-4"
                    @submit.prevent="submitPayment"
                >
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="payment-amount">Nominal (Rp)</Label>
                            <Input
                                id="payment-amount"
                                v-model="payment.amount"
                                inputmode="decimal"
                                required
                                class="shadow-neu-inset"
                                :aria-invalid="Boolean(payment.errors.amount)"
                            />
                            <InputError :message="payment.errors.amount" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="payment-date">Tanggal bayar</Label>
                            <Input
                                id="payment-date"
                                v-model="payment.paid_at"
                                type="date"
                                required
                                class="shadow-neu-inset"
                                :aria-invalid="Boolean(payment.errors.paid_at)"
                            />
                            <InputError :message="payment.errors.paid_at" />
                        </div>
                    </div>

                    <div class="grid gap-2">
                        <Label for="payment-note">Catatan</Label>
                        <Input
                            id="payment-note"
                            v-model="payment.note"
                            maxlength="500"
                            class="shadow-neu-inset"
                        />
                        <InputError :message="payment.errors.note" />
                    </div>

                    <label class="flex items-center gap-2 text-sm">
                        <input
                            v-model="payment.create_transaction"
                            type="checkbox"
                            class="size-4 rounded border-input"
                        />
                        Sekaligus buat transaksi di modul Transaksi
                    </label>

                    <div
                        v-if="payment.create_transaction"
                        class="grid gap-4 sm:grid-cols-2"
                    >
                        <div class="grid gap-2">
                            <Label for="payment-account">Akun</Label>
                            <Select v-model="payment.transaction.account_id">
                                <SelectTrigger
                                    id="payment-account"
                                    class="w-full"
                                >
                                    <SelectValue placeholder="Pilih akun" />
                                </SelectTrigger>
                                <SelectContent>
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
                                :message="
                                    payment.errors['transaction.account_id']
                                "
                            />
                        </div>

                        <div class="grid gap-2">
                            <Label for="payment-category">Kategori</Label>
                            <Select v-model="payment.transaction.category_id">
                                <SelectTrigger
                                    id="payment-category"
                                    class="w-full"
                                >
                                    <SelectValue placeholder="Tanpa kategori" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value=""
                                        >Tanpa kategori</SelectItem
                                    >
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
                                :message="
                                    payment.errors['transaction.category_id']
                                "
                            />
                        </div>
                    </div>

                    <DialogFooter>
                        <Button
                            type="submit"
                            class="gap-2 sm:w-40"
                            :disabled="payment.processing"
                        >
                            <Spinner v-if="payment.processing" class="size-4" />
                            Simpan
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
