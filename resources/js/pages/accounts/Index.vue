<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { Archive, ArchiveRestore, Pencil, Plus, Wallet, X } from '@lucide/vue';
import AccountIcon from '@/components/AccountIcon.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
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
import {
    formatCurrency,
    formatCurrencyPrecise,
    formatDate,
} from '@/lib/format';
import { archive, index, restore, store, update } from '@/routes/accounts';
import type {
    AccountFilters,
    AccountFormData,
    AccountListItem,
    AccountType,
} from '@/types';

defineOptions({
    layout: {
        title: 'Akun',
        description: 'Kelola kas, rekening, e-wallet, dan kartu kredit',
    },
});

const props = defineProps<{
    accounts: AccountListItem[];
    filters: AccountFilters;
    totalBalance: string;
}>();

const typeOptions: { value: AccountType; label: string }[] = [
    { value: 'cash', label: 'Kas / Tunai' },
    { value: 'bank', label: 'Rekening Bank' },
    { value: 'ewallet', label: 'E-Wallet' },
    { value: 'saving', label: 'Tabungan' },
    { value: 'credit_card', label: 'Kartu Kredit' },
];

const emptyForm = (): AccountFormData => ({
    name: '',
    type: 'cash',
    initial_balance: '0',
    credit_limit: '',
    billing_day: '',
    due_day: '',
    notes: '',
});

const form = useForm<AccountFormData>(emptyForm());
const editingId = ref<number | null>(null);

const isEditing = computed(() => editingId.value !== null);
const isCredit = computed(() => form.type === 'credit_card');

function startCreate(): void {
    editingId.value = null;
    form.defaults(emptyForm());
    form.reset();
    form.clearErrors();
}

function startEdit(account: AccountListItem): void {
    editingId.value = account.id;
    form.defaults({
        name: account.name,
        type: account.type,
        initial_balance: account.initial_balance,
        credit_limit: account.credit_limit ?? '',
        billing_day: account.billing_day?.toString() ?? '',
        due_day: account.due_day?.toString() ?? '',
        notes: account.notes ?? '',
    });
    form.reset();
    form.clearErrors();
}

function submit(): void {
    if (editingId.value === null) {
        form.post(store().url, {
            preserveScroll: true,
            onSuccess: () => startCreate(),
        });

        return;
    }

    form.put(update(editingId.value).url, {
        preserveScroll: true,
        onSuccess: () => startCreate(),
    });
}

function archiveAccount(account: AccountListItem): void {
    router.post(archive(account.id).url, { preserveScroll: true });
}

function restoreAccount(account: AccountListItem): void {
    router.post(restore(account.id).url, { preserveScroll: true });
}

function setFilter(archived: boolean): void {
    router.get(index.url({ query: { archived: archived ? '1' : undefined } }), {
        preserveScroll: true,
        replace: true,
    });
}

function creditTone(percent: number): string {
    if (percent >= 80) {
        return 'bg-destructive';
    }

    if (percent >= 50) {
        return 'bg-warning';
    }

    return 'bg-primary';
}
</script>

<template>
    <Head title="Akun" />

    <div class="flex flex-col gap-6">
        <Heading
            title="Akun"
            description="Setiap akun punya saldo sendiri. Kartu kredit dicatat sebagai liabilitas."
        />

        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <Button
                    :variant="filters.archived ? 'ghost' : 'secondary'"
                    :class="filters.archived ? 'shadow-neu-pressed-sm' : ''"
                    @click="setFilter(false)"
                >
                    Aktif
                </Button>
                <Button
                    :variant="filters.archived ? 'secondary' : 'ghost'"
                    :class="filters.archived ? 'shadow-neu-pressed-sm' : ''"
                    @click="setFilter(true)"
                >
                    Diarsipkan
                </Button>
            </div>

            <Card class="rounded-2xl border-0 shadow-neu-flat">
                <CardContent class="flex items-center gap-2">
                    <Wallet class="size-4 text-primary" />
                    <span class="text-xs text-muted-foreground"
                        >Total saldo</span
                    >
                    <span class="text-sm font-semibold tabular-nums">
                        {{ formatCurrency(totalBalance) }}
                    </span>
                </CardContent>
            </Card>
        </div>

        <Card class="w-full max-w-4xl rounded-2xl border-0 shadow-neu-flat">
            <CardContent>
                <div class="mb-4 flex items-center justify-between">
                    <h3 class="text-sm font-semibold">
                        {{ isEditing ? 'Ubah akun' : 'Tambah akun' }}
                    </h3>
                    <Button
                        v-if="isEditing"
                        variant="ghost"
                        size="sm"
                        @click="startCreate"
                    >
                        <X class="size-4" />
                        Batal
                    </Button>
                </div>

                <form class="flex flex-col gap-4" @submit.prevent="submit">
                    <div class="grid gap-4 md:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="account-name">Nama akun</Label>
                            <Input
                                id="account-name"
                                v-model="form.name"
                                name="name"
                                required
                                maxlength="60"
                                autocomplete="off"
                                placeholder="Contoh: BCA Tabungan"
                                class="shadow-neu-inset"
                                :aria-invalid="Boolean(form.errors.name)"
                            />
                            <InputError :message="form.errors.name" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="account-type">Tipe akun</Label>
                            <Select v-model="form.type">
                                <SelectTrigger
                                    id="account-type"
                                    class="w-full shadow-neu-inset"
                                    :aria-invalid="Boolean(form.errors.type)"
                                >
                                    <SelectValue placeholder="Pilih tipe" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="option in typeOptions"
                                        :key="option.value"
                                        :value="option.value"
                                    >
                                        {{ option.label }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <InputError :message="form.errors.type" />
                        </div>
                    </div>

                    <div class="grid gap-4 md:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="account-balance">Saldo awal</Label>
                            <Input
                                id="account-balance"
                                v-model="form.initial_balance"
                                name="initial_balance"
                                type="text"
                                inputmode="decimal"
                                required
                                placeholder="0"
                                class="shadow-neu-inset"
                                :aria-invalid="
                                    Boolean(form.errors.initial_balance)
                                "
                            />
                            <p
                                v-if="isEditing"
                                class="text-xs text-muted-foreground"
                            >
                                Mengubah saldo awal menggeser saldo saat ini
                                sebesar selisihnya. Saldo transaksi tidak
                                ditimpa.
                            </p>
                            <InputError
                                :message="form.errors.initial_balance"
                            />
                        </div>

                        <div v-if="isCredit" class="grid gap-2">
                            <Label for="account-limit">Limit kredit</Label>
                            <Input
                                id="account-limit"
                                v-model="form.credit_limit"
                                name="credit_limit"
                                type="text"
                                inputmode="decimal"
                                required
                                placeholder="10000000"
                                class="shadow-neu-inset"
                                :aria-invalid="
                                    Boolean(form.errors.credit_limit)
                                "
                            />
                            <InputError :message="form.errors.credit_limit" />
                        </div>
                    </div>

                    <div v-if="isCredit" class="grid gap-4 md:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="account-billing-day">
                                Tanggal cetak tagihan
                            </Label>
                            <Input
                                id="account-billing-day"
                                v-model="form.billing_day"
                                name="billing_day"
                                type="number"
                                min="1"
                                max="31"
                                required
                                placeholder="5"
                                class="shadow-neu-inset"
                                :aria-invalid="Boolean(form.errors.billing_day)"
                            />
                            <p class="text-xs text-muted-foreground">
                                Tanggal dalam bulan (1–31).
                            </p>
                            <InputError :message="form.errors.billing_day" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="account-due-day">
                                Tanggal jatuh tempo
                            </Label>
                            <Input
                                id="account-due-day"
                                v-model="form.due_day"
                                name="due_day"
                                type="number"
                                min="1"
                                max="31"
                                required
                                placeholder="25"
                                class="shadow-neu-inset"
                                :aria-invalid="Boolean(form.errors.due_day)"
                            />
                            <InputError :message="form.errors.due_day" />
                        </div>
                    </div>

                    <div class="flex justify-end">
                        <Button
                            type="submit"
                            class="gap-2 sm:w-40"
                            :disabled="form.processing"
                        >
                            <Spinner v-if="form.processing" class="size-4" />
                            <Pencil v-else-if="isEditing" class="size-4" />
                            <Plus v-else class="size-4" />
                            {{ isEditing ? 'Simpan' : 'Tambah' }}
                        </Button>
                    </div>
                </form>
            </CardContent>
        </Card>

        <div
            v-if="accounts.length === 0"
            class="rounded-2xl border border-dashed p-10 text-center text-sm text-muted-foreground"
        >
            {{
                filters.archived
                    ? 'Belum ada akun yang diarsipkan.'
                    : 'Belum ada akun. Tambahkan kas atau rekening pertamamu di atas.'
            }}
        </div>

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <Card
                v-for="account in accounts"
                :key="account.id"
                class="rounded-2xl border-0 shadow-neu-flat"
            >
                <CardContent class="flex flex-col gap-4">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex min-w-0 items-center gap-3">
                            <span
                                class="grid size-10 shrink-0 place-items-center rounded-xl bg-primary/10 text-primary"
                            >
                                <AccountIcon :icon="account.type_icon" />
                            </span>
                            <div class="min-w-0">
                                <p class="truncate font-medium">
                                    {{ account.name }}
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    {{ account.type_label }}
                                </p>
                            </div>
                        </div>

                        <Badge
                            v-if="account.is_archived"
                            variant="secondary"
                            class="shrink-0 text-2xs"
                        >
                            Diarsipkan
                        </Badge>
                    </div>

                    <div>
                        <p class="text-xs text-muted-foreground">Saldo</p>
                        <p
                            class="text-xl font-semibold tabular-nums"
                            :class="
                                Number(account.cached_balance) < 0
                                    ? 'text-expense'
                                    : 'text-foreground'
                            "
                        >
                            {{ formatCurrencyPrecise(account.cached_balance) }}
                        </p>
                    </div>

                    <div
                        v-if="account.is_credit && account.credit_limit"
                        class="grid gap-2"
                    >
                        <div class="flex items-center justify-between text-xs">
                            <span class="text-muted-foreground">
                                Limit terpakai
                            </span>
                            <span class="tabular-nums">
                                {{ account.credit_usage_percent ?? 0 }}%
                            </span>
                        </div>
                        <div class="neu-inset h-2 rounded-full">
                            <div
                                class="neu-progress h-2 rounded-full"
                                :class="
                                    creditTone(
                                        account.credit_usage_percent ?? 0,
                                    )
                                "
                                :style="{
                                    width: `${Math.min(account.credit_usage_percent ?? 0, 100)}%`,
                                }"
                            />
                        </div>
                        <p class="text-xs text-muted-foreground">
                            Tersisa
                            {{
                                formatCurrency(account.available_credit ?? '0')
                            }}
                            dari
                            {{ formatCurrency(account.credit_limit) }}
                        </p>
                    </div>

                    <dl
                        v-if="account.is_credit"
                        class="grid grid-cols-2 gap-2 text-xs"
                    >
                        <div>
                            <dt class="text-muted-foreground">
                                Tagihan berikutnya
                            </dt>
                            <dd>
                                {{ formatDate(account.next_billing_date) }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">Jatuh tempo</dt>
                            <dd>{{ formatDate(account.next_due_date) }}</dd>
                        </div>
                    </dl>

                    <div class="flex items-center justify-end gap-1">
                        <Button
                            variant="ghost"
                            size="sm"
                            aria-label="Ubah akun"
                            @click="startEdit(account)"
                        >
                            <Pencil class="size-4" />
                        </Button>

                        <Button
                            v-if="account.is_archived"
                            variant="ghost"
                            size="sm"
                            aria-label="Pulihkan akun"
                            @click="restoreAccount(account)"
                        >
                            <ArchiveRestore class="size-4" />
                        </Button>
                        <Button
                            v-else
                            variant="ghost"
                            size="sm"
                            class="text-muted-foreground"
                            aria-label="Arsipkan akun"
                            @click="archiveAccount(account)"
                        >
                            <Archive class="size-4" />
                        </Button>
                    </div>
                </CardContent>
            </Card>
        </div>

        <p class="text-xs text-muted-foreground">
            Akun yang diarsipkan tidak bisa dipilih saat mencatat transaksi,
            tetapi seluruh riwayatnya tetap tersimpan dan bisa dipulihkan kapan
            saja lewat tab Diarsipkan.
        </p>
    </div>
</template>
