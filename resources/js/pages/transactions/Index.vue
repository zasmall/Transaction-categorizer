<script setup lang="ts">
import { Head, Link, setLayoutProps } from '@inertiajs/vue3';
import { computed } from 'vue';
import ClientNav from '@/components/clients/ClientNav.vue';
import Heading from '@/components/Heading.vue';
import CategorizeCell from '@/components/transactions/CategorizeCell.vue';
import RuleSuggestionBanner from '@/components/transactions/RuleSuggestionBanner.vue';
import { Button } from '@/components/ui/button';
import { formatDate } from '@/lib/format';
import { dashboard } from '@/routes';
import { useCategorize } from '@/composables/useCategorize';
import { index } from '@/routes/clients/transactions';
import type {
    AccountOption,
    CategorizationStatus,
    ClientSummary,
    Paginated,
    TransactionRow,
} from '@/types';

const props = defineProps<{
    client: ClientSummary;
    transactions: Paginated<TransactionRow>;
    status: CategorizationStatus | null;
    counts: Partial<Record<CategorizationStatus, number>>;
    accounts: AccountOption[];
}>();

setLayoutProps({
    breadcrumbs: [
        { title: 'Clients', href: dashboard() },
        { title: props.client.name, href: index(props.client.slug) },
        { title: 'Transactions', href: index(props.client.slug) },
    ],
});

const filters = computed(() => {
    const total = Object.values(props.counts).reduce((sum, n) => sum + n, 0);

    return [
        { value: null, label: 'All', count: total },
        {
            value: 'uncategorized' as const,
            label: 'Uncategorized',
            count: props.counts.uncategorized ?? 0,
        },
        {
            value: 'suggested' as const,
            label: 'Suggested',
            count: props.counts.suggested ?? 0,
        },
        {
            value: 'approved' as const,
            label: 'Approved',
            count: props.counts.approved ?? 0,
        },
    ];
});

const { saving, categorize, approveSuggestion } = useCategorize(
    props.client.slug,
);
</script>

<template>
    <Head :title="`Transactions · ${client.name}`" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <Heading
            :title="client.name"
            description="Pick an account for anything rules didn't catch. Each decision is recorded with who or what made it."
        />

        <ClientNav :client="client" />

        <RuleSuggestionBanner :client="client" />

        <div
            class="flex flex-wrap gap-1"
            role="group"
            aria-label="Filter by status"
        >
            <Button
                v-for="filter in filters"
                :key="filter.label"
                size="sm"
                :variant="status === filter.value ? 'secondary' : 'ghost'"
                :aria-pressed="status === filter.value"
                as-child
            >
                <Link
                    :href="
                        index(client.slug, {
                            query: filter.value ? { status: filter.value } : {},
                        })
                    "
                    preserve-scroll
                >
                    {{ filter.label }}
                    <span class="text-muted-foreground tabular-nums">{{
                        filter.count
                    }}</span>
                </Link>
            </Button>
        </div>

        <p
            v-if="transactions.data.length === 0"
            class="text-sm text-muted-foreground"
        >
            No transactions here.
        </p>

        <div v-else class="overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th class="px-4 py-2 font-medium">Date</th>
                        <th class="px-4 py-2 font-medium">Payee</th>
                        <th class="px-4 py-2 text-right font-medium">Amount</th>
                        <th class="w-80 px-4 py-2 font-medium">Account</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr
                        v-for="transaction in transactions.data"
                        :key="transaction.id"
                        class="align-top"
                    >
                        <td class="px-4 py-3 whitespace-nowrap">
                            {{ formatDate(transaction.posted_on) }}
                        </td>
                        <td class="px-4 py-3">
                            <p class="font-medium">{{ transaction.payee }}</p>
                            <p class="text-xs text-muted-foreground">
                                {{ transaction.description }} ·
                                {{ transaction.bank_account }}
                            </p>
                        </td>
                        <td
                            class="px-4 py-3 text-right whitespace-nowrap tabular-nums"
                            :class="{
                                'text-emerald-700 dark:text-emerald-400':
                                    transaction.amount_cents > 0,
                            }"
                        >
                            {{ transaction.amount }}
                        </td>
                        <td class="px-4 py-3">
                            <CategorizeCell
                                :transaction="transaction"
                                :accounts="accounts"
                                :busy="saving === transaction.id"
                                @categorize="
                                    (id) => categorize(transaction, id)
                                "
                                @approve="approveSuggestion(transaction)"
                            />
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div
            v-if="transactions.last_page > 1"
            class="flex items-center justify-between text-sm text-muted-foreground"
        >
            <span
                >{{ transactions.from }}–{{ transactions.to }} of
                {{ transactions.total }}</span
            >
            <div class="flex gap-2">
                <template
                    v-for="link in [
                        { label: 'Previous', url: transactions.prev_page_url },
                        { label: 'Next', url: transactions.next_page_url },
                    ]"
                    :key="link.label"
                >
                    <Button
                        v-if="link.url"
                        variant="outline"
                        size="sm"
                        as-child
                    >
                        <Link :href="link.url">{{ link.label }}</Link>
                    </Button>
                    <Button v-else variant="outline" size="sm" disabled>
                        {{ link.label }}
                    </Button>
                </template>
            </div>
        </div>
    </div>
</template>
