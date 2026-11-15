import { usePage } from '@inertiajs/vue3';
import type { ComputedRef, DeepReadonly } from 'vue';
import { computed, readonly } from 'vue';
import type { User } from '@/types/auth';

/**
 * Akses read-only ke user yang sedang login dan role globalnya.
 *
 * Pendamping {@link useWorkspace}: composable itu menjawab "apa hak saya di
 * buku keuangan ini", sedangkan yang ini menjawab "apa hak saya di aplikasi".
 *
 * `isAdmin` hanya menentukan apa yang DITAMPILKAN. Setiap rute admin tetap
 * dijaga middleware `admin` di server, jadi menyembunyikan menu bukan
 * mekanisme keamanannya.
 */
export type UseAuthReturn = {
    user: DeepReadonly<ComputedRef<User | null>>;
    isAdmin: DeepReadonly<ComputedRef<boolean>>;
};

export function useAuth(): UseAuthReturn {
    const page = usePage();

    const user = computed(() => (page.props.auth?.user ?? null) as User | null);
    const isAdmin = computed(() => Boolean(page.props.auth?.is_admin));

    return {
        user: readonly(user),
        isAdmin: readonly(isAdmin),
    };
}
