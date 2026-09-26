<script setup lang="ts">
import { SidebarProvider } from '@/components/ui/sidebar';
import {
    resolveInitialSidebarOpen,
    useSidebarPreference,
} from '@/composables/useSidebarPreference';
import type { AppVariant } from '@/types';

type Props = {
    variant?: AppVariant;
};

withDefaults(defineProps<Props>(), {
    variant: 'sidebar',
});

const { setOpen } = useSidebarPreference();

// Nilai awal hanya dibaca sekali: setelah itu SidebarProvider yang menjadi
// sumber state, dan `setOpen` menyinkronkan ke localStorage + server.
const initialOpen = resolveInitialSidebarOpen();
</script>

<template>
    <div v-if="variant === 'header'" class="flex min-h-screen w-full flex-col">
        <slot />
    </div>
    <SidebarProvider v-else :default-open="initialOpen" @open-change="setOpen">
        <slot />
    </SidebarProvider>
</template>
