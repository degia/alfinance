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

function hydrate(transaction: TransactionListItem): void {
    form.defaults({
        account_id: transaction.account?.id ?? '',
        transfer_to_account_id: transaction.transfer_to_account?.id ?? '',
        category_id: transaction.category?.id ?? '',
        type: transaction.type,
        amount: transaction.amount,
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
}

if (props.transaction) {
    hydrate(props.transaction);
}

/**
 * Mengganti tipe harus benar-benar mengosongkan field yang tidak relevan:
 * server memakai aturan `prohibited`/`required`, jadi field yang sekadar
 * disembunyikan akan ditolak.
 */
watch(isTransfer, (transfer) => {
    if (transfer) {
        form.category_id = '';
    } else {
        form.transfer_to_account_id = '';
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
    <Card class="rounded-2xl border-0 shadow-neu-flat">
        <CardContent class="p-6">
            <div class="mb-4 flex items-center justify-between gap-3">
                <Heading
                    :title="headingTitle"
                    :description="headingDescription"
                />

                <Badge variant="outline" class="shrink-0 text-[10px]">
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

                    <div v-else class="grid gap-2">
                        <Label for="transaction-category">Kategori</Label>
                        <Select v-model="form.category_id">
                            <SelectTrigger
                                id="transaction-category"
                                class="w-full shadow-neu-inset"
                                :aria-invalid="Boolean(form.errors.category_id)"
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
