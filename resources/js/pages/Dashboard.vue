<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Building2, Landmark, Upload } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { dashboard } from '@/routes';
import { index as importsIndex } from '@/routes/clients/imports';

type ClientCard = {
    name: string;
    slug: string;
    role: 'owner' | 'bookkeeper';
    bank_accounts_count: number;
    imports_count: number;
    uncategorized_count: number;
};

defineProps<{
    clients: ClientCard[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Clients',
                href: dashboard(),
            },
        ],
    },
});
</script>

<template>
    <Head title="Clients" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <Heading
            title="Clients"
            description="Pick a client to import statements and review transactions."
        />

        <p v-if="clients.length === 0" class="text-sm text-muted-foreground">
            You don't have access to any clients yet.
        </p>

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <Link
                v-for="client in clients"
                :key="client.slug"
                :href="importsIndex(client.slug)"
                class="rounded-xl focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
            >
                <Card class="h-full transition-colors hover:border-primary/40">
                    <CardHeader>
                        <div class="flex items-start justify-between gap-2">
                            <CardTitle class="flex items-center gap-2">
                                <Building2
                                    class="size-4 text-muted-foreground"
                                />
                                {{ client.name }}
                            </CardTitle>
                            <Badge variant="outline" class="capitalize">
                                {{ client.role }}
                            </Badge>
                        </div>
                        <CardDescription>
                            {{ client.uncategorized_count }} uncategorized
                            {{
                                client.uncategorized_count === 1
                                    ? 'transaction'
                                    : 'transactions'
                            }}
                        </CardDescription>
                    </CardHeader>
                    <CardContent
                        class="flex gap-6 text-sm text-muted-foreground"
                    >
                        <span class="flex items-center gap-1.5">
                            <Landmark class="size-4" />
                            {{ client.bank_accounts_count }} accounts
                        </span>
                        <span class="flex items-center gap-1.5">
                            <Upload class="size-4" />
                            {{ client.imports_count }} imports
                        </span>
                    </CardContent>
                </Card>
            </Link>
        </div>
    </div>
</template>
