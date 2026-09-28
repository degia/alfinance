<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';
import { useSidebar } from '@/components/ui/sidebar';

/**
 * Indikator menu aktif di sidebar kiri (AGENT.md Fase 1).
 *
 * Posisi bar mengikuti item `[data-nav-href][data-active="true"]`, dengan
 * transisi `top`/`height` supaya perpindahan menu terasa halus. Bar disembunyikan
 * saat sidebar collapsed karena label menu tidak terlihat lagi (tooltip yang
 * menggantikan).
 */
const { state } = useSidebar();

/**
 * Transisi memakai token motion yang sama dengan sisa aplikasi
 * (`--motion-slow` + `--ease-neu`), bukan durasi hardcode, supaya active
 * bar tidak menyimpang dari kartu dan tombol.
 */
const barTransition: Record<string, string> = {
    top: 'var(--motion-slow) var(--ease-neu)',
    height: 'var(--motion-slow) var(--ease-neu)',
    opacity: 'var(--motion-base) var(--ease-neu)',
};

const navRef = ref<HTMLElement | null>(null);
const isCollapsed = computed(() => state.value === 'collapsed');
const currentUrl = computed(() => usePage().url);

const barStyle = ref<Record<string, string>>({
    top: '0px',
    height: '0px',
    opacity: '0',
});

async function updateBar(): Promise<void> {
    const container = navRef.value;

    if (!container) {
        return;
    }

    const active = isCollapsed.value
        ? null
        : container.querySelector<HTMLElement>(
              '[data-nav-href][data-active="true"]',
          );

    if (!active) {
        barStyle.value = { ...barStyle.value, opacity: '0' };

        return;
    }

    barStyle.value = {
        top: `${active.offsetTop - container.offsetTop}px`,
        height: `${active.offsetHeight}px`,
        opacity: '1',
    };
}

// Navigasi Inertia dan perubahan state sidebar memengaruhi posisi bar.
watch([isCollapsed, currentUrl], () => {
    void nextTick(updateBar);
});

onMounted(() => {
    void nextTick(updateBar);

    // Font yang selesai dimuat / resize bisa mengubah tinggi item.
    void document.fonts?.ready?.then(() => nextTick(updateBar));

    window.addEventListener('resize', updateBar);
});

onBeforeUnmount(() => {
    window.removeEventListener('resize', updateBar);
});
</script>

<template>
    <div ref="navRef" class="relative isolate">
        <slot />

        <span
            aria-hidden="true"
            class="pointer-events-none absolute inset-y-0 left-0 z-10 w-1 rounded-r-full bg-primary"
            :style="[barTransition, barStyle]"
        />
    </div>
</template>
