<script setup lang="ts">
type Props = {
    title: string;
    description?: string;
    variant?: 'default' | 'small';
    /**
     * Jarak bawah milik pemanggil. Nilai default (8px) disusun supaya total
     * jarak ke blok berikutnya tepat 32px: `gap-6` (24px) dari stack halaman
     * + 8px milik Heading. Set `false` saat Heading berada di dalam flex row
     * (mis. baris judul + kontrol) supaya margin tidak ikut menambah tinggi
     * baris dan menggeser perataan `items-center`/`items-end`.
     */
    spaced?: boolean;
};

withDefaults(defineProps<Props>(), {
    variant: 'default',
    spaced: true,
});
</script>

<template>
    <header :class="variant === 'small' || !spaced ? '' : 'mb-2 space-y-0.5'">
        <h2
            :class="
                variant === 'small'
                    ? 'mb-0.5 text-base font-medium'
                    : 'text-xl font-semibold tracking-tight'
            "
        >
            {{ title }}
        </h2>
        <p v-if="description" class="text-sm text-muted-foreground">
            {{ description }}
        </p>
    </header>
</template>
