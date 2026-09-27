<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import TransactionForm from '@/components/TransactionForm.vue';
import { store } from '@/routes/transactions';
import type { TransactionFormData, TransactionOptions } from '@/types';

defineOptions({
    layout: {
        title: 'Catat Transaksi',
        description: 'Tambah pemasukan, pengeluaran, atau transfer baru',
    },
});

const props = defineProps<{
    options: TransactionOptions;
}>();

function submit(form: ReturnType<typeof useForm<TransactionFormData>>): void {
    form.post(store().url, {
        forceFormData: true,
    });
}
</script>

<template>
    <Head title="Catat Transaksi" />

    <div class="flex flex-col gap-6">
        <TransactionForm
            :options="props.options"
            submit-label="Simpan transaksi"
            heading-title="Catat transaksi"
            heading-description="Nominal selalu positif. Pengeluaran mengurangi saldo, pemasukan menambahnya, transfer memindahkannya antar akun."
            @submit="submit"
        />
    </div>
</template>
