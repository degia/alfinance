<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { Check, Copy, Download, Mail } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { dashboard as adminDashboard } from '@/routes/admin';
import {
    exportMethod as exportEmails,
    index as emailsIndex,
} from '@/routes/admin/emails';
import type { AdminEmailRow, AdminEmailSegment } from '@/types';

defineOptions({
    layout: {
        title: 'Daftar email',
        description: 'Alamat email pengguna untuk pengumuman',
        breadcrumbs: [
            { title: 'Admin', href: adminDashboard() },
            { title: 'Daftar email', href: emailsIndex() },
        ],
    },
});

const props = defineProps<{
    emails: AdminEmailRow[];
    segment: AdminEmailSegment;
    segments: AdminEmailSegment[];
}>();

const segmentLabels: Record<AdminEmailSegment, string> = {
    all: 'Semua',
    verified: 'Terverifikasi',
    unverified: 'Belum verifikasi',
    active: 'Tidak ditangguhkan',
};

const addressList = computed(() =>
    props.emails.map((row) => row.email).join(', '),
);

const copied = ref(false);
let copyTimer: ReturnType<typeof setTimeout> | undefined;

/**
 * Clipboard API hanya tersedia pada origin yang aman (HTTPS/localhost), jadi
 * kegagalannya ditangani: textarea tetap ada di halaman sebagai cadangan
 * untuk menyalin manual.
 */
async function copyAll(): Promise<void> {
    try {
        await navigator.clipboard.writeText(addressList.value);

        copied.value = true;
        clearTimeout(copyTimer);
        copyTimer = setTimeout(() => (copied.value = false), 2000);
    } catch {
        copied.value = false;
    }
}

function selectSegment(segment: AdminEmailSegment): void {
    router.get(emailsIndex().url, segment === 'all' ? {} : { segment }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

const exportUrl = computed(
    () =>
        exportEmails({
            query: props.segment === 'all' ? {} : { segment: props.segment },
        }).url,
);
</script>

<template>
    <Head title="Daftar email" />

    <div class="flex flex-col gap-6">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <Heading
                title="Daftar email"
                description="Kumpulan alamat email pengguna, siap disalin atau diunduh."
                :spaced="false"
            />

            <Button variant="outline" size="sm" as-child>
                <a :href="exportUrl">
                    <Download class="size-4" />
                    Unduh CSV
                </a>
            </Button>
        </div>

        <Card class="rounded-2xl border-0 shadow-neu-flat">
            <CardContent class="flex flex-col gap-4">
                <div class="flex flex-wrap items-center gap-1.5">
                    <Button
                        v-for="option in segments"
                        :key="option"
                        type="button"
                        size="sm"
                        :variant="segment === option ? 'default' : 'outline'"
                        @click="selectSegment(option)"
                    >
                        {{ segmentLabels[option] }}
                    </Button>

                    <span class="ml-auto text-xs text-muted-foreground">
                        {{ emails.length }} alamat
                    </span>
                </div>

                <div class="flex flex-col gap-2">
                    <label
                        for="email-list"
                        class="text-xs text-muted-foreground"
                    >
                        Dipisahkan koma, siap ditempel ke kolom BCC.
                    </label>
                    <textarea
                        id="email-list"
                        :value="addressList"
                        readonly
                        rows="4"
                        class="w-full resize-y rounded-xl bg-transparent p-3 font-mono text-xs shadow-neu-inset outline-none"
                    />
                    <Button
                        type="button"
                        variant="secondary"
                        size="sm"
                        class="self-start"
                        :disabled="emails.length === 0"
                        @click="copyAll"
                    >
                        <component :is="copied ? Check : Copy" class="size-4" />
                        {{ copied ? 'Tersalin' : 'Salin semua' }}
                    </Button>
                </div>
            </CardContent>
        </Card>

        <div
            v-if="emails.length === 0"
            class="rounded-2xl border border-dashed p-10 text-center text-sm text-muted-foreground"
        >
            Tidak ada alamat email pada segmen ini.
        </div>

        <Card v-else class="rounded-2xl border-0 shadow-neu-flat">
            <CardContent class="flex flex-col gap-1">
                <div
                    v-for="row in emails"
                    :key="row.id"
                    class="flex flex-wrap items-center gap-3 rounded-xl px-3 py-2 hover:shadow-neu-flat-sm"
                >
                    <Mail class="size-4 shrink-0 text-muted-foreground" />

                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-medium">
                            {{ row.email }}
                        </span>
                        <span
                            class="block truncate text-xs text-muted-foreground"
                        >
                            {{ row.name }}
                        </span>
                    </span>

                    <Badge
                        v-if="!row.is_verified"
                        variant="outline"
                        class="shrink-0 text-2xs"
                    >
                        Belum verifikasi
                    </Badge>
                    <Badge
                        v-if="row.is_suspended"
                        variant="destructive"
                        class="shrink-0 text-2xs"
                    >
                        Ditangguhkan
                    </Badge>
                </div>
            </CardContent>
        </Card>
    </div>
</template>
