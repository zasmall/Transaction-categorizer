<script setup lang="ts">
import { Head, Link, router, setLayoutProps } from '@inertiajs/vue3';
import { CheckCheck, Lightbulb } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import ClientNav from '@/components/clients/ClientNav.vue';
import Heading from '@/components/Heading.vue';
import CategorizeCell from '@/components/transactions/CategorizeCell.vue';
import RuleSuggestionBanner from '@/components/transactions/RuleSuggestionBanner.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useCategorize } from '@/composables/useCategorize';
import { formatDate } from '@/lib/format';
import { dashboard } from '@/routes';
import { approve, index } from '@/routes/clients/review';
import { index as rulesIndex } from '@/routes/clients/rules';
import type {
    AccountOption,
    ClientSummary,
    Paginated,
    TransactionRow,
} from '@/types';

type QueueRow = TransactionRow & { confidence: number | null };

const props = defineProps<{
    client: ClientSummary;
    queue: Paginated<QueueRow>;
    accounts: AccountOption[];
    learnedRules: number;
    maxBulk: number;
}>();

setLayoutProps({
    breadcrumbs: [
        { title: 'Clients', href: dashboard() },
        { title: props.client.name, href: index(props.client.slug) },
        { title: 'Review', href: index(props.client.slug) },
    ],
});

const { saving, categorize, approveSuggestion } = useCategorize(
    props.client.slug,
);

const selected = ref<number[]>([]);
const approving = ref(false);

const suggestedIds = computed(() =>
    props.queue.data
        .filter((row) => row.status === 'suggested')
        .map((row) => row.id),
);
const allSelected = computed(
    () =>
        suggestedIds.value.length > 0 &&
        suggestedIds.value.every((id) => selected.value.includes(id)),
);

// Drop selections that left the queue after a reload.
watch(suggestedIds, (ids) => {
    selected.value = selected.value.filter((id) => ids.includes(id));
});

function toggle(id: number, checked: boolean | 'indeterminate') {
    selected.value = checked
        ? [...new Set([...selected.value, id])]
        : selected.value.filter((selectedId) => selectedId !== id);
}

function toggleAll(checked: boolean | 'indeterminate') {
    selected.value = checked ? [...suggestedIds.value] : [];
}

function approveSelected() {
    router.post(
        approve.url(props.client.slug),
        { transaction_ids: selected.value },
        {
            preserveScroll: true,
            onStart: () => (approving.value = true),
            onFinish: () => (approving.value = false),
            onSuccess: () => (selected.value = []),
        },
    );
}

function confidenceClass(confidence: number | null): string {
    if (confidence === null) {
        return 'text-muted-foreground';
    }

    return confidence >= 80
        ? 'text-emerald-700 dark:text-emerald-400'
        : confidence >= 50
          ? 'text-amber-700 dark:text-amber-400'
          : 'text-red-700 dark:text-red-400';
}
</script>

<template>
    <Head :title="`Review · ${client.name}`" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <Heading
            :title="client.name"
            description="Everything that still needs a person, least certain first: transactions nothing could place, then AI suggestions from lowest to highest confidence."
        />

        <ClientNav :client="client" />

        <RuleSuggestionBanner :client="client" />

        <Alert v-if="learnedRules > 0">
            <Lightbulb />
            <AlertTitle>
                {{ learnedRules }} rule
                {{ learnedRules === 1 ? 'suggestion' : 'suggestions' }} from
                your approvals
            </AlertTitle>
            <AlertDescription>
                <p>
                    You keep approving some payees to the same account. Turning
                    them into rules means they're categorized automatically next
                    time, with no AI call.
                    <Link
                        :href="rulesIndex(client.slug)"
                        class="font-medium underline underline-offset-4"
                        >Review rule suggestions</Link
                    >
                </p>
            </AlertDescription>
        </Alert>

        <p
            v-if="queue.data.length === 0"
            class="rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground"
        >
            All caught up. Nothing needs review.
        </p>

        <template v-else>
            <div class="flex flex-wrap items-center gap-3">
                <Label
                    class="flex items-center gap-2 text-sm font-normal"
                    for="select-all"
                >
                    <Checkbox
                        id="select-all"
                        :model-value="allSelected"
                        :disabled="suggestedIds.length === 0"
                        @update:model-value="toggleAll"
                    />
                    Select all suggestions on this page
                </Label>
                <Button
                    :disabled="
                        selected.length === 0 ||
                        selected.length > maxBulk ||
                        approving
                    "
                    @click="approveSelected"
                >
                    <Spinner v-if="approving" />
                    <CheckCheck v-else />
                    Approve selected ({{ selected.length }})
                </Button>
                <span class="text-sm text-muted-foreground"
                    >{{ queue.total }} waiting</span
                >
            </div>

            <div class="overflow-x-auto rounded-xl border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left text-muted-foreground">
                        <tr>
                            <th class="w-10 px-4 py-2">
                                <span class="sr-only">Select</span>
                            </th>
                            <th class="px-4 py-2 font-medium">Confidence</th>
                            <th class="px-4 py-2 font-medium">Date</th>
                            <th class="px-4 py-2 font-medium">Payee</th>
                            <th class="px-4 py-2 text-right font-medium">
                                Amount
                            </th>
                            <th class="w-80 px-4 py-2 font-medium">Account</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="row in queue.data"
                            :key="row.id"
                            class="align-top"
                        >
                            <td class="px-4 py-3">
                                <Checkbox
                                    v-if="row.status === 'suggested'"
                                    :model-value="selected.includes(row.id)"
                                    :aria-label="`Select ${row.payee}`"
                                    @update:model-value="
                                        (checked) => toggle(row.id, checked)
                                    "
                                />
                            </td>
                            <td
                                class="px-4 py-3 font-medium tabular-nums"
                                :class="confidenceClass(row.confidence)"
                            >
                                {{
                                    row.confidence === null
                                        ? '—'
                                        : `${row.confidence}%`
                                }}
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                {{ formatDate(row.posted_on) }}
                            </td>
                            <td class="px-4 py-3">
                                <p class="font-medium">{{ row.payee }}</p>
                                <p class="text-xs text-muted-foreground">
                                    {{ row.description }} ·
                                    {{ row.bank_account }}
                                </p>
                            </td>
                            <td
                                class="px-4 py-3 text-right whitespace-nowrap tabular-nums"
                                :class="{
                                    'text-emerald-700 dark:text-emerald-400':
                                        row.amount_cents > 0,
                                }"
                            >
                                {{ row.amount }}
                            </td>
                            <td class="px-4 py-3">
                                <CategorizeCell
                                    :transaction="row"
                                    :accounts="accounts"
                                    :busy="saving === row.id"
                                    @categorize="(id) => categorize(row, id)"
                                    @approve="approveSuggestion(row)"
                                />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div
                v-if="queue.last_page > 1"
                class="flex items-center justify-between text-sm text-muted-foreground"
            >
                <span
                    >{{ queue.from }}–{{ queue.to }} of {{ queue.total }}</span
                >
                <div class="flex gap-2">
                    <template
                        v-for="link in [
                            { label: 'Previous', url: queue.prev_page_url },
                            { label: 'Next', url: queue.next_page_url },
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
        </template>
    </div>
</template>
