<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import {
    Chart,
    LineController,
    LineElement,
    PointElement,
    LinearScale,
    CategoryScale,
    Filler,
    Tooltip,
    Legend,
} from 'chart.js';
import { Head, router, useForm } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2, TrendingDown, TrendingUp, X } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { useAppearance } from '@/composables/useAppearance';
import { chartAnimation, chartToken } from '@/lib/chart';
import { formatCurrency, formatSignedCurrency, toNumber } from '@/lib/format';
import {
    destroy as destroyItem,
    index as netWorthIndex,
    store as storeItem,
    update as updateItem,
} from '@/routes/net-worth';
import type {
    NetWorthIndexProps,
    NetWorthItem,
    NetWorthItemFormData,
} from '@/types';

Chart.register(
    LineController,
    LineElement,
    PointElement,
    LinearScale,
    CategoryScale,
    Filler,
    Tooltip,
    Legend,
);

defineOptions({
    layout: {
        title: 'Net worth',
        description: 'Aset, kewajiban, dan tren bulanan',
    },
});

const props = defineProps<NetWorthIndexProps>();

const emptyForm = (): NetWorthItemFormData => ({
    type: 'asset',
    subtype: props.options.types[0]?.subtypes[0]?.value ?? '',
    name: '',
    value: '',
    annual_rate: '',
    valued_at: new Date().toISOString().slice(0, 10),
    note: '',
});

const isFormOpen = ref(false);
const editingId = ref<number | null>(null);
const form = useForm<NetWorthItemFormData>(emptyForm());

const availableSubtypes = computed(() => {
    const type = props.options.types.find(
        (option) => option.value === form.type,
    );

    return type?.subtypes ?? [];
});

/** Subtype wajib ikut tipe: ganti tipe berarti subtype lama tidak berlaku. */
watch(
    () => form.type,
    (type) => {
        const subtypes =
            props.options.types.find((option) => option.value === type)
                ?.subtypes ?? [];
        const stillValid = subtypes.some(
            (subtype) => subtype.value === form.subtype,
        );

        if (!stillValid) {
            form.subtype = subtypes[0]?.value ?? '';
        }
    },
);

function startCreate(): void {
    editingId.value = null;
    form.defaults(emptyForm());
    form.reset();
    form.clearErrors();
    isFormOpen.value = true;
}

function startEdit(item: NetWorthItem): void {
    editingId.value = item.id;
    form.defaults({
        type: item.type,
        subtype: item.subtype,
        name: item.name,
        value: item.value,
        annual_rate: item.annual_rate ?? '',
        valued_at: item.valued_at,
        note: item.note ?? '',
    });
    form.reset();
    form.clearErrors();
    isFormOpen.value = true;
}

function submit(): void {
    if (editingId.value === null) {
        form.post(storeItem().url, {
            preserveScroll: true,
            onSuccess: () => (isFormOpen.value = false),
        });

        return;
    }

    form.put(updateItem(editingId.value).url, {
        preserveScroll: true,
        onSuccess: () => (isFormOpen.value = false),
    });
}

function remove(id: number): void {
    router.delete(destroyItem(id).url, { preserveScroll: true });
}

const assets = computed(() =>
    props.items.filter((item) => item.type === 'asset'),
);
const liabilities = computed(() =>
    props.items.filter((item) => item.type === 'liability'),
);

const netWorthTone = computed(() => {
    const value = toNumber(props.summary.net_worth) ?? 0;

    if (value > 0) {
        return 'text-emerald-600 dark:text-emerald-400';
    }

    if (value < 0) {
        return 'text-rose-600 dark:text-rose-400';
    }

    return 'text-muted-foreground';
});

const summaryCards = computed(() => [
    {
        label: 'Total aset',
        value: props.summary.total_assets,
        hint: `Item ${formatCurrency(props.summary.item_assets)} + kartu kredit overpay + piutang`,
    },
    {
        label: 'Total kewajiban',
        value: props.summary.total_liabilities,
        hint: `Item ${formatCurrency(props.summary.item_liabilities)} + kartu kredit + utang`,
    },
    {
        label: 'Net worth',
        value: props.summary.net_worth,
        hint: 'Aset dikurangi kewajiban',
        tone: netWorthTone.value,
    },
]);

/*
|--------------------------------------------------------------------------
| Grafik tren
|--------------------------------------------------------------------------
*/

const canvas = ref<HTMLCanvasElement | null>(null);
let chart: Chart | null = null;

function renderChart(): void {
    if (canvas.value === null) {
        return;
    }

    chart?.destroy();

    const values = props.series.map((point) => toNumber(point.net_worth));

    chart = new Chart(canvas.value, {
        type: 'line',
        data: {
            labels: props.series.map((point) => point.label),
            datasets: [
                {
                    label: 'Net worth',
                    data: values,
                    borderColor: chartToken('income'),
                    backgroundColor: chartToken('incomeSoft'),
                    tension: 0.35,
                    fill: true,
                    spanGaps: true,
                    pointRadius: 3,
                },
            ],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            animation: chartAnimation(),
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: (context) => {
                            const value = context.parsed.y;

                            return value === null
                                ? 'Belum ada snapshot'
                                : formatCurrency(value);
                        },
                    },
                },
            },
            scales: {
                y: {
                    ticks: {
                        callback: (value) => formatCurrency(Number(value)),
                    },
                    grid: { color: chartToken('grid') },
                },
                x: { grid: { display: false } },
            },
        },
    });
}

onMounted(renderChart);
onBeforeUnmount(() => chart?.destroy());
watch(() => props.series, renderChart, { deep: true });

/*
| Warna garis tren berasal dari token CSS, jadi chart harus digambar ulang
| saat light/dark mode berubah.
*/
const { resolvedAppearance } = useAppearance();

watch(resolvedAppearance, renderChart);
</script>

<template>
    <Head title="Net worth" />

    <div class="flex flex-col gap-6">
        <Heading
            title="Net worth"
            description="Aset dan kewajiban hari ini. Angka tren dibaca dari snapshot bulanan, bukan dihitung ulang dari transaksi."
        />

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <Card
                v-for="card in summaryCards"
                :key="card.label"
                class="rounded-2xl border-0 shadow-neu-flat"
            >
                <CardContent>
                    <p
                        class="text-xs tracking-wide text-muted-foreground uppercase"
                    >
                        {{ card.label }}
                    </p>
                    <p class="mt-1 text-xl font-semibold" :class="card.tone">
                        {{ formatCurrency(card.value) }}
                    </p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        {{ card.hint }}
                    </p>
                    <p
                        v-if="
                            card.label === 'Net worth' && props.summary.change
                        "
                        class="mt-2 flex items-center gap-1 text-xs font-medium"
                        :class="
                            props.summary.change.direction === 'down'
                                ? 'text-rose-600 dark:text-rose-400'
                                : 'text-emerald-600 dark:text-emerald-400'
                        "
                    >
                        <TrendingUp
                            v-if="props.summary.change.direction === 'up'"
                            class="size-3.5"
                        />
                        <TrendingDown
                            v-else-if="
                                props.summary.change.direction === 'down'
                            "
                            class="size-3.5"
                        />
                        {{ formatSignedCurrency(props.summary.change.amount) }}
                        dari bulan lalu
                    </p>
                </CardContent>
            </Card>
        </div>

        <Card class="rounded-2xl border-0 shadow-neu-flat">
            <CardContent class="flex flex-col gap-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="flex items-center gap-2">
                        <h3 class="text-sm font-semibold">
                            Tren {{ props.year }}
                        </h3>
                        <Select
                            :model-value="String(props.year)"
                            @update:model-value="
                                router.get(
                                    netWorthIndex({
                                        query: { year: Number($event) },
                                    }).url,
                                )
                            "
                        >
                            <SelectTrigger class="w-28">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="year in props.years"
                                    :key="year"
                                    :value="String(year)"
                                >
                                    {{ year }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    <Button size="sm" class="gap-2" @click="startCreate">
                        <Plus class="size-4" />
                        Tambah item
                    </Button>
                </div>

                <div class="h-64">
                    <canvas ref="canvas" />
                </div>

                <p
                    v-if="!props.series.some((point) => point.has_snapshot)"
                    class="text-xs text-muted-foreground"
                >
                    Belum ada snapshot untuk {{ props.year }}. Snapshot bulan
                    berjalan dibuat otomatis setiap kali item atau cicilan
                    berubah, lalu bulanan setiap tanggal 1.
                </p>
            </CardContent>
        </Card>

        <div class="grid gap-4 lg:grid-cols-2">
            <Card
                v-for="group in [
                    { title: 'Aset', rows: assets },
                    { title: 'Kewajiban', rows: liabilities },
                ]"
                :key="group.title"
                class="rounded-2xl border-0 shadow-neu-flat"
            >
                <CardContent class="flex flex-col gap-3">
                    <h3 class="text-sm font-semibold">{{ group.title }}</h3>

                    <p
                        v-if="group.rows.length === 0"
                        class="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground"
                    >
                        Belum ada item {{ group.title.toLowerCase() }}.
                    </p>

                    <div
                        v-for="item in group.rows"
                        :key="item.id"
                        class="neu-inset flex items-center justify-between gap-3 rounded-lg px-3 py-2"
                    >
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium">
                                {{ item.name }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {{ item.subtype_label }}
                                <span v-if="item.annual_rate">
                                    · {{ item.annual_rate }}%/thn</span
                                >
                                · nilai {{ formatCurrency(item.value) }}
                            </p>
                        </div>

                        <div class="flex items-center gap-2">
                            <span class="text-sm font-semibold">
                                {{ formatCurrency(item.current_value) }}
                            </span>
                            <Button
                                variant="ghost"
                                size="sm"
                                :aria-label="`Ubah ${item.name}`"
                                @click="startEdit(item)"
                            >
                                <Pencil class="size-4" />
                            </Button>
                            <Button
                                variant="ghost"
                                size="sm"
                                class="text-muted-foreground"
                                :aria-label="`Hapus ${item.name}`"
                                @click="remove(item.id)"
                            >
                                <Trash2 class="size-4" />
                            </Button>
                        </div>
                    </div>
                </CardContent>
            </Card>
        </div>

        <Card
            v-if="props.credit_cards.length > 0"
            class="rounded-2xl border-0 shadow-neu-flat"
        >
            <CardContent class="flex flex-col gap-3">
                <h3 class="text-sm font-semibold">
                    Utang kartu kredit
                    <Badge variant="secondary" class="ml-2 text-2xs">
                        otomatis
                    </Badge>
                </h3>

                <div
                    v-for="card in props.credit_cards"
                    :key="card.id"
                    class="flex items-center justify-between gap-3"
                >
                    <span class="text-sm">{{ card.name }}</span>
                    <span class="text-sm font-medium">
                        {{ formatCurrency(card.outstanding) }}
                        <span
                            v-if="card.credit_limit"
                            class="text-xs text-muted-foreground"
                        >
                            dari {{ formatCurrency(card.credit_limit) }}
                        </span>
                    </span>
                </div>
            </CardContent>
        </Card>

        <Dialog v-model:open="isFormOpen">
            <DialogContent class="sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>
                        {{ editingId === null ? 'Tambah item' : 'Ubah item' }}
                    </DialogTitle>
                    <DialogDescription>
                        Isi suku bunga tahunan kalau nilainya tumbuh atau
                        berkurang tiap tahun. Tanggal nilai tidak boleh di masa
                        depan.
                    </DialogDescription>
                </DialogHeader>

                <form class="flex flex-col gap-4" @submit.prevent="submit">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="item-type">Tipe</Label>
                            <Select v-model="form.type">
                                <SelectTrigger id="item-type" class="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="option in props.options.types"
                                        :key="option.value"
                                        :value="option.value"
                                    >
                                        {{ option.label }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <InputError :message="form.errors.type" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="item-subtype">Jenis</Label>
                            <Select v-model="form.subtype">
                                <SelectTrigger id="item-subtype" class="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="subtype in availableSubtypes"
                                        :key="subtype.value"
                                        :value="subtype.value"
                                    >
                                        {{ subtype.label }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <InputError :message="form.errors.subtype" />
                        </div>
                    </div>

                    <div class="grid gap-2">
                        <Label for="item-name">Nama</Label>
                        <Input
                            id="item-name"
                            v-model="form.name"
                            required
                            maxlength="120"
                            placeholder="Contoh: Rumah"
                            class="shadow-neu-inset"
                            :aria-invalid="Boolean(form.errors.name)"
                        />
                        <InputError :message="form.errors.name" />
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="item-value">Nilai (Rp)</Label>
                            <Input
                                id="item-value"
                                v-model="form.value"
                                inputmode="decimal"
                                required
                                class="shadow-neu-inset"
                                :aria-invalid="Boolean(form.errors.value)"
                            />
                            <InputError :message="form.errors.value" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="item-rate">
                                Suku bunga tahunan (%)
                            </Label>
                            <Input
                                id="item-rate"
                                v-model="form.annual_rate"
                                inputmode="decimal"
                                placeholder="Kosongkan kalau tetap"
                                class="shadow-neu-inset"
                                :aria-invalid="Boolean(form.errors.annual_rate)"
                            />
                            <InputError :message="form.errors.annual_rate" />
                        </div>
                    </div>

                    <div class="grid gap-2">
                        <Label for="item-valued-at">Tanggal nilai</Label>
                        <Input
                            id="item-valued-at"
                            v-model="form.valued_at"
                            type="date"
                            required
                            class="shadow-neu-inset"
                            :aria-invalid="Boolean(form.errors.valued_at)"
                        />
                        <InputError :message="form.errors.valued_at" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="item-note">Catatan</Label>
                        <Input
                            id="item-note"
                            v-model="form.note"
                            maxlength="500"
                            class="shadow-neu-inset"
                        />
                        <InputError :message="form.errors.note" />
                    </div>

                    <DialogFooter class="gap-2">
                        <Button
                            v-if="editingId !== null"
                            variant="ghost"
                            type="button"
                            @click="isFormOpen = false"
                        >
                            <X class="size-4" />
                            Batal
                        </Button>
                        <Button
                            type="submit"
                            class="gap-2 sm:w-40"
                            :disabled="form.processing"
                        >
                            <Spinner v-if="form.processing" class="size-4" />
                            Simpan
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
