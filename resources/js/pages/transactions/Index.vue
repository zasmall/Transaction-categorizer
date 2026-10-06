<script setup lang="ts">
import { Head, Link, router, setLayoutProps, usePage } from '@inertiajs/vue3';
import { Check, Sparkles, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import AccountSelect from '@/components/AccountSelect.vue';
import ClientNav from '@/components/clients/ClientNav.vue';
import Heading from '@/components/Heading.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatDate } from '@/lib/format';
import { dashboard } from '@/routes';
import { create as createRule } from '@/routes/clients/rules';
import { approve, index, update } from '@/routes/clients/transactions';
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

const page = usePage();
const suggestion = computed(() => page.flash.ruleSuggestion);
const dismissed = ref<string | null>(null);

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

const saving = ref<number | null>(null);

function approveSuggestion(transaction: TransactionRow) {
    router.post(
        approve.url([props.client.slug, transaction.id]),
        {},
        {
            preserveScroll: true,
            onStart: () => (saving.value = transaction.id),
            onFinish: () => (saving.value = null),
        },
    );
}

function categorize(transaction: TransactionRow, accountId: number | null) {
    if (accountId === null || accountId === transaction.account_id) {
        return;
    }

    dismissed.value = null;
    router.patch(
        update.url([props.client.slug, transaction.id]),
        { account_id: accountId },
        {
            preserveScroll: true,
            onStart: () => (saving.value = transaction.id),
            onFinish: () => (saving.value = null),
        },
    );
}
</script>

<template>
    <Head :title="`Transactions · ${client.name}`" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <Heading
            :title="client.name"
            description="Pick an account for anything rules didn't catch. Each decision is recorded with who or what made it."
        />

        <ClientNav :client="client" />

        <Alert
            v-if="suggestion && dismissed !== suggestion.payee"
            class="border-primary/40"
        >
            <Sparkles />
            <AlertTitle>Turn this into a rule?</AlertTitle>
            <AlertDescription>
                <p>
                    Always put payees containing
                    <strong>“{{ suggestion.payee }}”</strong> in
                    <strong>{{ suggestion.account }}</strong
                    >.
                    <template v-if="suggestion.similar > 0">
                        It would also categorize {{ suggestion.similar }} other
                        {{
                            suggestion.similar === 1
                                ? 'transaction'
                                : 'transactions'
                        }}
                        right now.
                    </template>
                </p>
                <div class="mt-3 flex gap-2">
                    <Button size="sm" as-child>
                        <Link
                            :href="
                                createRule(client.slug, {
                                    query: {
                                        payee: suggestion.payee,
                                        account_id: suggestion.account_id,
                                    },
                                })
                            "
                        >
                            Create rule
                        </Link>
                    </Button>
                    <Button
                        size="sm"
                        variant="ghost"
                        @click="dismissed = suggestion.payee"
                    >
                        <X /> Not now
                    </Button>
                </div>
            </AlertDescription>
        </Alert>

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
                            <AccountSelect
                                :model-value="transaction.account_id"
                                :accounts="accounts"
                                :disabled="saving === transaction.id"
                                :aria-label="`Account for ${transaction.payee}`"
                                @update:model-value="
                                    (id) => categorize(transaction, id)
                                "
                            />
                            <div
                                class="mt-1 flex items-center gap-1.5 text-xs text-muted-foreground"
                            >
                                <Badge
                                    v-if="
                                        transaction.status === 'uncategorized'
                                    "
                                    variant="outline"
                                    >Uncategorized</Badge
                                >
                                <Badge
                                    v-else-if="
                                        transaction.status === 'suggested'
                                    "
                                    class="border-transparent bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300"
                                    >Needs review</Badge
                                >
                                {{ transaction.explanation }}
                                <Button
                                    v-if="transaction.status === 'suggested'"
                                    size="sm"
                                    variant="outline"
                                    class="ml-auto h-6 px-2 text-xs"
                                    :disabled="saving === transaction.id"
                                    @click="approveSuggestion(transaction)"
                                >
                                    <Check /> Approve
                                </Button>
                            </div>
                            <p
                                v-if="transaction.ai_reason"
                                class="mt-1 text-xs text-muted-foreground italic"
                            >
                                “{{ transaction.ai_reason }}”
                            </p>
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
