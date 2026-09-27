<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    BookOpen,
    Building2,
    FolderGit2,
    LayoutGrid,
    Repeat,
    Shapes,
    Tags,
    Wallet,
    ArrowLeftRight,
    HandCoins,
    Target,
    TrendingUp,
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
import { dashboard } from '@/routes';
import { index as accountsIndex } from '@/routes/accounts';
import { index as budgetsIndex } from '@/routes/budgets';
import { index as categoriesIndex } from '@/routes/categories';
import { index as debtsIndex } from '@/routes/debts';
import { index as netWorthIndex } from '@/routes/net-worth';
import { index as recurringRulesIndex } from '@/routes/recurring-rules';
import { index as tagsIndex } from '@/routes/tags';
import { index as transactionsIndex } from '@/routes/transactions';
import { index as workspacesIndex } from '@/routes/workspaces';
import type { NavItem } from '@/types';

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

const footerNavItems: NavItem[] = [
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
];
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
        </SidebarContent>

        <SidebarFooter>
            <NavFooter :items="footerNavItems" />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
