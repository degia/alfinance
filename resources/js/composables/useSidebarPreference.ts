import { usePage } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import type { ComputedRef, Ref } from 'vue';
import { computed, ref } from 'vue';
import { sidebar } from '@/routes/settings';

/**
 * State collapse sidebar:
 * - render awal: `users.sidebar_collapsed` dari server (shared Inertia),
 * - setelahnya: `localStorage` (anti flicker saat pindah halaman),
 * - perubahan: request ringan ke `POST settings/sidebar` dengan debounce.
 *
 * `collapsed = !open`, jadi sidebar terbuka berarti `collapsed = false`.
 */
export const SIDEBAR_PREFERENCE_KEY = 'alfinance:sidebar-collapsed';

/** Berapa lama menunggu setelah user selesai klik sebelum menyimpan. */
export const SIDEBAR_PERSIST_DEBOUNCE_MS = 400;

export type UseSidebarPreferenceReturn = {
    collapsed: ComputedRef<boolean>;
    isOpen: ComputedRef<boolean>;
    /** true bila localStorage sudah punya nilai. */
    hasStoredPreference: ComputedRef<boolean>;
    setOpen: (open: boolean) => void;
};

function readStoredPreference(): boolean | null {
    if (typeof window === 'undefined') {
        return null;
    }

    const stored = window.localStorage.getItem(SIDEBAR_PREFERENCE_KEY);

    if (stored === null) {
        return null;
    }

    return stored === '1' || stored === 'true';
}

function writeStoredPreference(collapsed: boolean): void {
    if (typeof window === 'undefined') {
        return;
    }

    window.localStorage.setItem(SIDEBAR_PREFERENCE_KEY, collapsed ? '1' : '0');
}

/**
 * Token CSRF dari root view untuk request non-Inertia.
 */
function csrfToken(): string {
    if (typeof document === 'undefined') {
        return '';
    }

    return (
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? ''
    );
}

/**
 * Nilai terakhir yang tersimpan di server, dipakai agar request tidak dikirim
 * ulang untuk nilai yang sama.
 */
let lastPersisted: boolean | null = null;

export function useSidebarPreference(): UseSidebarPreferenceReturn {
    const stored: Ref<boolean | null> = ref(readStoredPreference());

    const serverCollapsed = computed(
        () => usePage().props.sidebarCollapsed === true,
    );

    const collapsed = computed(() => stored.value ?? serverCollapsed.value);
    const isOpen = computed(() => !collapsed.value);
    const hasStoredPreference = computed(() => stored.value !== null);

    const persist = useDebounceFn((value: boolean) => {
        lastPersisted = value;

        /*
         * `POST settings/sidebar` sengaja membalas JSON biasa, bukan Inertia
         * response, supaya tidak memicu render ulang halaman. Karena itu
         * request-nya HARUS lewat `fetch`: kalau dikirim dengan `router.post`,
         * Inertia mengharapkan response Inertia dan melempar "All Inertia
         * requests must receive a valid Inertia response".
         */
        void fetch(sidebar.url(), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify({ collapsed: value }),
            // Kalau navigasi menyusul, request tetap terkirim.
            keepalive: true,
        }).catch(() => {
            // Preferensi ini hanya memengaruhi tampilan; localStorage sudah
            // menyimpan nilainya, jadi kegagalan sinkronisasi diabaikan.
        });
    }, SIDEBAR_PERSIST_DEBOUNCE_MS);

    function setOpen(open: boolean): void {
        const value = !open;

        writeStoredPreference(value);
        stored.value = value;

        if (value !== lastPersisted) {
            void persist(value);
        }
    }

    return {
        collapsed,
        isOpen,
        hasStoredPreference,
        setOpen,
    };
}

/**
 * Nilai awal untuk SidebarProvider: pakai localStorage bila tersedia supaya
 * sidebar tidak berkedip saat Inertia melakukan navigasi.
 */
export function resolveInitialSidebarOpen(): boolean {
    const stored = readStoredPreference();

    if (stored !== null) {
        return !stored;
    }

    return usePage().props.sidebarCollapsed !== true;
}
