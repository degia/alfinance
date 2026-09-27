<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowLeftRight,
    Check,
    Filter,
    Paperclip,
    Pencil,
    Plus,
    RefreshCw,
    Repeat,
    Trash2,
    TrendingDown,
    TrendingUp,
    X,
} from '@lucide/vue';
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
import { formatCurrency, formatDate, formatSignedCurrency } from '@/lib/format';
import {
    confirm as confirmRoute,
    create,
    destroy,
    discard as discardRoute,
    edit,
    index,
} from '@/routes/transactions';
import { index as recurringIndex } from '@/routes/recurring-rules';
import type {
    TransactionFilters,
    TransactionListItem,
    TransactionOptions,
    TransactionPagination,
    TransactionStatus,
    TransactionSummary,
    TransactionType,
} from '@/types';
import { useWorkspace } from '@/composables/useWorkspace';

defineOptions({
    layout: {
        title: 'Transaksi',
        description: 'Catatan arus kas, transfer, dan transaksi berulang',
    },
});

const props = defineProps<{
    transactions: TransactionListItem[];
    pagination: TransactionPagination;
    filters: TransactionFilters;
    summary: TransactionSummary;
    options: TransactionOptions;
}>();

const { workspace } = useWorkspace();
const canEdit = computed(() => workspace.value?.role !== 'viewer');

const showFilters = ref(
    props.filters.from !== null ||
        props.filters.to !== null ||
        props.filters.account_id !== null ||
        props.filters.category_id !== null ||
        props.filters.tag_id !== null ||
        props.filters.type !== null ||
        props.filters.status !== null ||
        props.filters.amount_min !== null ||
        props.filters.amount_max !== null ||
        props.filters.search !== null,
);

const NONE = '__none__';

const typeOptions: { value: TransactionType; label: string }[] = [
    { value: 'expense', label: 'Pengeluaran' },
    { value: 'income', label: 'Pemasukan' },
    { value: 'transfer', label: 'Transfer' },
];

const statusOptions: { value: TransactionStatus; label: string }[] = [
    { value: 'posted', label: 'Sudah masuk saldo' },
    { value: 'pending', label: 'Menunggu konfirmasi' },
];

const form = ref({
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
    account_id:
        props.filters.account_id === null
            ? NONE
            : String(props.filters.account_id),
    category_id:
        props.filters.category_id === null
            ? NONE
            : String(props.filters.category_id),
    tag_id: props.filters.tag_id === null ? NONE : String(props.filters.tag_id),
    type: props.filters.type ?? NONE,
    status: props.filters.status ?? NONE,
    amount_min: props.filters.amount_min ?? '',
    amount_max: props.filters.amount_max ?? '',
    search: props.filters.search ?? '',
});

// Halaman_idx dipoles server setiap kali filter berubah.
watch(
    () => props.filters,
    (next) => {
        form.value = {
            from: next.from ?? '',
            to: next.to ?? '',
            account_id:
                next.account_id === null ? NONE : String(next.account_id),
            category_id:
                next.category_id === null ? NONE : String(next.category_id),
            tag_id: next.tag_id === null ? NONE : String(next.tag_id),
            type: next.type ?? NONE,
            status: next.status ?? NONE,
            amount_min: next.amount_min ?? '',
            amount_max: next.amount_max ?? '',
            search: next.search ?? '',
        };
    },
);

const isFiltered = computed(
    () =>
        Object.values(form.value).some(
            (value) => value !== '' && value !== NONE,
        ) || props.pagination.current_page > 1,
);

function applyFilters(page = 1): void {
    const value = form.value;

    router.get(
        index.url({
            query: {
                page,
                from: value.from || undefined,
                to: value.to || undefined,
                account_id:
                    value.account_id === NONE ? undefined : value.account_id,
                category_id:
                    value.category_id === NONE ? undefined : value.category_id,
                tag_id: value.tag_id === NONE ? undefined : value.tag_id,
                type: value.type === NONE ? undefined : value.type,
                status: value.status === NONE ? undefined : value.status,
                amount_min: value.amount_min || undefined,
                amount_max: value.amount_max || undefined,
                search: value.search || undefined,
            },
        }),
        { preserveScroll: true, replace: true },
    );
}

function resetFilters(): void {
    form.value = {
        from: '',
        to: '',
        account_id: NONE,
        category_id: NONE,
        tag_id: NONE,
        type: NONE,
        status: NONE,
        amount_min: '',
        amount_max: '',
        search: '',
    };

    router.get(index.url(), { preserveScroll: true, replace: true });
}

function remove(transaction: TransactionListItem): void {
    if (window.confirm('Hapus transaksi ini? Saldo akun akan dikembalikan.')) {
        router.delete(destroy(transaction.id).url, { preserveScroll: true });
    }
}

function confirmPending(transaction: TransactionListItem): void {
    router.post(confirmRoute(transaction.id).url, { preserveScroll: true });
}

function discardPending(transaction: TransactionListItem): void {
    if (
        window.confirm(
            'Buang instance ini? Saldo tidak berubah karena belum pernah tersentuh.',
        )
    ) {
        router.post(discardRoute(transaction.id).url, { preserveScroll: true });
    }
}

function goToPage(url: string | null): void {
    if (url) {
        router.get(url, { preserveScroll: true });
    }
}

function typeIcon(type: TransactionType) {
    if (type === 'income') {
        return TrendingUp;
    }

    if (type === 'transfer') {
        return ArrowLeftRight;
    }

    return TrendingDown;
}
</script>

<template>
    <Head title="Transaksi" />

    <div class="flex flex-col gap-6">
        <Heading
            title="Transaksi"
            description="Setiap baris langsung memengaruhi saldo akunnya. Transfer memindahkan uang antar akun tanpa dianggap pendapatan."
        />

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <Card class="rounded-2xl border-0 shadow-neu-flat">
                <CardContent class="p-5">
                    <p class="text-xs text-muted-foreground">Pemasukan</p>
                    <p
                        class="mt-1 text-lg font-semibold text-income tabular-nums"
                    >
                        {{ formatCurrency(summary.income) }}
                    </p>
                </CardContent>
            </Card>

            <Card class="rounded-2xl border-0 shadow-neu-flat">
                <CardContent class="p-5">
                    <p class="text-xs text-muted-foreground">Pengeluaran</p>
                    <p
                        class="mt-1 text-lg font-semibold text-expense tabular-nums"
                    >
                        {{ formatCurrency(summary.expense) }}
                    </p>
                </CardContent>
            </Card>

            <Card class="rounded-2xl border-0 shadow-neu-flat">
                <CardContent class="p-5">
                    <p class="text-xs text-muted-foreground">
                        Bersih (pemasukan − pengeluaran)
                    </p>
                    <p
                        class="mt-1 text-lg font-semibold tabular-nums"
                        :class="
                            Number(summary.net) < 0
                                ? 'text-expense'
                                : 'text-foreground'
                        "
                    >
                        {{ formatCurrency(summary.net) }}
                    </p>
                </CardContent>
            </Card>

            <Card class="rounded-2xl border-0 shadow-neu-flat">
                <CardContent class="p-5">
                    <p class="text-xs text-muted-foreground">
                        Menunggu konfirmasi
                    </p>
                    <p class="mt-1 text-lg font-semibold tabular-nums">
                        {{ summary.pending_count }}
                    </p>
                </CardContent>
            </Card>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <Button
                    :variant="showFilters ? 'secondary' : 'ghost'"
                    :class="showFilters ? 'shadow-neu-pressed-sm' : ''"
                    @click="showFilters = !showFilters"
                >
                    <Filter class="size-4" />
                    Filter
                </Button>

                <Button v-if="isFiltered" variant="ghost" @click="resetFilters">
                    <X class="size-4" />
                    Reset
                </Button>
            </div>

            <div class="flex items-center gap-2">
                <Button :as-child="true" variant="ghost">
                    <Link :href="recurringIndex().url">
                        <Repeat class="size-4" />
                        Transaksi berulang
                    </Link>
                </Button>

                <Button :as-child="true">
                    <Link :href="create().url">
                        <Plus class="size-4" />
                        Catat transaksi
                    </Link>
                </Button>
            </div>
        </div>

        <Card v-if="showFilters" class="rounded-2xl border-0 shadow-neu-flat">
            <CardContent class="grid gap-4 p-6 md:grid-cols-3">
                <div class="grid gap-2">
                    <Label for="filter-search">Cari catatan</Label>
                    <Input
                        id="filter-search"
                        v-model="form.search"
                        placeholder="mis. komplek"
                        class="shadow-neu-inset"
                        @keyup.enter="applyFilters()"
                    />
                </div>

                <div class="grid gap-2">
                    <Label for="filter-from">Dari tanggal</Label>
                    <Input
                        id="filter-from"
                        v-model="form.from"
                        type="date"
                        class="shadow-neu-inset"
                    />
                </div>

                <div class="grid gap-2">
                    <Label for="filter-to">Sampai tanggal</Label>
                    <Input
                        id="filter-to"
                        v-model="form.to"
                        type="date"
                        class="shadow-neu-inset"
                    />
                </div>

                <div class="grid gap-2">
                    <Label>Akun</Label>
                    <Select v-model="form.account_id">
                        <SelectTrigger class="w-full shadow-neu-inset">
                            <SelectValue placeholder="Semua akun" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="NONE">Semua akun</SelectItem>
                            <SelectItem
                                v-for="account in options.accounts"
                                :key="account.id"
                                :value="String(account.id)"
                            >
                                {{ account.name }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <div class="grid gap-2">
                    <Label>Kategori</Label>
                    <Select v-model="form.category_id">
                        <SelectTrigger class="w-full shadow-neu-inset">
                            <SelectValue placeholder="Semua kategori" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="NONE">
                                Semua kategori
                            </SelectItem>
                            <SelectItem
                                v-for="category in options.categories"
                                :key="category.id"
                                :value="String(category.id)"
                            >
                                {{ category.name }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <div class="grid gap-2">
                    <Label>Tag</Label>
                    <Select v-model="form.tag_id">
                        <SelectTrigger class="w-full shadow-neu-inset">
                            <SelectValue placeholder="Semua tag" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="NONE">Semua tag</SelectItem>
                            <SelectItem
                                v-for="tag in options.tags"
                                :key="tag.id"
                                :value="String(tag.id)"
                            >
                                {{ tag.name }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <div class="grid gap-2">
                    <Label>Tipe</Label>
                    <Select v-model="form.type">
                        <SelectTrigger class="w-full shadow-neu-inset">
                            <SelectValue placeholder="Semua tipe" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="NONE">Semua tipe</SelectItem>
                            <SelectItem
                                v-for="option in typeOptions"
                                :key="option.value"
                                :value="option.value"
                            >
                                {{ option.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <div class="grid gap-2">
                    <Label>Status</Label>
                    <Select v-model="form.status">
                        <SelectTrigger class="w-full shadow-neu-inset">
                            <SelectValue placeholder="Semua status" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="NONE">Semua status</SelectItem>
                            <SelectItem
                                v-for="option in statusOptions"
                                :key="option.value"
                                :value="option.value"
                            >
                                {{ option.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <div class="grid gap-2">
                    <Label for="filter-min">Nominal minimum</Label>
                    <Input
                        id="filter-min"
                        v-model="form.amount_min"
                        inputmode="decimal"
                        placeholder="0"
                        class="shadow-neu-inset"
                    />
                </div>

                <div class="grid gap-2">
                    <Label for="filter-max">Nominal maksimum</Label>
                    <Input
                        id="filter-max"
                        v-model="form.amount_max"
                        inputmode="decimal"
                        placeholder="Tak terbatas"
                        class="shadow-neu-inset"
                    />
                </div>

                <div class="flex items-end justify-end gap-2 md:col-span-3">
                    <Button variant="ghost" @click="resetFilters">Reset</Button>
                    <Button @click="applyFilters()">Terapkan</Button>
                </div>
            </CardContent>
        </Card>

        <div
            v-if="transactions.length === 0"
            class="rounded-2xl border border-dashed p-10 text-center text-sm text-muted-foreground"
        >
            {{
                isFiltered
                    ? 'Tidak ada transaksi yang cocok dengan filter ini.'
                    : 'Belum ada transaksi. Catat pemasukan atau pengeluaran pertamamu.'
            }}
        </div>

        <Card class="rounded-2xl border-0 shadow-neu-flat">
            <CardContent class="p-0">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-3xl text-sm">
                        <thead>
                            <tr
                                class="border-b text-left text-xs text-muted-foreground"
                            >
                                <th class="px-4 py-3 font-medium">Tanggal</th>
                                <th class="px-4 py-3 font-medium">Akun</th>
                                <th class="px-4 py-3 font-medium">Kategori</th>
                                <th class="px-4 py-3 font-medium">Catatan</th>
                                <th class="px-4 py-3 text-right font-medium">
                                    Nominal
                                </th>
                                <th
                                    v-if="canEdit"
                                    class="px-4 py-3 text-right font-medium"
                                >
                                    Aksi
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="transaction in transactions"
                                :key="transaction.id"
                                class="border-b last:border-0"
                            >
                                <td
                                    class="px-4 py-3 whitespace-nowrap tabular-nums"
                                >
                                    {{ formatDate(transaction.occurred_at) }}
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-2">
                                        <component
                                            :is="typeIcon(transaction.type)"
                                            class="size-4 shrink-0"
                                            :class="
                                                transaction.type === 'income'
                                                    ? 'text-income'
                                                    : transaction.type ===
                                                        'transfer'
                                                      ? 'text-primary'
                                                      : 'text-expense'
                                            "
                                        />
                                        <div class="min-w-0">
                                            <p class="truncate">
                                                {{ transaction.account?.name }}
                                            </p>
                                            <p
                                                v-if="
                                                    transaction.transfer_to_account
                                                "
                                                class="truncate text-xs text-muted-foreground"
                                            >
                                                →
                                                {{
                                                    transaction
                                                        .transfer_to_account
                                                        .name
                                                }}
                                            </p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    <p v-if="transaction.category">
                                        {{ transaction.category.name }}
                                    </p>
                                    <p v-else class="text-muted-foreground">
                                        —
                                    </p>
                                </td>
                                <td class="px-4 py-3">
                                    <p class="line-clamp-1">
                                        {{ transaction.note ?? '—' }}
                                    </p>
                                    <div
                                        class="mt-1 flex flex-wrap items-center gap-1"
                                    >
                                        <Badge
                                            v-for="tag in transaction.tags"
                                            :key="tag.id"
                                            variant="secondary"
                                            class="text-[10px]"
                                        >
                                            {{ tag.name }}
                                        </Badge>
                                        <a
                                            v-for="attachment in transaction.attachments"
                                            :key="attachment.id"
                                            :href="attachment.url"
                                            class="inline-flex items-center gap-1 rounded bg-muted px-1.5 py-0.5 text-[10px] text-muted-foreground hover:text-foreground"
                                        >
                                            <Paperclip class="size-3" />
                                            {{ attachment.original_name }}
                                        </a>
                                    </div>
                                </td>
                                <td
                                    class="px-4 py-3 text-right font-medium whitespace-nowrap tabular-nums"
                                >
                                    <span
                                        :class="
                                            transaction.type === 'income'
                                                ? 'text-income'
                                                : 'text-expense'
                                        "
                                    >
                                        {{
                                            formatSignedCurrency(
                                                transaction.signed_amount,
                                            )
                                        }}
                                    </span>
                                    <div
                                        v-if="transaction.is_pending"
                                        class="mt-1 flex items-center justify-end gap-1"
                                    >
                                        <Badge
                                            variant="outline"
                                            class="text-[10px]"
                                        >
                                            Menunggu
                                        </Badge>
                                    </div>
                                </td>
                                <td
                                    v-if="canEdit"
                                    class="px-4 py-3 text-right whitespace-nowrap"
                                >
                                    <div
                                        class="flex items-center justify-end gap-1"
                                    >
                                        <template v-if="transaction.is_pending">
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                aria-label="Konfirmasi transaksi"
                                                @click="
                                                    confirmPending(transaction)
                                                "
                                            >
                                                <Check class="size-4" />
                                            </Button>
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                class="text-muted-foreground"
                                                aria-label="Buang instance"
                                                @click="
                                                    discardPending(transaction)
                                                "
                                            >
                                                <X class="size-4" />
                                            </Button>
                                        </template>

                                        <template v-else>
                                            <Button
                                                :as-child="true"
                                                variant="ghost"
                                                size="sm"
                                                aria-label="Ubah transaksi"
                                            >
                                                <Link
                                                    :href="
                                                        edit(transaction.id).url
                                                    "
                                                >
                                                    <Pencil class="size-4" />
                                                </Link>
                                            </Button>
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                class="text-muted-foreground"
                                                aria-label="Hapus transaksi"
                                                @click="remove(transaction)"
                                            >
                                                <Trash2 class="size-4" />
                                            </Button>
                                        </template>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </CardContent>
        </Card>

        <div
            v-if="pagination.total > 0"
            class="flex items-center justify-between gap-3 text-xs text-muted-foreground"
        >
            <p>
                Menampilkan {{ pagination.from }}–{{ pagination.to }} dari
                {{ pagination.total }} transaksi pada filter ini
            </p>

            <div class="flex items-center gap-2">
                <Button
                    variant="ghost"
                    size="sm"
                    :disabled="pagination.current_page <= 1"
                    @click="goToPage(pagination.prev_page_url)"
                >
                    <RefreshCw class="size-3.5" />
                    Sebelumnya
                </Button>
                <span class="tabular-nums">
                    Halaman {{ pagination.current_page }} /
                    {{ pagination.last_page }}
                </span>
                <Button
                    variant="ghost"
                    size="sm"
                    :disabled="!pagination.next_page_url"
                    @click="goToPage(pagination.next_page_url)"
                >
                    Berikutnya
                    <RefreshCw class="size-3.5" />
                </Button>
            </div>
        </div>

        <div
            v-if="canEdit === false"
            class="rounded-2xl border border-dashed p-6 text-center text-xs text-muted-foreground"
        >
            Kamu punya akses baca saja di workspace ini, jadi transaksi tidak
            bisa ditambah, diubah, atau dihapus.
        </div>
    </div>
</template>
