<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ListChecks, Receipt, Upload } from '@lucide/vue';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { cn } from '@/lib/utils';
import { index as importsIndex } from '@/routes/clients/imports';
import { index as rulesIndex } from '@/routes/clients/rules';
import { index as transactionsIndex } from '@/routes/clients/transactions';
import type { ClientSummary } from '@/types';

const props = defineProps<{
    client: ClientSummary;
}>();

const { isCurrentOrParentUrl } = useCurrentUrl();

const tabs = [
    { title: 'Imports', href: importsIndex(props.client.slug), icon: Upload },
    {
        title: 'Transactions',
        href: transactionsIndex(props.client.slug),
        icon: Receipt,
    },
    { title: 'Rules', href: rulesIndex(props.client.slug), icon: ListChecks },
];
</script>

<template>
    <nav class="flex gap-1 border-b" :aria-label="`${client.name} sections`">
        <Link
            v-for="tab in tabs"
            :key="tab.title"
            :href="tab.href"
            :aria-current="isCurrentOrParentUrl(tab.href) ? 'page' : undefined"
            :class="
                cn(
                    '-mb-px flex items-center gap-2 border-b-2 px-3 py-2 text-sm font-medium transition-colors',
                    isCurrentOrParentUrl(tab.href)
                        ? 'border-primary text-foreground'
                        : 'border-transparent text-muted-foreground hover:text-foreground',
                )
            "
        >
            <component :is="tab.icon" class="size-4" />
            {{ tab.title }}
        </Link>
    </nav>
</template>
