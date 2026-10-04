<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Check, Paperclip, X } from '@lucide/vue';
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
import type {
    TransactionFormData,
    TransactionListItem,
    TransactionOptions,
    TransactionType,
} from '@/types';

const props = defineProps<{
    options: TransactionOptions;
    transaction?: TransactionListItem | null;
    submitLabel: string;
    headingTitle: string;
    headingDescription: string;
}>();

const emit = defineEmits<{
    (
        event: 'submit',
        form: ReturnType<typeof useForm<TransactionFormData>>,
    ): void;
}>();

const typeOptions: { value: TransactionType; label: string }[] = [
    { value: 'expense', label: 'Pengeluaran' },
    { value: 'income', label: 'Pemasukan' },
    { value: 'transfer', label: 'Transfer' },
];

/**
 * Tanggal hari ini dalam format `YYYY-MM-DD` tanpa zona waktu, supaya tidak
 * bergeser sehari seperti `new Date().toISOString()`.
 */
function today(): string {
    const now = new Date();
    const month = String(now.getMonth() + 1).padStart(2, '0');
    const day = String(now.getDate()).padStart(2, '0');

    return `${now.getFullYear()}-${month}-${day}`;
}

const emptyForm = (): TransactionFormData => ({
    account_id: '',
    transfer_to_account_id: '',
    category_id: '',
    type: 'expense',
    amount: '',
    admin_fee: '',
    admin_fee_category_id: '',
    debt_id: '',
    occurred_at: today(),
    note: '',
    tag_ids: [],
    attachment: null,
    remove_attachment: false,
});

const form = useForm<TransactionFormData>(emptyForm());
const fileInput = ref<HTMLInputElement | null>(null);
const selectedFile = ref<File | null>(null);

/**
 * Salinan lokal daftar lampiran, supaya bisa dikosongkan saat pengguna menekan
 * "Hapus lampiran" tanpa mengubah prop dari server.
 */
const localAttachments = ref<TransactionListItem['attachments']>([]);

const isTransfer = computed(() => form.type === 'transfer');

const isExpense = computed(() => form.type === 'expense');

/**
 * Utang yang harus dibayar. Didaftarkan server lewat `options.debts` dan sudah
 * disaring: hanya utang milik workspace aktif yang belum lunas, diurutkan
 * terlambat lalu jatuh tempo terdekat.
 */
const payableDebts = computed(() => props.options.debts);

const selectedDebt = computed(
    () =>
        payableDebts.value.find(
            (debt) => debt.id === form.debt_id,
        ) ?? null,
);

/**
 * Nominal yang terisi otomatis oleh select "Bayar utang", dipakai untuk
 * membedakan "nominal hasil pilihan user" dari "nominal yang kita isi sendiri":
 * memilih utang lain tidak boleh menimpa nominal yang sudah diketik user.
 */
const autoFilledAmount = ref<string | null>(null);

watch(
    () => form.debt_id,
    () => {
        const amountIsStillOurs =
            form.amount === '' || form.amount === autoFilledAmount.value;

        if (selectedDebt.value === null || !amountIsStillOurs) {
            return;
        }

        // Default-nya melunasi sisa utang, tapi nominal tetap bisa dikoreksi
        // untuk cicilan sebagian.
        autoFilledAmount.value = selectedDebt.value.remaining;
        form.amount = selectedDebt.value.remaining;
    },
);

/**
 * Kategori untuk baris potongan admin ditampilkan datar: kategori utama dulu
 * lalu sub-kategorinya, dengan sub-kategori diberi indent supaya tetap jelas
 * induknya. Select ini sengaja terpisah dari select kategori transaksi, karena
 * yang dikategorikan di sini adalah pengeluaran "Potongan admin", bukan transfer
 * yang dicatat dari akun sumber ke akun tujuan.
 */
const adminFeeCategories = computed(() =>
    props.options.categories.map((category) => ({
        id: category.id,
        name:
            category.parent_id === null
                ? category.name
                : `\u2014 ${category.name}`,
    })),
);

/**
 * Kategori utama dan sub-kategori dipisah supaya form bisa menampilkan dua
 * select bertingkat. Yang tersimpan ke `form.category_id` tetap kategori yang
 * benar-benar dipilih: sub-kategori kalau ada, kalau tidak kategori utamanya.
 */
const rootCategories = computed(() =>
    props.options.categories.filter((category) => category.parent_id === null),
);

const selectedRootId = ref<number | ''>('');
const selectedChildId = ref<number | ''>('');

const childCategories = computed(() => {
    if (selectedRootId.value === '') {
        return [];
    }

    return props.options.categories.filter(
        (category) => category.parent_id === selectedRootId.value,
    );
});

watch(selectedRootId, (rootId) => {
    // Sub-kategori yang tidak lagi milik kategori utama terpilih harus
    // dibuang; kalau tidak, form bisa mengirim pasangan induk/sub yang tidak
    // saling related. Sub-kategori yang masih cocok tetap dibiarkan supaya
    // `hydrate()` boleh mengisi kedua select sekaligus tanpa saling menimpa.
    const childStillBelongs = props.options.categories.some(
        (category) =>
            category.id === selectedChildId.value &&
            category.parent_id === rootId,
    );

    if (!childStillBelongs) {
        selectedChildId.value = '';
    }
});

watch([selectedRootId, selectedChildId], ([rootId, childId]) => {
    form.category_id = childId === '' ? rootId : childId;
});

function hydrate(transaction: TransactionListItem): void {
    const category = transaction.category;
    const usesChild = category !== null && category.parent_id !== null;

    form.defaults({
        account_id: transaction.account?.id ?? '',
        transfer_to_account_id: transaction.transfer_to_account?.id ?? '',
        category_id: category?.id ?? '',
        type: transaction.type,
        amount: transaction.amount,
        admin_fee: transaction.admin_fee ?? '',
        admin_fee_category_id: transaction.admin_fee_category_id ?? '',
        debt_id: transaction.debt_id ?? '',
        occurred_at: transaction.occurred_at,
        note: transaction.note ?? '',
        tag_ids: [...transaction.tag_ids],
        attachment: null,
        remove_attachment: false,
    });
    form.reset();
    form.clearErrors();
    selectedFile.value = null;
    localAttachments.value = [...transaction.attachments];
    selectedRootId.value = usesChild
        ? (category.parent_id ?? '')
        : (category?.id ?? '');
    selectedChildId.value = usesChild ? (category?.id ?? '') : '';
}

if (props.transaction) {
    hydrate(props.transaction);
}

/**
 * Mengganti tipe harus benar-benar mengosongkan field yang tidak relevan:
 * server memakai aturan `prohibited`/`required`, jadi field yang sekadar
 * disembunyikan akan ditolak.
 */
/**
 * "Bayar utang" hanya berlaku untuk expense; income dan transfer harus benar
 * benar mengosongkan field ini karena server menolaknya dengan `prohibited`.
 */
watch(isExpense, (expense) => {
    if (!expense) {
        form.debt_id = '';
    }
});

watch(isTransfer, (transfer) => {
    if (transfer) {
        selectedRootId.value = '';
        selectedChildId.value = '';
        form.category_id = '';
        form.debt_id = '';
    } else {
        form.transfer_to_account_id = '';
        form.admin_fee = '';
        form.admin_fee_category_id = '';
    }
});

function pickFile(event: Event): void {
    const input = event.target as HTMLInputElement;
    selectedFile.value = input.files?.[0] ?? null;
    form.attachment = selectedFile.value;
    form.remove_attachment = false;
}

function clearFile(): void {
    selectedFile.value = null;
    form.attachment = null;
    if (fileInput.value) {
        fileInput.value.value = '';
    }
}

function removeExistingAttachment(): void {
    form.remove_attachment = true;
    localAttachments.value = [];
    clearFile();
}

function toggleTag(id: number): void {
    const index = form.tag_ids.indexOf(id);

    if (index === -1) {
        form.tag_ids.push(id);
    } else {
        form.tag_ids.splice(index, 1);
    }
}

function submit(): void {
    emit('submit', form);
}
</script>

<template>
    <Card class="w-full max-w-4xl rounded-2xl border-0 shadow-neu-flat">
        <CardContent>
            <div class="mb-4 flex items-center justify-between gap-3">
                <Heading
                    :spaced="false"
                    :title="headingTitle"
                    :description="headingDescription"
                />

                <Badge variant="outline" class="shrink-0 text-2xs">
                    Maks 2 MB · jpg/png/webp/pdf
                </Badge>
            </div>

            <form class="flex flex-col gap-4" @submit.prevent="submit">
                <div class="grid gap-4 md:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="transaction-type">Tipe transaksi</Label>
                        <Select v-model="form.type">
                            <SelectTrigger
                                id="transaction-type"
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

                    <div class="grid gap-2">
                        <Label for="transaction-account">Akun</Label>
                        <Select v-model="form.account_id">
                            <SelectTrigger
                                id="transaction-account"
                                class="w-full shadow-neu-inset"
                                :aria-invalid="Boolean(form.errors.account_id)"
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
                        <Label for="transaction-target">Ke akun</Label>
                        <Select v-model="form.transfer_to_account_id">
                            <SelectTrigger
                                id="transaction-target"
                                class="w-full shadow-neu-inset"
                                :aria-invalid="
                                    Boolean(form.errors.transfer_to_account_id)
                                "
                            >
                                <SelectValue placeholder="Pilih akun tujuan" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="account in options.accounts.filter(
                                        (item) => item.id !== form.account_id,
                                    )"
                                    :key="account.id"
                                    :value="account.id"
                                >
                                    {{ account.name }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <p class="text-xs text-muted-foreground">
                            Akun tujuan harus berbeda dari akun sumber.
                        </p>
                        <InputError
                            :message="form.errors.transfer_to_account_id"
                        />
                    </div>

                    <div v-else class="flex flex-col gap-4">
                        <div class="grid gap-2">
                            <Label for="transaction-category">
                                Kategori utama
                            </Label>
                            <Select v-model="selectedRootId">
                                <SelectTrigger
                                    id="transaction-category"
                                    class="w-full shadow-neu-inset"
                                    :aria-invalid="
                                        Boolean(form.errors.category_id)
                                    "
                                >
                                    <SelectValue placeholder="Pilih kategori" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="category in rootCategories"
                                        :key="category.id"
                                        :value="category.id"
                                    >
                                        {{ category.name }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <InputError :message="form.errors.category_id" />
                        </div>

                        <div
                            v-if="childCategories.length > 0"
                            class="grid gap-2"
                        >
                            <Label for="transaction-subcategory">
                                Sub kategori
                            </Label>
                            <Select v-model="selectedChildId">
                                <SelectTrigger
                                    id="transaction-subcategory"
                                    class="w-full shadow-neu-inset"
                                >
                                    <SelectValue
                                        placeholder="Pilih sub kategori (opsional)"
                                    />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="category in childCategories"
                                        :key="category.id"
                                        :value="category.id"
                                    >
                                        {{ category.name }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <p class="text-xs text-muted-foreground">
                                Kosongkan untuk memakai kategori utama langsung.
                            </p>
                        </div>

                        <!--
                            Bayar utang: expense ini dicatat sebagai cicilan
                            utangnya, jadi sisa utang di menu Utang & Piutang
                            ikut turun. Hanya expense yang punya utang.
                        -->
                        <div
                            v-if="isExpense"
                            class="grid gap-2 border-t border-border pt-4"
                        >
                            <Label for="transaction-debt">Bayar utang</Label>
                            <Select v-model="form.debt_id">
                                <SelectTrigger
                                    id="transaction-debt"
                                    class="w-full shadow-neu-inset"
                                    :aria-invalid="Boolean(form.errors.debt_id)"
                                >
                                    <SelectValue placeholder="Bukan pembayaran utang" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="debt in payableDebts"
                                        :key="debt.id"
                                        :value="debt.id"
                                    >
                                        {{ debt.counterparty }} —
                                        {{ formatCurrency(debt.remaining) }}
                                        <span
                                            v-if="debt.due_date"
                                            class="text-xs text-muted-foreground"
                                        >
                                            (jatuh tempo
                                            {{ formatDate(debt.due_date) }})
                                        </span>
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <p class="text-xs text-muted-foreground">
                                <template v-if="selectedDebt">
                                    Sisa {{ selectedDebt.counterparty }}:
                                    {{ formatCurrency(selectedDebt.remaining) }}
                                    — {{ selectedDebt.status_label }}. Nominal
                                    terisi penuh; perkecil untuk cicilan
                                    sebagian.
                                </template>
                                <template v-else>
                                    Kosongkan kalau pengeluaran ini bukan
                                    pelunasan utang.
                                </template>
                            </p>
                            <InputError :message="form.errors.debt_id" />
                        </div>
                    </div>

                    <div class="grid gap-2">
                        <Label for="transaction-amount">Nominal</Label>
                        <Input
                            id="transaction-amount"
                            v-model="form.amount"
                            name="amount"
                            type="text"
                            inputmode="decimal"
                            required
                            placeholder="0"
                            class="shadow-neu-inset"
                            :aria-invalid="Boolean(form.errors.amount)"
                        />
                        <p class="text-xs text-muted-foreground">
                            Selalu positif — arahnya ditentukan tipe transaksi.
                        </p>
                        <InputError :message="form.errors.amount" />
                    </div>
                </div>

                <!--
                    Potongan admin hanya ada di transfer: nilainya dicatat sebagai
                    pengeluaran terpisah di akun sumber, bukan dipotong dari nominal
                    yang sampai ke akun tujuan. Kategorinya boleh dipilih sendiri;
                    kalau dikosongkan server memakai kategori otomatis "Biaya Admin".
                -->
                <div v-if="isTransfer" class="grid gap-4 md:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="transaction-admin-fee">
                            Potongan admin
                        </Label>
                        <Input
                            id="transaction-admin-fee"
                            v-model="form.admin_fee"
                            name="admin_fee"
                            type="text"
                            inputmode="decimal"
                            placeholder="0"
                            class="shadow-neu-inset"
                            :aria-invalid="Boolean(form.errors.admin_fee)"
                        />
                        <p class="text-xs text-muted-foreground">
                            Opsional — dicatat sebagai pengeluaran terpisah dari
                            akun sumber.
                        </p>
                        <InputError :message="form.errors.admin_fee" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="transaction-admin-fee-category">
                            Kategori biaya admin
                        </Label>
                        <Select v-model="form.admin_fee_category_id">
                            <SelectTrigger
                                id="transaction-admin-fee-category"
                                class="w-full shadow-neu-inset"
                                :aria-invalid="
                                    Boolean(form.errors.admin_fee_category_id)
                                "
                            >
                                <SelectValue placeholder="Biaya Admin (otomatis)" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="category in adminFeeCategories"
                                    :key="category.id"
                                    :value="category.id"
                                >
                                    {{ category.name }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <p class="text-xs text-muted-foreground">
                            Kosongkan untuk memakai kategori otomatis "Biaya
                            Admin".
                        </p>
                        <InputError
                            :message="form.errors.admin_fee_category_id"
                        />
                    </div>
                </div>

                <div class="grid gap-4 md:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="transaction-date">Tanggal</Label>
                        <Input
                            id="transaction-date"
                            v-model="form.occurred_at"
                            name="occurred_at"
                            type="date"
                            required
                            class="shadow-neu-inset"
                            :aria-invalid="Boolean(form.errors.occurred_at)"
                        />
                        <InputError :message="form.errors.occurred_at" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="transaction-note">Catatan</Label>
                        <Input
                            id="transaction-note"
                            v-model="form.note"
                            name="note"
                            maxlength="255"
                            placeholder="Opsional"
                            class="shadow-neu-inset"
                            :aria-invalid="Boolean(form.errors.note)"
                        />
                        <InputError :message="form.errors.note" />
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

                        <span
                            v-if="options.tags.length === 0"
                            class="text-xs text-muted-foreground"
                        >
                            Belum ada tag.
                        </span>
                    </div>
                    <InputError :message="form.errors.tag_ids" />
                </div>

                <div class="grid gap-2">
                    <Label for="transaction-attachment">
                        Lampiran bukti (opsional)
                    </Label>

                    <div
                        v-if="localAttachments.length > 0"
                        class="flex flex-wrap items-center gap-2"
                    >
                        <a
                            v-for="attachment in localAttachments"
                            :key="attachment.id"
                            :href="attachment.url"
                            class="inline-flex items-center gap-1.5 rounded-lg bg-muted px-2.5 py-1.5 text-xs hover:bg-muted/70"
                        >
                            <Paperclip class="size-3.5" />
                            {{ attachment.original_name }}
                            <span class="text-muted-foreground">
                                {{ attachment.human_size }}
                            </span>
                        </a>

                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            class="text-muted-foreground"
                            @click="removeExistingAttachment"
                        >
                            <X class="size-3.5" />
                            Hapus lampiran
                        </Button>
                    </div>

                    <div v-else class="flex items-center gap-2">
                        <input
                            id="transaction-attachment"
                            ref="fileInput"
                            type="file"
                            class="text-xs"
                            accept="image/jpeg,image/png,image/webp,application/pdf"
                            @change="pickFile"
                        />

                        <Button
                            v-if="selectedFile"
                            type="button"
                            variant="ghost"
                            size="sm"
                            class="text-muted-foreground"
                            @click="clearFile"
                        >
                            <X class="size-3.5" />
                            Batalkan pilihan
                        </Button>
                    </div>

                    <p
                        v-if="selectedFile"
                        class="text-xs text-muted-foreground"
                    >
                        {{ selectedFile.name }} — akan menggantikan lampiran
                        lama.
                    </p>
                    <InputError :message="form.errors.attachment" />
                </div>

                <div class="flex justify-end">
                    <Button
                        type="submit"
                        class="gap-2 sm:w-44"
                        :disabled="form.processing"
                    >
                        <Spinner v-if="form.processing" class="size-4" />
                        {{ submitLabel }}
                    </Button>
                </div>
            </form>
        </CardContent>
    </Card>
</template>
