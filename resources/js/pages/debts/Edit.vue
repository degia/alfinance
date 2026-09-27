<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import type { InertiaForm } from '@inertiajs/vue3';
import DebtForm from '@/components/DebtForm.vue';
import Heading from '@/components/Heading.vue';
import { update as updateDebt } from '@/routes/debts';
import type { Debt, DebtFormData, DebtFormOptions } from '@/types';

defineOptions({
    layout: {
        title: 'Ubah utang',
        description: 'Perbarui detail utang atau piutang',
    },
});

const props = defineProps<{
    debt: Debt;
    options: DebtFormOptions;
}>();

function submit(form: InertiaForm<DebtFormData>): void {
    form.put(updateDebt(props.debt.id).url, { preserveScroll: true });
}
</script>

<template>
    <Head title="Ubah utang" />

    <div class="flex flex-col gap-6">
        <Heading
            title="Ubah utang"
            :description="`${props.debt.counterparty} · sisa ${props.debt.remaining}`"
        />

        <DebtForm
            :options="props.options"
            :debt="props.debt"
            submit-label="Simpan perubahan"
            :on-submit="submit"
        />
    </div>
</template>
