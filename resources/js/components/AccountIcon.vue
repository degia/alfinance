<script setup lang="ts">
import {
    Banknote,
    CreditCard,
    Landmark,
    PiggyBank,
    Smartphone,
} from '@lucide/vue';
import type { Component } from 'vue';

/**
 * Pemetaan nama ikon dari `App\Enums\AccountType::icon()` ke komponen lucide.
 * Key-nya adalah nama ikon (bukan nilai enum akun), jadi tipe kuncinya string.
 */
const icons: Record<string, Component> = {
    banknote: Banknote,
    landmark: Landmark,
    smartphone: Smartphone,
    'piggy-bank': PiggyBank,
    'credit-card': CreditCard,
};

const props = defineProps<{
    icon: string;
}>();
</script>

<template>
    <component :is="icons[props.icon] ?? Banknote" aria-hidden="true" />
</template>
