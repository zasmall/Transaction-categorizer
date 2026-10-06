<script setup lang="ts">
import { Head, Link, setLayoutProps, usePoll } from '@inertiajs/vue3';
import { ArrowLeft, Check, Circle, CircleX } from '@lucide/vue';
import { computed, onMounted, ref, watch } from 'vue';
import ClientNav from '@/components/clients/ClientNav.vue';
import Heading from '@/components/Heading.vue';
import ImportStatusBadge from '@/components/imports/ImportStatusBadge.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { formatDate, formatDateTime } from '@/lib/format';
import { dashboard } from '@/routes';
import { index, show } from '@/routes/clients/imports';
import type {
    ClientSummary,
    Import,
    ImportRow,
    ImportRowStatus,
    ImportStatus,
} from '@/types';

const props = defineProps<{
    client: ClientSummary;
    statementImport: Import;
    rows: ImportRow[];
}>();

setLayoutProps({
    breadcrumbs: [
        { title: 'Clients', href: dashboard() },
        { title: props.client.name, href: index(props.client.slug) },
        {
            title: props.statementImport.original_filename,
            href: show([props.client.slug, props.statementImport.id]),
        },
    ],
});

// Refresh every second while the queue works through the import, then stop.
// Started from onMounted (not autoStart) so a finished import never polls at all.
const { start, stop } = usePoll(
    1000,
    { only: ['statementImport', 'rows'] },
    { autoStart: false },
);
onMounted(() => {
    if (!props.statementImport.is_finished) {
        start();
    }
});
watch(
    () => props.statementImport.is_finished,
    (finished) => finished && stop(),
);

const stages: { status: ImportStatus; label: string }[] = [
    { status: 'parsing', label: 'Parse file' },
    { status: 'normalizing', label: 'Clean up rows' },
    { status: 'persisting', label: 'Save & de-duplicate' },
    { status: 'categorizing', label: 'Apply rules' },
    { status: 'suggesting', label: 'AI suggestions' },
    { status: 'completed', label: 'Done' },
];

const order: ImportStatus[] = [
    'pending',
    'parsing',
    'normalizing',
    'persisting',
    'categorizing',
    'suggesting',
    'completed',
];

function stageState(
    stage: ImportStatus,
): 'done' | 'current' | 'waiting' | 'failed' {
    const status = props.statementImport.status;

    if (status === 'failed') {
        return 'failed';
    }

    if (status === 'completed' || status === 'completed_with_errors') {
        return 'done';
    }

    const current = order.indexOf(status);
    const position = order.indexOf(stage);

    return position < current
        ? 'done'
        : position === current
          ? 'current'
          : 'waiting';
}

const filters: { value: ImportRowStatus | 'all'; label: string }[] = [
    { value: 'all', label: 'All' },
    { value: 'imported', label: 'New' },
    { value: 'duplicate', label: 'Duplicates' },
    { value: 'failed', label: 'Failed' },
];
const filter = ref<ImportRowStatus | 'all'>('all');

const visibleRows = computed(() =>
    filter.value === 'all'
        ? props.rows
        : props.rows.filter((row) => row.status === filter.value),
);

function rawSummary(row: ImportRow): string {
    return Object.values(row.raw).filter(Boolean).join(' · ');
}
</script>

<template>
    <Head :title="`${statementImport.original_filename} · ${client.name}`" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                :title="statementImport.original_filename"
                :description="`${statementImport.bank_account} · ${statementImport.profile} format · uploaded ${formatDateTime(statementImport.created_at)}${statementImport.uploaded_by ? ` by ${statementImport.uploaded_by}` : ''}`"
            />
            <Button variant="outline" as-child>
                <Link :href="index(client.slug)">
                    <ArrowLeft /> All imports
                </Link>
            </Button>
        </div>

        <ClientNav :client="client" />

        <ol
            class="grid gap-3 sm:grid-cols-3 lg:grid-cols-6"
            aria-label="Import progress"
        >
            <li
                v-for="stage in stages"
                :key="stage.status"
                class="flex items-center gap-2 rounded-lg border px-3 py-2 text-sm"
                :class="{
                    'border-primary/50 bg-primary/5':
                        stageState(stage.status) === 'current',
                    'text-muted-foreground':
                        stageState(stage.status) === 'waiting',
                }"
            >
                <Check
                    v-if="stageState(stage.status) === 'done'"
                    class="size-4 text-emerald-600"
                />
                <CircleX
                    v-else-if="stageState(stage.status) === 'failed'"
                    class="size-4 text-red-600"
                />
                <Circle
                    v-else
                    class="size-4"
                    :class="{
                        'animate-pulse text-primary':
                            stageState(stage.status) === 'current',
                    }"
                />
                {{ stage.label }}
            </li>
        </ol>

        <Alert v-if="statementImport.status === 'failed'" variant="destructive">
            <CircleX />
            <AlertTitle>This import failed</AlertTitle>
            <AlertDescription>{{ statementImport.error }}</AlertDescription>
        </Alert>

        <dl class="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-7">
            <div class="rounded-lg border p-3">
                <dt class="text-xs text-muted-foreground">Status</dt>
                <dd class="mt-1">
                    <ImportStatusBadge :status="statementImport.status" />
                </dd>
            </div>
            <div class="rounded-lg border p-3">
                <dt class="text-xs text-muted-foreground">Rows in file</dt>
                <dd class="mt-1 text-xl font-semibold tabular-nums">
                    {{ statementImport.total_rows }}
                </dd>
            </div>
            <div class="rounded-lg border p-3">
                <dt class="text-xs text-muted-foreground">New transactions</dt>
                <dd class="mt-1 text-xl font-semibold tabular-nums">
                    {{ statementImport.imported_rows }}
                </dd>
            </div>
            <div class="rounded-lg border p-3">
                <dt class="text-xs text-muted-foreground">
                    Duplicates skipped
                </dt>
                <dd class="mt-1 text-xl font-semibold tabular-nums">
                    {{ statementImport.duplicate_rows }}
                </dd>
            </div>
            <div class="rounded-lg border p-3">
                <dt class="text-xs text-muted-foreground">
                    Categorized by rules
                </dt>
                <dd class="mt-1 text-xl font-semibold tabular-nums">
                    {{ statementImport.categorized_rows }}
                </dd>
            </div>
            <div class="rounded-lg border p-3">
                <dt class="text-xs text-muted-foreground">
                    {{
                        statementImport.ai_is_demo
                            ? 'Demo suggestions'
                            : 'AI suggestions'
                    }}
                </dt>
                <dd class="mt-1 text-xl font-semibold tabular-nums">
                    {{ statementImport.ai_suggested_rows }}
                </dd>
            </div>
            <div class="rounded-lg border p-3">
                <dt class="text-xs text-muted-foreground">Failed rows</dt>
                <dd
                    class="mt-1 text-xl font-semibold tabular-nums"
                    :class="{
                        'text-red-600 dark:text-red-400':
                            statementImport.failed_rows > 0,
                    }"
                >
                    {{ statementImport.failed_rows }}
                </dd>
            </div>
        </dl>

        <p
            v-if="statementImport.ai_model"
            class="-mt-3 text-xs text-muted-foreground"
        >
            <template v-if="statementImport.ai_is_demo">
                Suggestions came from demo mode (keyword matching, no AI). Set
                AI_CATEGORIZER=anthropic and an API key to use Claude.
            </template>
            <template v-else>
                Suggested by {{ statementImport.ai_model }} ·
                {{ statementImport.ai_input_tokens.toLocaleString() }} input /
                {{ statementImport.ai_output_tokens.toLocaleString() }} output
                tokens. Suggestions wait for review; none are approved
                automatically.
            </template>
        </p>

        <section v-if="rows.length > 0" class="space-y-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-base font-medium">Rows</h2>
                <div class="flex gap-1" role="group" aria-label="Filter rows">
                    <Button
                        v-for="option in filters"
                        :key="option.value"
                        size="sm"
                        :variant="
                            filter === option.value ? 'secondary' : 'ghost'
                        "
                        :aria-pressed="filter === option.value"
                        @click="filter = option.value"
                    >
                        {{ option.label }}
                    </Button>
                </div>
            </div>

            <div class="overflow-x-auto rounded-xl border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left text-muted-foreground">
                        <tr>
                            <th class="px-4 py-2 font-medium">Line</th>
                            <th class="px-4 py-2 font-medium">Status</th>
                            <th class="px-4 py-2 font-medium">Date</th>
                            <th class="px-4 py-2 font-medium">Payee</th>
                            <th class="px-4 py-2 text-right font-medium">
                                Amount
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="row in visibleRows"
                            :key="row.id"
                            class="align-top"
                        >
                            <td
                                class="px-4 py-2 text-muted-foreground tabular-nums"
                            >
                                {{ row.row_number }}
                            </td>
                            <td class="px-4 py-2">
                                <ImportStatusBadge :status="row.status" />
                            </td>
                            <template v-if="row.status === 'failed'">
                                <td colspan="3" class="px-4 py-2">
                                    <p
                                        class="font-medium text-red-700 dark:text-red-400"
                                    >
                                        {{ row.error }}
                                    </p>
                                    <p
                                        class="mt-0.5 font-mono text-xs text-muted-foreground"
                                    >
                                        {{ rawSummary(row) }}
                                    </p>
                                </td>
                            </template>
                            <template v-else>
                                <td class="px-4 py-2 whitespace-nowrap">
                                    {{ formatDate(row.posted_on) }}
                                </td>
                                <td class="px-4 py-2">
                                    <p class="font-medium">{{ row.payee }}</p>
                                    <p class="text-xs text-muted-foreground">
                                        {{ row.description }}
                                    </p>
                                </td>
                                <td
                                    class="px-4 py-2 text-right whitespace-nowrap tabular-nums"
                                    :class="{
                                        'text-emerald-700 dark:text-emerald-400':
                                            (row.amount_cents ?? 0) > 0,
                                    }"
                                >
                                    {{ row.amount }}
                                </td>
                            </template>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-if="rows.length >= 200" class="text-xs text-muted-foreground">
                Showing the first 200 rows.
            </p>
        </section>
    </div>
</template>
