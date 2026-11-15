<script setup lang="ts">
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import {
    Activity,
    CircleCheck,
    CircleX,
    Mail,
    ShieldAlert,
    UserPlus,
    UsersRound,
    Wifi,
} from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { dashboard as adminDashboard } from '@/routes/admin';
import { index as adminEmailsIndex } from '@/routes/admin/emails';
import { index as adminUsersIndex } from '@/routes/admin/users';
import type {
    AdminActiveUser,
    AdminRecentUser,
    AdminSummary,
    AdminSystemCheck,
    AdminTrendPoint,
} from '@/types';

defineOptions({
    layout: {
        title: 'Ringkasan admin',
        description: 'Kondisi pengguna dan sistem saat ini',
        breadcrumbs: [{ title: 'Admin', href: adminDashboard() }],
    },
});

const props = defineProps<{
    summary: AdminSummary;
    registrationTrend: AdminTrendPoint[];
    recentlyActive: AdminActiveUser[];
    recentRegistrations: AdminRecentUser[];
    systemStatus: AdminSystemCheck[];
}>();

const kpis = computed(() => [
    {
        label: 'Total pengguna',
        value: props.summary.users.total,
        hint: `${props.summary.users.admins} admin`,
        icon: UsersRound,
    },
    {
        label: 'Online sekarang',
        value: props.summary.users.online,
        hint: `${props.summary.users.active_today} aktif hari ini`,
        icon: Wifi,
    },
    {
        label: 'Pengguna baru',
        value: props.summary.users.new_today,
        hint: `${props.summary.users.new_this_week} dalam 7 hari`,
        icon: UserPlus,
    },
    {
        label: 'Perlu perhatian',
        value: props.summary.users.suspended + props.summary.users.unverified,
        hint: `${props.summary.users.suspended} ditangguhkan · ${props.summary.users.unverified} belum verifikasi`,
        icon: ShieldAlert,
    },
]);

const secondary = computed(() => [
    { label: 'Workspace aktif', value: props.summary.workspaces.total },
    { label: 'Workspace di Sampah', value: props.summary.workspaces.trashed },
    { label: 'Total transaksi', value: props.summary.transactions.total },
    { label: 'Email terverifikasi', value: props.summary.users.verified },
    { label: '2FA aktif', value: props.summary.users.two_factor },
    { label: 'Belum pernah login', value: props.summary.users.never_logged_in },
]);

/**
 * Tinggi batang dihitung relatif terhadap hari tersibuk, bukan terhadap nilai
 * absolut — tanpa itu grafik akan rata di dasar saat registrasi harian hanya
 * satu-dua akun, yang justru kondisi paling umum.
 */
const peak = computed(() =>
    Math.max(1, ...props.registrationTrend.map((point) => point.count)),
);

function barHeight(count: number): string {
    return `${Math.max(count === 0 ? 2 : 8, (count / peak.value) * 100)}%`;
}

const numberFormatter = new Intl.NumberFormat('id-ID');

function formatNumber(value: number): string {
    return numberFormatter.format(value);
}
</script>

<template>
    <Head title="Ringkasan admin" />

    <div class="flex flex-col gap-6">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <Heading
                title="Ringkasan admin"
                description="Status pengguna, aktivitas terbaru, dan kesehatan sistem."
                :spaced="false"
            />

            <div class="flex gap-2">
                <Button variant="outline" size="sm" as-child>
                    <Link :href="adminUsersIndex()">
                        <UsersRound class="size-4" />
                        Kelola pengguna
                    </Link>
                </Button>
                <Button variant="outline" size="sm" as-child>
                    <Link :href="adminEmailsIndex()">
                        <Mail class="size-4" />
                        Daftar email
                    </Link>
                </Button>
            </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <Card
                v-for="kpi in kpis"
                :key="kpi.label"
                class="rounded-2xl border-0 shadow-neu-flat"
            >
                <CardContent class="flex items-start gap-3">
                    <span
                        class="grid size-10 shrink-0 place-items-center rounded-xl bg-primary/10 text-primary"
                    >
                        <component :is="kpi.icon" class="size-5" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-xs text-muted-foreground">
                            {{ kpi.label }}
                        </p>
                        <p class="text-2xl font-semibold">
                            {{ formatNumber(kpi.value) }}
                        </p>
                        <p class="mt-0.5 text-xs text-muted-foreground">
                            {{ kpi.hint }}
                        </p>
                    </div>
                </CardContent>
            </Card>
        </div>

        <Card class="rounded-2xl border-0 shadow-neu-flat">
            <CardContent class="flex flex-col gap-4">
                <Heading
                    variant="small"
                    title="Registrasi 30 hari terakhir"
                    :description="`Puncak ${formatNumber(peak)} pendaftar dalam satu hari`"
                />

                <div class="flex h-40 items-end gap-1">
                    <div
                        v-for="point in registrationTrend"
                        :key="point.date"
                        class="group relative flex h-full flex-1 items-end"
                        :title="`${point.label}: ${point.count} pendaftar`"
                    >
                        <div
                            class="w-full rounded-t-sm bg-primary/70 transition-colors group-hover:bg-primary"
                            :style="{ height: barHeight(point.count) }"
                        />
                    </div>
                </div>

                <div class="flex justify-between text-xs text-muted-foreground">
                    <span>{{ registrationTrend[0]?.label }}</span>
                    <span>
                        {{
                            registrationTrend[registrationTrend.length - 1]
                                ?.label
                        }}
                    </span>
                </div>
            </CardContent>
        </Card>

        <div class="grid gap-4 lg:grid-cols-2">
            <Card class="rounded-2xl border-0 shadow-neu-flat">
                <CardContent class="flex flex-col gap-4">
                    <Heading
                        variant="small"
                        title="Aktivitas terakhir"
                        description="Pengguna yang paling baru terlihat"
                    />

                    <p
                        v-if="recentlyActive.length === 0"
                        class="text-sm text-muted-foreground"
                    >
                        Belum ada aktivitas tercatat.
                    </p>

                    <ul v-else class="flex flex-col gap-3">
                        <li
                            v-for="user in recentlyActive"
                            :key="user.id"
                            class="flex items-center gap-3"
                        >
                            <span
                                class="size-2 shrink-0 rounded-full"
                                :class="
                                    user.is_online
                                        ? 'bg-emerald-500'
                                        : 'bg-muted-foreground/40'
                                "
                                :aria-label="
                                    user.is_online ? 'Online' : 'Offline'
                                "
                            />
                            <span class="min-w-0 flex-1">
                                <span
                                    class="block truncate text-sm font-medium"
                                >
                                    {{ user.name }}
                                </span>
                                <span
                                    class="block truncate text-xs text-muted-foreground"
                                >
                                    {{ user.email }}
                                </span>
                            </span>
                            <span
                                class="shrink-0 text-right text-xs text-muted-foreground"
                            >
                                <span class="block">
                                    {{ user.last_seen_at_diff }}
                                </span>
                                <span v-if="user.last_login_ip" class="block">
                                    {{ user.last_login_ip }}
                                </span>
                            </span>
                        </li>
                    </ul>
                </CardContent>
            </Card>

            <Card class="rounded-2xl border-0 shadow-neu-flat">
                <CardContent class="flex flex-col gap-4">
                    <Heading
                        variant="small"
                        title="Pendaftar terbaru"
                        description="Akun yang baru dibuat"
                    />

                    <p
                        v-if="recentRegistrations.length === 0"
                        class="text-sm text-muted-foreground"
                    >
                        Belum ada pendaftar.
                    </p>

                    <ul v-else class="flex flex-col gap-3">
                        <li
                            v-for="user in recentRegistrations"
                            :key="user.id"
                            class="flex items-center gap-3"
                        >
                            <span class="min-w-0 flex-1">
                                <span
                                    class="block truncate text-sm font-medium"
                                >
                                    {{ user.name }}
                                </span>
                                <span
                                    class="block truncate text-xs text-muted-foreground"
                                >
                                    {{ user.email }}
                                </span>
                            </span>
                            <Badge
                                :variant="
                                    user.is_verified ? 'secondary' : 'outline'
                                "
                                class="shrink-0 text-2xs"
                            >
                                {{
                                    user.is_verified
                                        ? 'Terverifikasi'
                                        : 'Belum verifikasi'
                                }}
                            </Badge>
                            <span
                                class="shrink-0 text-xs text-muted-foreground"
                            >
                                {{ user.created_at_diff }}
                            </span>
                        </li>
                    </ul>
                </CardContent>
            </Card>
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            <Card class="rounded-2xl border-0 shadow-neu-flat">
                <CardContent class="flex flex-col gap-4">
                    <Heading
                        variant="small"
                        title="Angka lain"
                        description="Cakupan data di seluruh aplikasi"
                    />

                    <dl class="grid gap-3 sm:grid-cols-2">
                        <div
                            v-for="item in secondary"
                            :key="item.label"
                            class="rounded-xl p-3 shadow-neu-inset"
                        >
                            <dt class="text-xs text-muted-foreground">
                                {{ item.label }}
                            </dt>
                            <dd class="text-lg font-semibold">
                                {{ formatNumber(item.value) }}
                            </dd>
                        </div>
                    </dl>
                </CardContent>
            </Card>

            <Card class="rounded-2xl border-0 shadow-neu-flat">
                <CardContent class="flex flex-col gap-4">
                    <Heading
                        variant="small"
                        title="Status sistem"
                        description="Layanan yang menopang aplikasi"
                    />

                    <ul class="flex flex-col gap-3">
                        <li
                            v-for="check in systemStatus"
                            :key="check.name"
                            class="flex items-center gap-3"
                        >
                            <component
                                :is="
                                    check.status === 'ok'
                                        ? CircleCheck
                                        : CircleX
                                "
                                class="size-4 shrink-0"
                                :class="
                                    check.status === 'ok'
                                        ? 'text-emerald-500'
                                        : 'text-destructive'
                                "
                            />
                            <span class="flex-1 text-sm font-medium">
                                {{ check.name }}
                            </span>
                            <span
                                class="max-w-[60%] truncate text-xs text-muted-foreground"
                                :title="check.detail"
                            >
                                {{ check.detail }}
                            </span>
                        </li>
                    </ul>

                    <p
                        class="flex items-center gap-1.5 text-xs text-muted-foreground"
                    >
                        <Activity class="size-3" />
                        Status dibaca langsung saat halaman dimuat, tanpa cache.
                    </p>
                </CardContent>
            </Card>
        </div>
    </div>
</template>
