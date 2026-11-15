<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import {
    BookOpen,
    Building2,
    Calculator,
    FileBarChart,
    FolderGit2,
    LayoutGrid,
    Mail,
    Repeat,
    ShieldCheck,
    Shapes,
    Tags,
    UsersRound,
    Wallet,
    ArrowLeftRight,
    HandCoins,
    Target,
    TrendingUp,
    Wrench,
} from '@lucide/vue';
import AppLogo from '@/components/AppLogo.vue';
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useAuth } from '@/composables/useAuth';
import { dashboard } from '@/routes';
import { index as accountsIndex } from '@/routes/accounts';
import { index as budgetsIndex } from '@/routes/budgets';
import { index as categoriesIndex } from '@/routes/categories';
import { index as debtsIndex } from '@/routes/debts';
import { index as exportsIndex } from '@/routes/exports';
import { index as hitunganIndex } from '@/routes/hitungan';
import { index as netWorthIndex } from '@/routes/net-worth';
import { index as recurringRulesIndex } from '@/routes/recurring-rules';
import { index as reportsIndex } from '@/routes/reports';
import { index as tagsIndex } from '@/routes/tags';
import { index as transactionsIndex } from '@/routes/transactions';
import { index as workspacesIndex } from '@/routes/workspaces';
import { dashboard as adminDashboard } from '@/routes/admin';
import { index as adminEmailsIndex } from '@/routes/admin/emails';
import { index as adminUsersIndex } from '@/routes/admin/users';
import type { NavItem } from '@/types';

const { isAdmin } = useAuth();

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
    {
        title: 'Transaksi',
        href: transactionsIndex(),
        icon: ArrowLeftRight,
    },
    {
        title: 'Transaksi berulang',
        href: recurringRulesIndex(),
        icon: Repeat,
    },
    {
        title: 'Akun',
        href: accountsIndex(),
        icon: Wallet,
    },
    {
        title: 'Anggaran',
        href: budgetsIndex(),
        icon: Target,
    },
    {
        title: 'Hitungan',
        href: hitunganIndex(),
        icon: Calculator,
    },
    {
        title: 'Net worth',
        href: netWorthIndex(),
        icon: TrendingUp,
    },
    {
        title: 'Utang & piutang',
        href: debtsIndex(),
        icon: HandCoins,
    },
    {
        title: 'Laporan',
        href: reportsIndex(),
        icon: FileBarChart,
    },
    {
        title: 'Ekspor & Backup',
        href: exportsIndex(),
        icon: Wrench,
    },
    {
        title: 'Kategori',
        href: categoriesIndex(),
        icon: Shapes,
    },
    {
        title: 'Tag',
        href: tagsIndex(),
        icon: Tags,
    },
    {
        title: 'Pilih workspace',
        href: workspacesIndex().url,
        icon: Building2,
    },
];

const adminNavItems: NavItem[] = [
    {
        title: 'Ringkasan admin',
        href: adminDashboard(),
        icon: ShieldCheck,
    },
    {
        title: 'Pengguna',
        href: adminUsersIndex(),
        icon: UsersRound,
    },
    {
        title: 'Daftar email',
        href: adminEmailsIndex(),
        icon: Mail,
    },
];

/**
 * Tautan pengembang (repo & dokumentasi) hanya relevan bagi admin — bagi
 * pengguna biasa keduanya menuju ke luar aplikasi dan tidak ada kaitannya
 * dengan mengelola keuangan.
 */
const footerNavItems = computed<NavItem[]>(() =>
    isAdmin.value
        ? [
              {
                  title: 'Repository',
                  href: 'https://github.com/laravel/vue-starter-kit',
                  icon: FolderGit2,
              },
              {
                  title: 'Documentation',
                  href: 'https://laravel.com/docs/starter-kits#vue',
                  icon: BookOpen,
              },
          ]
        : [],
);
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" label="Platform" />
            <NavMain v-if="isAdmin" :items="adminNavItems" label="Admin" />
        </SidebarContent>

        <SidebarFooter>
            <NavFooter
                v-if="footerNavItems.length > 0"
                :items="footerNavItems"
            />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
