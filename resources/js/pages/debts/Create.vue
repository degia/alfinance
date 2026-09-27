<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import type { InertiaForm } from '@inertiajs/vue3';
import DebtForm from '@/components/DebtForm.vue';
import Heading from '@/components/Heading.vue';
import { store as storeDebt } from '@/routes/debts';
import type { DebtFormData, DebtFormOptions } from '@/types';

defineOptions({
    layout: {
        title: 'Catat utang',
        description: 'Tambahkan utang atau piutang baru',
    },
});

const props = defineProps<{
    options: DebtFormOptions;
}>();

function submit(form: InertiaForm<DebtFormData>): void {
    form.post(storeDebt().url, { preserveScroll: true });
}
</script>

<template>
    <Head title="Catat utang" />

    <div class="flex flex-col gap-6">
        <Heading
            title="Catat utang"
            description="Pokok, bunga, dan jadwal cicilan boleh diisi sebagian dulu. Sisa-tagihan selalu dihitung dari riwayat pembayaran."
        />

        <DebtForm
            :options="props.options"
            submit-label="Simpan utang"
            :on-submit="submit"
        />
    </div>
</template>
