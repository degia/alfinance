<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import {
    BarController,
    BarElement,
    CategoryScale,
    Chart,
    DoughnutController,
    ArcElement,
    Filler,
    Legend,
    LineController,
    LineElement,
    LinearScale,
    PointElement,
    Tooltip,
} from 'chart.js';
import { Head, router } from '@inertiajs/vue3';
import { ArrowDownRight, ArrowUpRight, HeartPulse, Minus } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { useAppearance } from '@/composables/useAppearance';
import { chartAnimation, chartToken } from '@/lib/chart';
import { formatCurrency, formatDate, toNumber } from '@/lib/format';
import { dashboard } from '@/routes';
import type {
    DashboardChange,
    DashboardKpi,
    DashboardProps,
    FinancialHealthMetric,
} from '@/types';

Chart.register(
    BarController,
    BarElement,
    CategoryScale,
    DoughnutController,
    ArcElement,
    Filler,
    Legend,
    LineController,
    LineElement,
    LinearScale,
    PointElement,
    Tooltip,
);

defineOptions({
    layout: {
        title: 'Dashboard',
        description: 'Ringkasan kondisi keuangan bulan ini',
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: dashboard(),
            },
        ],
    },
});

const props = defineProps<DashboardProps>();

/*
|--------------------------------------------------------------------------
| Periode
|--------------------------------------------------------------------------
| Bulan yang ditampilkan ikut query string, jadi pilihan user bisa di-share
| dan tombol "kembali" tetap masuk akal. Server yang memvalidasi formatnya.
*/
const monthInput = ref(props.month);
const monthOptions = computed(() => buildMonthOptions(props.month));

function buildMonthOptions(
    current: string,
): { value: string; label: string }[] {
    const [year, month] = current.split('-').map(Number);
    const options: { value: string; label: string }[] = [];
    const cursor = new Date(Date.UTC(year, month - 1, 1));

    for (let index = 0; index < 24; index++) {
        const value = cursor.toISOString().slice(0, 7);
        const label = new Intl.DateTimeFormat('id-ID', {
            month: 'long',
            year: 'numeric',
            timeZone: 'UTC',
        }).format(cursor);

        options.push({ value, label });

        cursor.setUTCMonth(cursor.getUTCMonth() - 1);
    }

    return options;
}

function applyMonth(month: string): void {
    if (month === props.month) {
        return;
    }

    router.get(dashboard({ query: { month } }).url, {
        preserveState: true,
        preserveScroll: true,
    });
}

function applyTrend(months: number): void {
    router.get(
        dashboard({
            query: { month: props.month, trend: months },
        }).url,
        { preserveState: true, preserveScroll: true },
    );
}

/*
|--------------------------------------------------------------------------
| Kartu KPI
|--------------------------------------------------------------------------
| Perubahan terhadap bulan lalu ditampilkan lengkap dengan arah: "naik" tidak
| selalu kabar baik (pengeluaran naik adalah kabar buruk), jadi ikonnya
| diturunkan per kartu, bukan dari arahnya saja.
*/
type KpiCard = {
    key: string;
    label: string;
    value: string;
    change: DashboardChange | null;
    goodWhenUp: boolean;
    hint: string;
};

const kpiCards = computed<KpiCard[]>(() => {
    const kpi: DashboardKpi = props.kpi;

    return [
        {
            key: 'income',
            label: 'Pemasukan',
            value: kpi.income,
            change: kpi.income_change,
            goodWhenUp: true,
            hint: 'Total kas masuk bulan ini',
        },
        {
            key: 'expense',
            label: 'Pengeluaran',
            value: kpi.expense,
            change: kpi.expense_change,
            goodWhenUp: false,
            hint: 'Total kas keluar bulan ini',
        },
        {
            key: 'net_cash_flow',
            label: 'Arus kas bersih',
            value: kpi.net_cash_flow,
            change: kpi.net_cash_flow_change,
            goodWhenUp: true,
            hint: 'Pemasukan dikurangi pengeluaran',
        },
    ];
});

const netCashFlowTone = computed(() => {
    const value = toNumber(props.kpi.net_cash_flow) ?? 0;

    if (value > 0) {
        return 'text-emerald-600 dark:text-emerald-400';
    }

    if (value < 0) {
        return 'text-rose-600 dark:text-rose-400';
    }

    return 'text-muted-foreground';
});

function changeTone(card: KpiCard): string {
    if (card.change === null || card.change.direction === 'flat') {
        return 'text-muted-foreground';
    }

    const isUp = card.change.direction === 'up';
    const isGood = isUp === card.goodWhenUp;

    return isGood
        ? 'text-emerald-600 dark:text-emerald-400'
        : 'text-rose-600 dark:text-rose-400';
}

function changeLabel(card: KpiCard): string {
    if (card.change === null) {
        return '';
    }

    if (card.change.direction === 'flat') {
        return 'Sama seperti bulan lalu';
    }

    return `${formatCurrency(card.change.amount)} dari bulan lalu`;
}

/*
|--------------------------------------------------------------------------
| Tren arus kas
|--------------------------------------------------------------------------
| Bar, bukan line: yang dibandingkan antar bulan adalah total per bulan, dan
| batang membuat bulan tanpa transaksi tetap terlihat sebagai kolom kosong
| alih-alih garis yang tampak kontinu.
*/
const trendCanvas = ref<HTMLCanvasElement | null>(null);
let trendChart: Chart | null = null;

function renderTrendChart(): void {
    if (trendCanvas.value === null) {
        return;
    }

    trendChart?.destroy();

    const points = props.cash_flow.points;

    trendChart = new Chart(trendCanvas.value, {
        type: 'bar',
        data: {
            labels: points.map((point) => point.label),
            datasets: [
                {
                    label: 'Pemasukan',
                    data: points.map((point) => toNumber(point.income) ?? 0),
                    backgroundColor: chartToken('income'),
                    borderRadius: 4,
                },
                {
                    label: 'Pengeluaran',
                    data: points.map((point) => toNumber(point.expense) ?? 0),
                    backgroundColor: chartToken('expense'),
                    borderRadius: 4,
                },
            ],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            animation: chartAnimation(),
            plugins: {
                legend: { display: true, position: 'bottom' },
                tooltip: {
                    callbacks: {
                        label: (context) =>
                            formatCurrency(context.parsed.y ?? 0),
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

/*
|--------------------------------------------------------------------------
| Tren harian
|--------------------------------------------------------------------------
| Line, bukan bar: yang di sini adalah urutan hari dalam satu bulan, bukan
| perbandingan antar bulan. Bar tetap dipakai di `renderTrendChart()` karena
| total per bulan lebih tepat dibaca sebagai kolom; line lebih tepat untuk
| sebaran per hari, dan hari tanpa transaksi terlihat datar, bukan sebagai
| kolom kosong yang memenuhi setengah kartu.
|
| Pembacaannya dari tabel agregat harian (`dashboard_daily_snapshots`), bukan
| menjumlahkan transaksi per tanggal di halaman ini.
*/
const dailyCanvas = ref<HTMLCanvasElement | null>(null);
let dailyChart: Chart | null = null;

/*
| Empty state: kartu menampilkan pesan, bukan canvas dengan dua garis datar.
| "Belum ada rekap" dan "pengeluaran benar-benar nol" terlihat sama kalau
| hanya andalkan grafik, padahal maknanya berbeda.
*/
const dailyHasData = computed(() =>
    props.daily_cash_flow.points.some((point) => point.has_data),
);

function renderDailyChart(): void {
    if (dailyCanvas.value === null) {
        return;
    }

    dailyChart?.destroy();

    const points = props.daily_cash_flow.points;

    dailyChart = new Chart(dailyCanvas.value, {
        type: 'line',
        data: {
            labels: points.map((point) => point.label),
            datasets: [
                {
                    label: 'Pemasukan',
                    data: points.map((point) => toNumber(point.income) ?? 0),
                    borderColor: chartToken('income'),
                    backgroundColor: chartToken('incomeSoft'),
                    borderWidth: 2,
                    fill: true,
                },
                {
                    label: 'Pengeluaran',
                    data: points.map((point) => toNumber(point.expense) ?? 0),
                    borderColor: chartToken('expense'),
                    backgroundColor: chartToken('expenseSoft'),
                    borderWidth: 2,
                    fill: true,
                },
            ],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            animation: chartAnimation(),
            plugins: {
                legend: { display: true, position: 'bottom' },
                tooltip: {
                    callbacks: {
                        // Sumbu X sengaja hanya menampilkan angka hari, jadi
                        // tanggal lengkap ditambahkan di judul tooltip.
                        title: (items) =>
                            items.length > 0
                                ? (points[items[0].dataIndex]?.tooltip_label ??
                                  '')
                                : '',
                        label: (context) =>
                            formatCurrency(context.parsed.y ?? 0),
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
                x: {
                    // 28–31 label dalam kartu yang lebarnya terbatas: Chart.js
                    // boleh melewati sebagian supaya tanggal tetap terbaca,
                    // bukan menumpuk.
                    grid: { display: false },
                    ticks: {
                        autoSkip: true,
                        maxRotation: 0,
                        maxTicksLimit: 10,
                    },
                },
            },
        },
    });
}

/*
|--------------------------------------------------------------------------
| Komposisi pengeluaran
|--------------------------------------------------------------------------
| Donut untuk lima kategori teratas; sisanya digabung jadi "Lainnya" supaya
| irisan tipis tidak jadi legenda yang lebih besar daripada datanya.
*/
const MAX_SLICES = 5;

const donutSlices = computed(() => {
    const items = props.expense_breakdown.items.slice(0, MAX_SLICES);
    const rest = props.expense_breakdown.items.slice(MAX_SLICES);
    const restTotal = rest.reduce(
        (total, item) => total + (toNumber(item.amount) ?? 0),
        0,
    );

    if (restTotal <= 0) {
        return items.map((item) => ({
            label: item.name,
            amount: toNumber(item.amount) ?? 0,
            color: item.color,
        }));
    }

    return [
        ...items.map((item) => ({
            label: item.name,
            amount: toNumber(item.amount) ?? 0,
            color: item.color,
        })),
        {
            label: 'Lainnya',
            amount: restTotal,
            color: chartToken('neutral'),
        },
    ];
});

const breakdownCanvas = ref<HTMLCanvasElement | null>(null);
let breakdownChart: Chart | null = null;

function renderBreakdownChart(): void {
    if (breakdownCanvas.value === null) {
        return;
    }

    breakdownChart?.destroy();

    const slices = donutSlices.value;

    if (slices.length === 0) {
        breakdownChart = null;

        return;
    }

    breakdownChart = new Chart(breakdownCanvas.value, {
        type: 'doughnut',
        data: {
            labels: slices.map((slice) => slice.label),
            datasets: [
                {
                    data: slices.map((slice) => slice.amount),
                    backgroundColor: slices.map((slice) => slice.color),
                    borderWidth: 0,
                },
            ],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '65%',
            animation: chartAnimation(),
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: (context) => {
                            const total = context.dataset.data.reduce(
                                (sum, value) => sum + (value ?? 0),
                                0,
                            );
                            const percent =
                                total > 0
                                    ? ((context.parsed / total) * 100).toFixed(
                                          1,
                                      )
                                    : '0';

                            return `${context.label}: ${formatCurrency(context.parsed)} (${percent}%)`;
                        },
                    },
                },
            },
        },
    });
}

/*
|--------------------------------------------------------------------------
| Skor kesehatan
|--------------------------------------------------------------------------
| Tiga metrik ditampilkan lengkap dengan targetnya, karena "skor 72" tanpa
| target tidak bisa ditindaklanjuti.
*/
const healthMetrics = computed<FinancialHealthMetric[]>(
    () => props.health.metrics,
);

function metricValue(metric: FinancialHealthMetric): string {
    if (metric.value === null) {
        return 'Belum bisa dihitung';
    }

    return metric.unit === 'percent'
        ? `${metric.value}%`
        : `${metric.value} bulan`;
}

function metricTarget(metric: FinancialHealthMetric): string {
    return metric.unit === 'percent'
        ? `target ${metric.target}%`
        : `target ${metric.target} bulan`;
}

onMounted(() => {
    renderTrendChart();
    renderDailyChart();
    renderBreakdownChart();
});

onBeforeUnmount(() => {
    trendChart?.destroy();
    dailyChart?.destroy();
    breakdownChart?.destroy();
});

watch(
    () => props.cash_flow.points,
    () => renderTrendChart(),
    { deep: true },
);

watch(
    () => props.daily_cash_flow.points,
    () => renderDailyChart(),
    // `post` karena `<canvas>` ada di balik `v-if`: bulan yang tadinya kosong
    // baru memasang canvas setelah patch DOM, jadi watcher `pre` (default)
    // akan menggambar ke canvas yang masih null.
    { deep: true, flush: 'post' },
);

watch(donutSlices, () => renderBreakdownChart(), { deep: true });

/*
| Chart digambar ke canvas, jadi warna token harus dibaca ulang saat tema
| berubah; tanpa ini chart tetap memakai warna light di dark mode.
*/
const { resolvedAppearance } = useAppearance();

watch(resolvedAppearance, () => {
    renderTrendChart();
    renderDailyChart();
    renderBreakdownChart();
});
</script>

<template>
    <Head title="Dashboard" />

    <div class="flex flex-col gap-6">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <Heading
                :spaced="false"
                title="Dashboard"
                description="Ringkasan kondisi keuangan. Semua angka dibaca dari snapshot bulanan, bukan dihitung ulang dari transaksi."
            />

            <div class="flex flex-wrap items-center gap-2">
                <select
                    v-model="monthInput"
                    aria-label="Pilih bulan"
                    class="neu-inset rounded-lg px-3 py-2 text-sm"
                    @change="applyMonth(monthInput)"
                >
                    <option
                        v-for="option in monthOptions"
                        :key="option.value"
                        :value="option.value"
                    >
                        {{ option.label }}
                    </option>
                </select>

                <div class="flex items-center gap-1">
                    <Button
                        v-for="option in props.trend_options"
                        :key="option"
                        size="sm"
                        :variant="
                            option === props.trend_months ? 'default' : 'ghost'
                        "
                        @click="applyTrend(option)"
                    >
                        {{ option }} bulan
                    </Button>
                </div>
            </div>
        </div>

        <!-- Angka belum final: tampilkan skeleton, bukan nol -->
        <div
            v-if="props.kpi.is_pending"
            class="flex items-center gap-2 rounded-xl border border-dashed p-3 text-sm text-muted-foreground"
        >
            <Skeleton class="size-4" />
            Ringkasan {{ props.kpi.month_label }} sedang dihitung. Angka di
            bawah akan terisi sendiri dalam hitungan detik — halaman ini sengaja
            tidak menjumlahkan transaksi secara langsung.
        </div>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <Card
                v-for="card in kpiCards"
                :key="card.key"
                class="rounded-2xl border-0 shadow-neu-flat"
            >
                <CardContent>
                    <p
                        class="text-xs tracking-wide text-muted-foreground uppercase"
                    >
                        {{ card.label }}
                    </p>
                    <p
                        class="mt-1 text-xl font-semibold"
                        :class="
                            card.key === 'net_cash_flow' ? netCashFlowTone : ''
                        "
                    >
                        {{ formatCurrency(card.value) }}
                    </p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        {{ card.hint }}
                    </p>
                    <p
                        v-if="card.change !== null"
                        class="mt-2 flex items-center gap-1 text-xs font-medium"
                        :class="changeTone(card)"
                    >
                        <ArrowUpRight
                            v-if="card.change.direction === 'up'"
                            class="size-3.5"
                        />
                        <ArrowDownRight
                            v-else-if="card.change.direction === 'down'"
                            class="size-3.5"
                        />
                        <Minus v-else class="size-3.5" />
                        {{ changeLabel(card) }}
                    </p>
                </CardContent>
            </Card>

            <Card class="rounded-2xl border-0 shadow-neu-flat">
                <CardContent>
                    <p
                        class="text-xs tracking-wide text-muted-foreground uppercase"
                    >
                        Saldo total
                    </p>
                    <p class="mt-1 text-xl font-semibold">
                        {{ formatCurrency(props.kpi.total_balance) }}
                    </p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        Akun aktif non-liabilitas ·
                        {{ props.kpi.transaction_count }} transaksi bulan ini
                    </p>
                    <p
                        v-if="(toNumber(props.kpi.total_transfer) ?? 0) > 0"
                        class="mt-2 text-xs text-muted-foreground"
                    >
                        Termasuk transfer internal
                        {{ formatCurrency(props.kpi.total_transfer) }}
                    </p>
                </CardContent>
            </Card>
        </div>

        <Card class="rounded-2xl border-0 shadow-neu-flat">
            <CardContent class="flex flex-col gap-4">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h3 class="text-sm font-semibold">
                            Pemasukan &amp; pengeluaran harian
                        </h3>
                        <p class="text-xs text-muted-foreground">
                            Per hari di {{ props.kpi.month_label }} — hari tanpa
                            transaksi terlihat datar, bukan berlubang
                        </p>
                    </div>

                    <p class="text-xs text-muted-foreground">
                        {{ formatCurrency(props.daily_cash_flow.total_income) }}
                        masuk ·
                        {{
                            formatCurrency(props.daily_cash_flow.total_expense)
                        }}
                        keluar
                    </p>
                </div>

                <div v-if="dailyHasData" class="h-64">
                    <canvas ref="dailyCanvas" />
                </div>

                <p
                    v-else
                    class="neu-inset rounded-lg p-4 text-center text-xs text-muted-foreground"
                >
                    Belum ada rekap harian untuk
                    {{ props.kpi.month_label }}. Rekap harian dihitung otomatis
                    setiap kali ada transaksi yang berubah — halaman ini tidak
                    menjumlahkan transaksi per tanggal secara langsung.
                </p>
            </CardContent>
        </Card>

        <div class="grid gap-4 lg:grid-cols-3">
            <Card class="rounded-2xl border-0 shadow-neu-flat lg:col-span-2">
                <CardContent class="flex flex-col gap-4">
                    <div
                        class="flex flex-wrap items-center justify-between gap-2"
                    >
                        <h3 class="text-sm font-semibold">Arus kas bulanan</h3>
                        <p class="text-xs text-muted-foreground">
                            {{ formatCurrency(props.cash_flow.total_income) }}
                            masuk ·
                            {{ formatCurrency(props.cash_flow.total_expense) }}
                            keluar
                        </p>
                    </div>

                    <div class="h-64">
                        <canvas ref="trendCanvas" />
                    </div>

                    <p
                        v-if="
                            !props.cash_flow.points.some(
                                (point) => point.has_data,
                            )
                        "
                        class="text-xs text-muted-foreground"
                    >
                        Belum ada snapshot untuk {{ props.kpi.month_label }} dan
                        {{ props.trend_months }} bulan sebelumnya. Snapshot
                        dibuat otomatis setiap kali ada transaksi atau anggaran
                        yang berubah.
                    </p>
                </CardContent>
            </Card>

            <Card class="rounded-2xl border-0 shadow-neu-flat">
                <CardContent class="flex flex-col gap-4">
                    <div class="flex items-center justify-between gap-2">
                        <h3 class="text-sm font-semibold">
                            Komposisi pengeluaran
                        </h3>
                        <span class="text-xs text-muted-foreground">
                            {{ formatCurrency(props.expense_breakdown.total) }}
                        </span>
                    </div>

                    <div v-if="props.expense_breakdown.has_data" class="h-40">
                        <canvas ref="breakdownCanvas" />
                    </div>

                    <p
                        v-else
                        class="neu-inset rounded-lg p-4 text-center text-xs text-muted-foreground"
                    >
                        Belum ada pengeluaran tercatat di
                        {{ props.kpi.month_label }}.
                    </p>

                    <ul
                        v-if="props.expense_breakdown.has_data"
                        class="flex flex-col gap-2"
                    >
                        <li
                            v-for="item in props.expense_breakdown.items.slice(
                                0,
                                MAX_SLICES,
                            )"
                            :key="item.category_id"
                            class="flex items-center justify-between gap-2 text-sm"
                        >
                            <span class="flex min-w-0 items-center gap-2">
                                <span
                                    class="size-2.5 shrink-0 rounded-full"
                                    :style="{
                                        backgroundColor: item.color,
                                    }"
                                />
                                <span class="truncate">{{ item.name }}</span>
                            </span>
                            <span
                                class="shrink-0 text-muted-foreground tabular-nums"
                            >
                                {{ formatCurrency(item.amount) }}
                            </span>
                        </li>
                    </ul>
                </CardContent>
            </Card>
        </div>

        <div class="grid gap-4 lg:grid-cols-3">
            <Card class="rounded-2xl border-0 shadow-neu-flat lg:col-span-1">
                <CardContent class="flex flex-col gap-4">
                    <div class="flex items-center justify-between gap-2">
                        <h3
                            class="flex items-center gap-2 text-sm font-semibold"
                        >
                            <HeartPulse class="size-4" />
                            Kesehatan keuangan
                        </h3>
                        <Badge
                            :class="props.health.label_tone"
                            variant="secondary"
                        >
                            {{ props.health.label_text }}
                        </Badge>
                    </div>

                    <div class="flex items-end gap-2">
                        <span class="text-4xl font-semibold tabular-nums">
                            {{ props.health.score }}
                        </span>
                        <span class="pb-1 text-xs text-muted-foreground">
                            / 100
                        </span>
                    </div>

                    <div
                        class="neu-inset h-2 overflow-hidden rounded-full"
                        role="progressbar"
                        :aria-valuenow="props.health.score"
                        aria-valuemin="0"
                        aria-valuemax="100"
                        aria-label="Skor kesehatan keuangan"
                    >
                        <div
                            class="neu-progress h-full rounded-full bg-primary"
                            :style="{ width: `${props.health.score}%` }"
                        />
                    </div>

                    <p
                        v-if="!props.health.is_complete"
                        class="text-xs text-muted-foreground"
                    >
                        Sebagian metrik belum bisa dihitung, jadi skor ini belum
                        final.
                    </p>

                    <dl class="flex flex-col gap-2 text-sm">
                        <div
                            v-for="metric in healthMetrics"
                            :key="metric.key"
                            class="flex items-center justify-between gap-2"
                        >
                            <dt class="text-muted-foreground">
                                {{ metric.label }}
                            </dt>
                            <dd class="text-right">
                                <span
                                    class="tabular-nums"
                                    :class="
                                        metric.value === null
                                            ? 'text-muted-foreground'
                                            : metric.is_healthy
                                              ? 'text-emerald-600 dark:text-emerald-400'
                                              : 'text-amber-600 dark:text-amber-400'
                                    "
                                >
                                    {{ metricValue(metric) }}
                                </span>
                                <span
                                    class="block text-xs text-muted-foreground"
                                >
                                    {{ metricTarget(metric) }}
                                </span>
                            </dd>
                        </div>
                    </dl>

                    <ul
                        v-if="props.health.recommendations.length > 0"
                        class="flex flex-col gap-2 border-t pt-3 text-xs"
                    >
                        <li
                            v-for="item in props.health.recommendations"
                            :key="item.metric"
                            class="text-muted-foreground"
                        >
                            {{ item.message }}
                        </li>
                    </ul>
                </CardContent>
            </Card>

            <Card class="rounded-2xl border-0 shadow-neu-flat lg:col-span-2">
                <CardContent class="flex flex-col gap-3">
                    <h3 class="text-sm font-semibold">Transaksi terakhir</h3>

                    <p
                        v-if="props.recent_transactions.length === 0"
                        class="neu-inset rounded-lg p-6 text-center text-sm text-muted-foreground"
                    >
                        Belum ada transaksi di workspace ini.
                    </p>

                    <ul v-else class="flex flex-col gap-1">
                        <li
                            v-for="transaction in props.recent_transactions"
                            :key="transaction.id"
                            class="neu-inset flex items-center justify-between gap-3 rounded-lg px-3 py-2"
                        >
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium">
                                    {{
                                        transaction.category?.name ??
                                        transaction.account?.name ??
                                        'Tanpa kategori'
                                    }}
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    {{ formatDate(transaction.occurred_at) }}
                                    · {{ transaction.account?.name ?? '-' }}
                                    <span v-if="transaction.is_pending">
                                        · sudah recurring
                                    </span>
                                </p>
                            </div>

                            <div class="flex shrink-0 items-center gap-2">
                                <Badge
                                    v-if="transaction.is_transfer"
                                    variant="outline"
                                    class="text-2xs"
                                >
                                    transfer
                                </Badge>
                                <span
                                    class="text-sm font-semibold tabular-nums"
                                    :class="
                                        (toNumber(transaction.signed_amount) ??
                                            0) < 0
                                            ? 'text-rose-600 dark:text-rose-400'
                                            : 'text-emerald-600 dark:text-emerald-400'
                                    "
                                >
                                    {{
                                        formatCurrency(
                                            transaction.signed_amount,
                                        )
                                    }}
                                </span>
                            </div>
                        </li>
                    </ul>
                </CardContent>
            </Card>
        </div>
    </div>
</template>
