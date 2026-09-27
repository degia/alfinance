<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import TransactionForm from '@/components/TransactionForm.vue';
import { update } from '@/routes/transactions';
import type {
    TransactionFormData,
    TransactionListItem,
    TransactionOptions,
} from '@/types';

defineOptions({
    layout: {
        title: 'Ubah Transaksi',
        description: 'Perbarui transaksi dan sesuaikan saldo akun',
    },
});

const props = defineProps<{
    transaction: TransactionListItem;
    options: TransactionOptions;
}>();

function submit(form: ReturnType<typeof useForm<TransactionFormData>>): void {
    form.put(update(props.transaction.id).url, {
        forceFormData: true,
    });
}
</script>

<template>
    <Head title="Ubah Transaksi" />

    <div class="flex flex-col gap-6">
        <p class="text-xs text-muted-foreground">
            Saldo akun otomatis disesuaikan sebesar selisih transaksi ini.
            Memindahkan akun atau mengganti tipe tidak akan meninggalkan saldo
            ganda — dampak lama dibalik lebih dulu.
        </p>

        <TransactionForm
            :options="props.options"
            :transaction="props.transaction"
            submit-label="Simpan perubahan"
            heading-title="Ubah transaksi"
            heading-description="Perubahan langsung memengaruhi saldo akun terkait."
            @submit="submit"
        />
    </div>
</template>
