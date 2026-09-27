<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Check, Pencil, Plus, Repeat, Trash2, X } from '@lucide/vue';
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
import { formatCurrency, formatDate } from '@/lib/format';
import { index, store, toggle, update } from '@/routes/recurring-rules';
import {
    confirm as confirmRoute,
    discard as discardRoute,
    index as transactionsIndex,
} from '@/routes/transactions';
import type {
    PendingTransaction,
    RecurringRuleFormData,
    RecurringRuleListItem,
    RecurringRuleOptions,
    TransactionType,
} from '@/types';
import { useWorkspace } from '@/composables/useWorkspace';

defineOptions({
    layout: {
        title: 'Transaksi Berulang',
        description: 'Aturan otomatis untuk tagihan, gaji, dan langganan',
    },
});

const props = defineProps<{
    rules: RecurringRuleListItem[];
    pending: PendingTransaction[];
    filters: { inactive: boolean };
    options: RecurringRuleOptions;
}>();

const { workspace } = useWorkspace();
const canEdit = computed(() => workspace.value?.role !== 'viewer');

const typeOptions: { value: TransactionType; label: string }[] = [
    { value: 'expense', label: 'Pengeluaran' },
    { value: 'income', label: 'Pemasukan' },
    { value: 'transfer', label: 'Transfer' },
];

function tomorrow(): string {
    const now = new Date();
    now.setDate(now.getDate() + 1);
    const month = String(now.getMonth() + 1).padStart(2, '0');
    const day = String(now.getDate()).padStart(2, '0');

    return `${now.getFullYear()}-${month}-${day}`;
}

const emptyForm = (): RecurringRuleFormData => ({
    account_id: '',
    transfer_to_account_id: '',
    category_id: '',
    type: 'expense',
    amount: '',
    note: '',
    tag_ids: [],
    frequency: 'monthly',
    next_run_at: tomorrow(),
    end_date: '',
    requires_confirmation: true,
    is_active: true,
});

const form = useForm<RecurringRuleFormData>(emptyForm());
const editingId = ref<number | null>(null);
const isEditing = computed(() => editingId.value !== null);
const isTransfer = computed(() => form.type === 'transfer');

watch(isTransfer, (transfer) => {
    if (transfer) {
        form.category_id = '';
    } else {
        form.transfer_to_account_id = '';
    }
});

function startCreate(): void {
    editingId.value = null;
    form.defaults(emptyForm());
    form.reset();
    form.clearErrors();
}

function startEdit(rule: RecurringRuleListItem): void {
    editingId.value = rule.id;
    form.defaults({
        account_id: rule.account?.id ?? '',
        transfer_to_account_id: rule.transfer_to_account?.id ?? '',
        category_id: rule.category?.id ?? '',
        type: rule.type,
        amount: rule.amount,
        note: rule.note ?? '',
        tag_ids: [...rule.tag_ids],
        frequency: rule.frequency,
        next_run_at: rule.next_run_at,
        end_date: rule.end_date ?? '',
        requires_confirmation: rule.requires_confirmation,
        is_active: rule.is_active,
    });
    form.reset();
    form.clearErrors();
}

function submit(): void {
    if (editingId.value === null) {
        form.post(store().url, {
            preserveScroll: true,
            onSuccess: startCreate,
        });

        return;
    }

    form.put(update(editingId.value).url, {
        preserveScroll: true,
        onSuccess: startCreate,
    });
}

function toggleRule(rule: RecurringRuleListItem): void {
    router.post(toggle(rule.id).url, { preserveScroll: true });
}

function setFilter(inactive: boolean): void {
    router.get(index.url({ query: { inactive: inactive ? '1' : undefined } }), {
        preserveScroll: true,
        replace: true,
    });
}

function confirmPending(transaction: PendingTransaction): void {
    router.post(confirmRoute(transaction.id).url, { preserveScroll: true });
}

function discardPending(transaction: PendingTransaction): void {
    if (window.confirm('Buang instance ini?')) {
        router.post(discardRoute(transaction.id).url, { preserveScroll: true });
    }
}

function toggleTag(id: number): void {
    const index = form.tag_ids.indexOf(id);

    if (index === -1) {
        form.tag_ids.push(id);
    } else {
        form.tag_ids.splice(index, 1);
    }
}
</script>

<template>
    <Head title="Transaksi Berulang" />

    <div class="flex flex-col gap-6">
        <Heading
            title="Transaksi berulang"
            description="Aturan yang otomatis membuat transaksi baru. Instance baru bisa langsung memengaruhi saldo, atau menunggu konfirmasi dulu."
        />

        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <Button
                    :variant="filters.inactive ? 'ghost' : 'secondary'"
                    :class="filters.inactive ? '' : 'shadow-neu-pressed-sm'"
                    @click="setFilter(false)"
                >
                    Aktif
                </Button>
                <Button
                    :variant="filters.inactive ? 'secondary' : 'ghost'"
                    :class="filters.inactive ? 'shadow-neu-pressed-sm' : ''"
                    @click="setFilter(true)"
                >
                    Semua
                </Button>
            </div>

            <Button :as-child="true" variant="ghost">
                <Link :href="transactionsIndex().url">
                    <Repeat class="size-4" />
                    Lihat transaksi
                </Link>
            </Button>
        </div>

        <Card
            v-if="pending.length > 0"
            class="rounded-2xl border-0 shadow-neu-flat"
        >
            <CardContent class="p-6">
                <h3 class="mb-3 text-sm font-semibold">
                    Menunggu konfirmasi ({{ pending.length }})
                </h3>

                <p class="mb-4 text-xs text-muted-foreground">
                    Instance di bawah ini belum pernah menyentuh saldo.
                    Konfirmasi baru menulisnya ke saldo akun.
                </p>

                <div class="grid gap-3">
                    <div
                        v-for="transaction in pending"
                        :key="transaction.id"
                        class="flex flex-wrap items-center justify-between gap-3 rounded-xl bg-surface px-4 py-3"
                    >
                        <div class="min-w-0">
                            <p class="text-sm">
                                {{ transaction.type_label }} ·
                                {{
                                    transaction.account?.name ?? 'Akun terhapus'
                                }}
                                <span class="tabular-nums">
                                    {{ formatCurrency(transaction.amount) }}
                                </span>
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {{ formatDate(transaction.occurred_at) }}
                                <span v-if="transaction.category">
                                    · {{ transaction.category.name }}
                                </span>
                                <span v-if="transaction.note">
                                    · {{ transaction.note }}
                                </span>
                            </p>
                        </div>

                        <div v-if="canEdit" class="flex items-center gap-1">
                            <Button
                                variant="ghost"
                                size="sm"
                                @click="confirmPending(transaction)"
                            >
                                <Check class="size-4" />
                                Konfirmasi
                            </Button>
                            <Button
                                variant="ghost"
                                size="sm"
                                class="text-muted-foreground"
                                @click="discardPending(transaction)"
                            >
                                <X class="size-4" />
                                Buang
                            </Button>
                        </div>
                    </div>
                </div>
            </CardContent>
        </Card>

        <Card v-if="canEdit" class="rounded-2xl border-0 shadow-neu-flat">
            <CardContent class="p-6">
                <div class="mb-4 flex items-center justify-between">
                    <h3 class="text-sm font-semibold">
                        {{ isEditing ? 'Ubah aturan' : 'Tambah aturan' }}
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
                            <Label for="rule-type">Tipe</Label>
                            <Select v-model="form.type">
                                <SelectTrigger
                                    id="rule-type"
                                    class="w-full shadow-neu-inset"
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

                        <div class="grid gap-2">
                            <Label for="rule-account">Akun</Label>
                            <Select v-model="form.account_id">
                                <SelectTrigger
                                    id="rule-account"
                                    class="w-full shadow-neu-inset"
                                >
                                    <SelectValue placeholder="Pilih akun" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="account in options.accounts"
                                        :key="account.id"
                                        :value="account.id"
                                    >
                                        {{ account.name }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <InputError :message="form.errors.account_id" />
                        </div>
                    </div>

                    <div class="grid gap-4 md:grid-cols-2">
                        <div v-if="isTransfer" class="grid gap-2">
                            <Label for="rule-target">Ke akun</Label>
                            <Select v-model="form.transfer_to_account_id">
                                <SelectTrigger
                                    id="rule-target"
                                    class="w-full shadow-neu-inset"
                                >
                                    <SelectValue
                                        placeholder="Pilih akun tujuan"
                                    />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="account in options.accounts.filter(
                                            (item) =>
                                                item.id !== form.account_id,
                                        )"
                                        :key="account.id"
                                        :value="account.id"
                                    >
                                        {{ account.name }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <InputError
                                :message="form.errors.transfer_to_account_id"
                            />
                        </div>

                        <div v-else class="grid gap-2">
                            <Label for="rule-category">Kategori</Label>
                            <Select v-model="form.category_id">
                                <SelectTrigger
                                    id="rule-category"
                                    class="w-full shadow-neu-inset"
                                >
                                    <SelectValue placeholder="Pilih kategori" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="category in options.categories"
                                        :key="category.id"
                                        :value="category.id"
                                    >
                                        {{ category.name }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <InputError :message="form.errors.category_id" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="rule-amount">Nominal</Label>
                            <Input
                                id="rule-amount"
                                v-model="form.amount"
                                inputmode="decimal"
                                required
                                placeholder="0"
                                class="shadow-neu-inset"
                                :aria-invalid="Boolean(form.errors.amount)"
                            />
                            <InputError :message="form.errors.amount" />
                        </div>
                    </div>

                    <div class="grid gap-4 md:grid-cols-3">
                        <div class="grid gap-2">
                            <Label for="rule-frequency">Frekuensi</Label>
                            <Select v-model="form.frequency">
                                <SelectTrigger
                                    id="rule-frequency"
                                    class="w-full shadow-neu-inset"
                                >
                                    <SelectValue placeholder="Pilih" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="option in options.frequencies"
                                        :key="option.value"
                                        :value="option.value"
                                    >
                                        {{ option.label }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <InputError :message="form.errors.frequency" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="rule-next">Transaksi berikutnya</Label>
                            <Input
                                id="rule-next"
                                v-model="form.next_run_at"
                                type="date"
                                required
                                class="shadow-neu-inset"
                                :aria-invalid="Boolean(form.errors.next_run_at)"
                            />
                            <InputError :message="form.errors.next_run_at" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="rule-end">Sampai tanggal</Label>
                            <Input
                                id="rule-end"
                                v-model="form.end_date"
                                type="date"
                                class="shadow-neu-inset"
                                :aria-invalid="Boolean(form.errors.end_date)"
                            />
                            <p class="text-xs text-muted-foreground">
                                Kosongkan untuk berjalan terus.
                            </p>
                            <InputError :message="form.errors.end_date" />
                        </div>
                    </div>

                    <div class="grid gap-2">
                        <Label>Tag</Label>
                        <div class="flex flex-wrap items-center gap-2">
                            <Button
                                v-for="tag in options.tags"
                                :key="tag.id"
                                type="button"
                                size="sm"
                                :variant="
                                    form.tag_ids.includes(tag.id)
                                        ? 'secondary'
                                        : 'ghost'
                                "
                                :class="
                                    form.tag_ids.includes(tag.id)
                                        ? 'shadow-neu-pressed-sm'
                                        : ''
                                "
                                @click="toggleTag(tag.id)"
                            >
                                <Check
                                    v-if="form.tag_ids.includes(tag.id)"
                                    class="size-3.5"
                                />
                                {{ tag.name }}
                            </Button>
                        </div>
                    </div>

                    <div class="grid gap-4 md:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="rule-note">Catatan</Label>
                            <Input
                                id="rule-note"
                                v-model="form.note"
                                maxlength="255"
                                placeholder="Opsional"
                                class="shadow-neu-inset"
                            />
                            <InputError :message="form.errors.note" />
                        </div>

                        <div class="grid gap-2">
                            <Label>Konfirmasi</Label>
                            <div class="flex items-center gap-2">
                                <input
                                    id="rule-confirmation"
                                    v-model="form.requires_confirmation"
                                    type="checkbox"
                                />
                                <label for="rule-confirmation" class="text-sm">
                                    Tunggu konfirmasi sebelum menyentuh saldo
                                </label>
                            </div>
                            <p class="text-xs text-muted-foreground">
                                Nonaktifkan kalau amount-nya sudah pasti dan
                                tidak perlu ditinjau.
                            </p>
                        </div>
                    </div>

                    <div class="flex justify-end">
                        <Button
                            type="submit"
                            class="gap-2 sm:w-44"
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
            v-if="rules.length === 0"
            class="rounded-2xl border border-dashed p-10 text-center text-sm text-muted-foreground"
        >
            Belum ada aturan transaksi berulang.
        </div>

        <div class="grid gap-4 md:grid-cols-2">
            <Card
                v-for="rule in rules"
                :key="rule.id"
                class="rounded-2xl border-0 shadow-neu-flat"
            >
                <CardContent class="flex flex-col gap-3 p-5">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="truncate font-medium">
                                {{ rule.account?.name ?? 'Akun terhapus' }}
                                <span
                                    v-if="rule.transfer_to_account"
                                    class="text-muted-foreground"
                                >
                                    → {{ rule.transfer_to_account.name }}
                                </span>
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {{ rule.type_label }}
                                <span v-if="rule.category">
                                    · {{ rule.category.name }}
                                </span>
                            </p>
                        </div>

                        <Badge
                            :variant="rule.is_active ? 'secondary' : 'outline'"
                            class="shrink-0 text-[10px]"
                        >
                            {{ rule.is_active ? 'Aktif' : 'Nonaktif' }}
                        </Badge>
                    </div>

                    <div>
                        <p class="text-lg font-semibold tabular-nums">
                            {{ formatCurrency(rule.amount) }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ rule.frequency_label }} · berikutnya
                            {{ formatDate(rule.next_run_at) }}
                            <span v-if="rule.end_date">
                                · sampai {{ formatDate(rule.end_date) }}
                            </span>
                        </p>
                    </div>

                    <div class="flex flex-wrap items-center gap-1">
                        <Badge
                            v-if="rule.requires_confirmation"
                            variant="outline"
                            class="text-[10px]"
                        >
                            Butuh konfirmasi
                        </Badge>
                        <Badge
                            v-for="tag in rule.tags"
                            :key="tag.id"
                            variant="secondary"
                            class="text-[10px]"
                        >
                            {{ tag.name }}
                        </Badge>
                    </div>

                    <div
                        v-if="canEdit"
                        class="flex items-center justify-end gap-1"
                    >
                        <Button
                            variant="ghost"
                            size="sm"
                            @click="startEdit(rule)"
                        >
                            <Pencil class="size-4" />
                        </Button>
                        <Button
                            variant="ghost"
                            size="sm"
                            class="text-muted-foreground"
                            @click="toggleRule(rule)"
                        >
                            <Trash2 v-if="rule.is_active" class="size-4" />
                            <Plus v-else class="size-4" />
                        </Button>
                    </div>
                </CardContent>
            </Card>
        </div>
    </div>
</template>
