<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import ErrorState from '@/components/ErrorState.vue';

type Props = {
    status: number;
};

defineProps<Props>();
</script>

<template>
    <Head :title="`Error ${status}`" />

    <ErrorState :status="status" />
</template>
