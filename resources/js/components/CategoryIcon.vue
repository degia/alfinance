<script setup lang="ts">
import {
    Baby,
    BriefcaseBusiness,
    Car,
    Clapperboard,
    CreditCard,
    Gift,
    GraduationCap,
    HandCoins,
    HeartPulse,
    House,
    PiggyBank,
    Plane,
    Repeat,
    Shapes,
    ShieldCheck,
    ShoppingBag,
    TrendingUp,
    Utensils,
    Wallet,
    Zap,
} from '@lucide/vue';
import type { Component } from 'vue';

/**
 * Memetakan nama ikon dari `App\Enums\CategoryIcon` ke komponen lucide.
 *
 * Pemetaan eksplisit (bukan dynamic import) supaya ikon yang tidak dikenal
 * tidak bisa merusak render, dan daftar ini ikut di-type-check.
 */
const icons: Record<string, Component> = {
    'hand-coins': HandCoins,
    utensils: Utensils,
    'shopping-bag': ShoppingBag,
    car: Car,
    house: House,
    zap: Zap,
    'heart-pulse': HeartPulse,
    'graduation-cap': GraduationCap,
    clapperboard: Clapperboard,
    repeat: Repeat,
    'shield-check': ShieldCheck,
    'piggy-bank': PiggyBank,
    'trending-up': TrendingUp,
    wallet: Wallet,
    'briefcase-business': BriefcaseBusiness,
    gift: Gift,
    baby: Baby,
    plane: Plane,
    shapes: Shapes,
    'credit-card': CreditCard,
};

const props = withDefaults(
    defineProps<{
        icon: string;
        color?: string;
    }>(),
    {
        color: 'currentColor',
    },
);

const component = icons[props.icon] ?? Shapes;
</script>

<template>
    <component
        :is="component"
        aria-hidden="true"
        :style="{ color: props.color }"
    />
</template>
