<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import {
    ArrowDown,
    ArrowUp,
    BadgeCheck,
    ChevronLeft,
    ChevronRight,
    KeyRound,
    LogOut,
    MailCheck,
    MoreHorizontal,
    Search,
    ShieldCheck,
    ShieldOff,
    Trash2,
    UserCheck,
    UserX,
} from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useAuth } from '@/composables/useAuth';
import { dashboard as adminDashboard } from '@/routes/admin';
import {
    destroy as destroyUser,
    forceLogout,
    index as usersIndex,
    passwordReset,
    resendVerification,
    role as updateRole,
    suspend as suspendUser,
    unsuspend as unsuspendUser,
    verifyEmail,
} from '@/routes/admin/users';
import type {
    AdminUserFilters,
    AdminUserPagination,
    AdminUserRow,
    AdminUserSort,
    AdminUserStatus,
} from '@/types';

defineOptions({
    layout: {
        title: 'Pengguna',
        description: 'Kelola akun, akses, dan status pengguna',
        breadcrumbs: [
            { title: 'Admin', href: adminDashboard() },
            { title: 'Pengguna', href: usersIndex() },
        ],
    },
});

const props = defineProps<{
    users: AdminUserPagination;
    filters: AdminUserFilters;
    statusOptions: AdminUserStatus[];
    sortOptions: AdminUserSort[];
    supportsForceLogout: boolean;
}>();

const { user: currentUser } = useAuth();

const statusLabels: Record<AdminUserStatus, string> = {
    all: 'Semua',
    online: 'Online',
    admin: 'Admin',
    suspended: 'Ditangguhkan',
    unverified: 'Belum verifikasi',
    never_logged_in: 'Belum pernah login',
};

const sortLabels: Record<AdminUserSort, string> = {
    name: 'Nama',
    email: 'Email',
    created_at: 'Terdaftar',
    last_login_at: 'Login terakhir',
    last_seen_at: 'Terlihat terakhir',
};

const search = ref(props.filters.search ?? '');

/*
|--------------------------------------------------------------------------
| Filter
|--------------------------------------------------------------------------
| Semua filter hidup di query string, bukan di state lokal, supaya hasil
| pencarian bisa di-bookmark/dibagikan dan tombol back browser bekerja wajar.
|
| Pencarian di-debounce agar tiap ketikan tidak menjadi satu request; nilai
| sebelumnya dibatalkan lewat `cancelToken` bawaan Inertia (`only` membuat
| hanya daftar user yang dikirim ulang, bukan seluruh prop halaman).
*/
let searchTimer: ReturnType<typeof setTimeout> | undefined;

watch(search, (value) => {
    clearTimeout(searchTimer);

    searchTimer = setTimeout(() => {
        applyFilters({ search: value.trim() || undefined, page: undefined });
    }, 300);
});

type FilterPatch = {
    search?: string;
    status?: AdminUserStatus;
    sort?: AdminUserSort;
    direction?: 'asc' | 'desc';
    page?: number;
};

function applyFilters(patch: FilterPatch): void {
    const query: Record<string, string> = {};

    const merged = {
        search: props.filters.search ?? undefined,
        status: props.filters.status,
        sort: props.filters.sort,
        direction: props.filters.direction,
        ...patch,
    };

    if (merged.search) {
        query.search = merged.search;
    }
    if (merged.status && merged.status !== 'all') {
        query.status = merged.status;
    }
    if (merged.sort && merged.sort !== 'created_at') {
        query.sort = merged.sort;
    }
    if (merged.direction && merged.direction !== 'desc') {
        query.direction = merged.direction;
    }
    if (merged.page && merged.page > 1) {
        query.page = String(merged.page);
    }

    router.get(usersIndex().url, query, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function toggleSort(column: AdminUserSort): void {
    const direction =
        props.filters.sort === column && props.filters.direction === 'asc'
            ? 'desc'
            : 'asc';

    applyFilters({ sort: column, direction, page: undefined });
}

function goTo(url: string | null): void {
    if (!url) {
        return;
    }

    router.get(url, {}, { preserveState: true, preserveScroll: true });
}

/*
|--------------------------------------------------------------------------
| Aksi
|--------------------------------------------------------------------------
| Aksi yang tidak bisa dibatalkan (tangguhkan, hapus) melewati dialog
| konfirmasi; sisanya langsung dikirim karena efeknya reversibel.
*/
type PendingAction =
    | { type: 'suspend'; user: AdminUserRow }
    | { type: 'delete'; user: AdminUserRow };

const pending = ref<PendingAction | null>(null);
const suspendReason = ref('');
const processing = ref(false);

watch(pending, () => {
    suspendReason.value = '';
});

const pendingTitle = computed(() => {
    if (!pending.value) {
        return '';
    }

    return pending.value.type === 'suspend'
        ? `Tangguhkan ${pending.value.user.name}?`
        : `Hapus akun ${pending.value.user.name}?`;
});

function isSelf(user: AdminUserRow): boolean {
    return currentUser.value?.id === user.id;
}

/**
 * `router.delete` menerima (url, options) sedangkan `router.post` menerima
 * (url, data, options), jadi keduanya tidak bisa dipanggil lewat indeks yang
 * sama tanpa menggeser argumen options ke posisi data.
 */
function send(url: string, method: 'post' | 'delete' = 'post'): void {
    processing.value = true;

    const options = {
        preserveScroll: true,
        onFinish: () => {
            processing.value = false;
            pending.value = null;
        },
    };

    if (method === 'delete') {
        router.delete(url, options);

        return;
    }

    router.post(url, {}, options);
}

function toggleRole(user: AdminUserRow): void {
    processing.value = true;

    router.put(
        updateRole(user.id).url,
        { role: user.role === 'admin' ? 'user' : 'admin' },
        {
            preserveScroll: true,
            onFinish: () => {
                processing.value = false;
            },
        },
    );
}

function confirmPending(): void {
    const action = pending.value;

    if (!action) {
        return;
    }

    if (action.type === 'delete') {
        send(destroyUser(action.user.id).url, 'delete');

        return;
    }

    processing.value = true;

    router.post(
        suspendUser(action.user.id).url,
        { reason: suspendReason.value.trim() },
        {
            preserveScroll: true,
            onFinish: () => {
                processing.value = false;
                pending.value = null;
            },
        },
    );
}
</script>

<template>
    <Head title="Pengguna" />

    <div class="flex flex-col gap-6">
        <Heading
            title="Pengguna"
            description="Cari, pantau, dan kendalikan akun yang terdaftar di aplikasi."
        />

        <Card class="rounded-2xl border-0 shadow-neu-flat">
            <CardContent class="flex flex-col gap-4">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-center">
                    <div class="relative flex-1">
                        <Search
                            class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                        />
                        <Input
                            v-model="search"
                            type="search"
                            placeholder="Cari nama atau email…"
                            autocomplete="off"
                            class="pl-9 shadow-neu-inset"
                            aria-label="Cari pengguna"
                        />
                    </div>

                    <div class="flex flex-wrap gap-1.5">
                        <Button
                            v-for="option in statusOptions"
                            :key="option"
                            type="button"
                            size="sm"
                            :variant="
                                filters.status === option
                                    ? 'default'
                                    : 'outline'
                            "
                            @click="
                                applyFilters({
                                    status: option,
                                    page: undefined,
                                })
                            "
                        >
                            {{ statusLabels[option] }}
                        </Button>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-1.5">
                    <span class="text-xs text-muted-foreground">
                        Urutkan:
                    </span>
                    <Button
                        v-for="option in sortOptions"
                        :key="option"
                        type="button"
                        size="sm"
                        variant="ghost"
                        :class="
                            filters.sort === option
                                ? 'text-foreground'
                                : 'text-muted-foreground'
                        "
                        @click="toggleSort(option)"
                    >
                        {{ sortLabels[option] }}
                        <component
                            v-if="filters.sort === option"
                            :is="
                                filters.direction === 'asc'
                                    ? ArrowUp
                                    : ArrowDown
                            "
                            class="size-3"
                        />
                    </Button>
                </div>
            </CardContent>
        </Card>

        <div
            v-if="users.data.length === 0"
            class="rounded-2xl border border-dashed p-10 text-center text-sm text-muted-foreground"
        >
            Tidak ada pengguna yang cocok dengan filter ini.
        </div>

        <Card v-else class="rounded-2xl border-0 shadow-neu-flat">
            <CardContent class="flex flex-col gap-2">
                <div
                    v-for="user in users.data"
                    :key="user.id"
                    class="flex flex-wrap items-center gap-3 rounded-xl p-3 hover:shadow-neu-flat-sm"
                >
                    <span
                        class="grid size-10 shrink-0 place-items-center rounded-xl bg-primary/10 text-sm font-semibold text-primary"
                    >
                        {{ user.name.charAt(0).toUpperCase() }}
                    </span>

                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="truncate font-medium">
                                {{ user.name }}
                            </span>

                            <Badge
                                v-if="user.role === 'admin'"
                                class="text-2xs"
                            >
                                Admin
                            </Badge>
                            <Badge
                                v-if="user.is_suspended"
                                variant="destructive"
                                class="text-2xs"
                                :title="user.suspended_reason ?? undefined"
                            >
                                Ditangguhkan
                            </Badge>
                            <Badge
                                v-if="!user.is_verified"
                                variant="outline"
                                class="text-2xs"
                            >
                                Belum verifikasi
                            </Badge>
                            <Badge
                                v-if="user.two_factor_enabled"
                                variant="secondary"
                                class="text-2xs"
                            >
                                2FA
                            </Badge>
                            <span
                                v-if="user.is_online"
                                class="flex items-center gap-1 text-xs text-emerald-600 dark:text-emerald-400"
                            >
                                <span
                                    class="size-1.5 rounded-full bg-emerald-500"
                                />
                                Online
                            </span>
                        </div>

                        <p class="truncate text-xs text-muted-foreground">
                            {{ user.email }}
                        </p>
                    </div>

                    <dl
                        class="hidden shrink-0 gap-6 text-xs text-muted-foreground md:flex"
                    >
                        <div>
                            <dt>Login terakhir</dt>
                            <dd class="text-foreground">
                                {{ user.last_login_at_diff ?? 'Belum pernah' }}
                            </dd>
                        </div>
                        <div>
                            <dt>Workspace</dt>
                            <dd class="text-foreground">
                                {{ user.workspaces_count }}
                            </dd>
                        </div>
                        <div>
                            <dt>Terdaftar</dt>
                            <dd class="text-foreground">
                                {{ user.created_at_diff }}
                            </dd>
                        </div>
                    </dl>

                    <DropdownMenu>
                        <DropdownMenuTrigger as-child>
                            <Button
                                variant="ghost"
                                size="icon-sm"
                                :aria-label="`Aksi untuk ${user.name}`"
                                :disabled="processing"
                            >
                                <MoreHorizontal class="size-4" />
                            </Button>
                        </DropdownMenuTrigger>

                        <DropdownMenuContent align="end" class="w-60">
                            <DropdownMenuLabel class="truncate">
                                {{ user.email }}
                            </DropdownMenuLabel>
                            <DropdownMenuSeparator />

                            <DropdownMenuItem
                                :disabled="isSelf(user)"
                                @select="toggleRole(user)"
                            >
                                <component
                                    :is="
                                        user.role === 'admin'
                                            ? ShieldOff
                                            : ShieldCheck
                                    "
                                    class="mr-2 size-4"
                                />
                                {{
                                    user.role === 'admin'
                                        ? 'Turunkan jadi pengguna'
                                        : 'Jadikan admin'
                                }}
                            </DropdownMenuItem>

                            <DropdownMenuItem
                                v-if="user.is_suspended"
                                @select="
                                    send(unsuspendUser(user.id).url, 'post')
                                "
                            >
                                <UserCheck class="mr-2 size-4" />
                                Aktifkan kembali
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                v-else
                                :disabled="isSelf(user)"
                                @select="pending = { type: 'suspend', user }"
                            >
                                <UserX class="mr-2 size-4" />
                                Tangguhkan akun
                            </DropdownMenuItem>

                            <DropdownMenuSeparator />

                            <DropdownMenuItem
                                v-if="!user.is_verified"
                                @select="send(verifyEmail(user.id).url)"
                            >
                                <BadgeCheck class="mr-2 size-4" />
                                Tandai email terverifikasi
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                v-if="!user.is_verified"
                                @select="send(resendVerification(user.id).url)"
                            >
                                <MailCheck class="mr-2 size-4" />
                                Kirim ulang verifikasi
                            </DropdownMenuItem>

                            <DropdownMenuItem
                                @select="send(passwordReset(user.id).url)"
                            >
                                <KeyRound class="mr-2 size-4" />
                                Kirim tautan reset sandi
                            </DropdownMenuItem>

                            <DropdownMenuItem
                                v-if="supportsForceLogout"
                                :disabled="isSelf(user)"
                                @select="send(forceLogout(user.id).url)"
                            >
                                <LogOut class="mr-2 size-4" />
                                Putus semua sesi
                            </DropdownMenuItem>

                            <DropdownMenuSeparator />

                            <DropdownMenuItem
                                variant="destructive"
                                :disabled="isSelf(user)"
                                @select="pending = { type: 'delete', user }"
                            >
                                <Trash2 class="mr-2 size-4" />
                                Hapus akun
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </CardContent>
        </Card>

        <div
            v-if="users.total > 0"
            class="flex flex-wrap items-center justify-between gap-3"
        >
            <p class="text-xs text-muted-foreground">
                Menampilkan {{ users.from }}–{{ users.to }} dari
                {{ users.total }} pengguna
            </p>

            <div class="flex items-center gap-2">
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="users.current_page <= 1"
                    @click="goTo(users.prev_page_url)"
                >
                    <ChevronLeft class="size-4" />
                    Sebelumnya
                </Button>
                <span class="text-xs text-muted-foreground">
                    Halaman {{ users.current_page }} / {{ users.last_page }}
                </span>
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="!users.next_page_url"
                    @click="goTo(users.next_page_url)"
                >
                    Berikutnya
                    <ChevronRight class="size-4" />
                </Button>
            </div>
        </div>
    </div>

    <Dialog
        :open="pending !== null"
        @update:open="(open) => !open && (pending = null)"
    >
        <DialogContent>
            <DialogHeader class="space-y-3">
                <DialogTitle>{{ pendingTitle }}</DialogTitle>
                <DialogDescription v-if="pending?.type === 'suspend'">
                    Akun langsung dikeluarkan dari semua sesinya dan tidak bisa
                    login sampai diaktifkan kembali. Alasan yang kamu tulis
                    ditampilkan di halaman login.
                </DialogDescription>
                <DialogDescription v-else>
                    Akun dan seluruh sesinya dihapus permanen. Workspace yang
                    dimilikinya diwariskan ke anggota lain; workspace tanpa
                    anggota lain ikut terhapus.
                </DialogDescription>
            </DialogHeader>

            <div v-if="pending?.type === 'suspend'" class="grid gap-2">
                <Label for="suspend-reason">Alasan (opsional)</Label>
                <Input
                    id="suspend-reason"
                    v-model="suspendReason"
                    v-focus
                    maxlength="255"
                    autocomplete="off"
                    placeholder="Contoh: aktivitas mencurigakan"
                    class="shadow-neu-inset"
                />
            </div>

            <DialogFooter class="gap-2">
                <Button variant="secondary" @click="pending = null">
                    Batal
                </Button>
                <Button
                    variant="destructive"
                    :disabled="processing"
                    @click="confirmPending"
                >
                    <Spinner v-if="processing" class="size-4" />
                    {{
                        pending?.type === 'suspend'
                            ? 'Tangguhkan'
                            : 'Hapus akun'
                    }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
