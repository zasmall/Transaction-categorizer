<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Activity, Building2, Webhook } from '@lucide/vue';
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
import { index as webhooksIndex } from '@/routes/webhooks';
import type { NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    {
        title: 'Clients',
        href: dashboard(),
        icon: Building2,
    },
    {
        title: 'Webhooks',
        href: webhooksIndex(),
        icon: Webhook,
    },
];

const footerNavItems: NavItem[] = [
    {
        title: 'Queue dashboard',
        href: '/horizon',
        icon: Activity,
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
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavFooter :items="footerNavItems" />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
