<script setup lang="ts">
import { Head, Link, router, setLayoutProps } from '@inertiajs/vue3';
import { Download, FileCheck2, TriangleAlert } from '@lucide/vue';
import { computed, reactive } from 'vue';
import ClientNav from '@/components/clients/ClientNav.vue';
import Heading from '@/components/Heading.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { dashboard } from '@/routes';
import { download, index, mark } from '@/routes/clients/exports';
import { index as reviewIndex } from '@/routes/clients/review';
import type { ClientSummary } from '@/types';

type Filters = {
    from: string | null;
    to: string | null;
    bank_account_id: number | null;
    include_exported: boolean;
};

const props = defineProps<{
    client: ClientSummary;
    filters: Filters;
    bankAccounts: { id: number; name: string }[];
    summary: { ready: number; needs_review: number; already_exported: number };
}>();

setLayoutProps({
    breadcrumbs: [
        { title: 'Clients', href: dashboard() },
        { title: props.client.name, href: index(props.client.slug) },
        { title: 'Export', href: index(props.client.slug) },
    ],
});

const form = reactive({
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
    bank_account_id: props.filters.bank_account_id,
    include_exported: props.filters.include_exported,
});

// Only send the filters that are set, so URLs stay tidy.
const query = computed(() =>
    Object.fromEntries(
        Object.entries({
            from: form.from || null,
            to: form.to || null,
            bank_account_id: form.bank_account_id,
            include_exported: form.include_exported ? 1 : null,
        }).filter(([, value]) => value !== null),
    ),
);

const downloadUrl = computed(() =>
    download.url(props.client.slug, { query: query.value }),
);

function preview() {
    router.get(index.url(props.client.slug), query.value, {
        preserveState: true,
        preserveScroll: true,
    });
}

function markExported() {
    if (
        confirm(
            `Mark ${props.summary.ready} ${props.summary.ready === 1 ? 'transaction' : 'transactions'} as exported? Do this once QuickBooks has accepted the file.`,
        )
    ) {
        router.post(
            mark.url(props.client.slug, { query: query.value }),
            {},
            {
                preserveScroll: true,
            },
        );
    }
}

const fieldClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-2 py-1 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30';
</script>

<template>
    <Head :title="`Export · ${client.name}`" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <Heading
            :title="client.name"
            description="Export approved transactions as QuickBooks Online journal entries: two balanced lines per transaction, using each account's QuickBooks name."
        />

        <ClientNav :client="client" />

        <form
            class="grid max-w-3xl gap-4 sm:grid-cols-[1fr_1fr_1.5fr_auto] sm:items-end"
            @submit.prevent="preview"
        >
            <div class="grid gap-2">
                <Label for="from">From</Label>
                <Input id="from" v-model="form.from" type="date" />
            </div>
            <div class="grid gap-2">
                <Label for="to">To</Label>
                <Input id="to" v-model="form.to" type="date" />
            </div>
            <div class="grid gap-2">
                <Label for="bank_account_id">Account</Label>
                <select
                    id="bank_account_id"
                    v-model="form.bank_account_id"
                    :class="fieldClass"
                >
                    <option :value="null">All accounts</option>
                    <option
                        v-for="account in bankAccounts"
                        :key="account.id"
                        :value="account.id"
                    >
                        {{ account.name }}
                    </option>
                </select>
            </div>
            <Button type="submit" variant="outline">Update preview</Button>
            <Label
                class="flex items-center gap-2 font-normal sm:col-span-4"
                for="include_exported"
            >
                <Checkbox
                    id="include_exported"
                    v-model="form.include_exported"
                />
                Include transactions that were already exported
            </Label>
        </form>

        <dl class="grid max-w-3xl grid-cols-3 gap-3">
            <div class="rounded-lg border p-3">
                <dt class="text-xs text-muted-foreground">Ready to export</dt>
                <dd class="mt-1 text-xl font-semibold tabular-nums">
                    {{ summary.ready }}
                </dd>
            </div>
            <div class="rounded-lg border p-3">
                <dt class="text-xs text-muted-foreground">Still need review</dt>
                <dd
                    class="mt-1 text-xl font-semibold tabular-nums"
                    :class="{
                        'text-amber-700 dark:text-amber-400':
                            summary.needs_review > 0,
                    }"
                >
                    {{ summary.needs_review }}
                </dd>
            </div>
            <div class="rounded-lg border p-3">
                <dt class="text-xs text-muted-foreground">Already exported</dt>
                <dd class="mt-1 text-xl font-semibold tabular-nums">
                    {{ summary.already_exported }}
                </dd>
            </div>
        </dl>

        <Alert v-if="summary.needs_review > 0" class="max-w-3xl">
            <TriangleAlert />
            <AlertTitle
                >Some transactions in this range aren't approved</AlertTitle
            >
            <AlertDescription>
                <p>
                    Only approved transactions are exported. Approve or
                    categorize the rest in
                    <Link
                        :href="reviewIndex(client.slug)"
                        class="font-medium underline underline-offset-4"
                        >Review</Link
                    >
                    first if this export should be complete.
                </p>
            </AlertDescription>
        </Alert>

        <div class="flex flex-wrap gap-2">
            <Button v-if="summary.ready > 0" as-child>
                <a :href="downloadUrl" download><Download /> Download CSV</a>
            </Button>
            <Button v-else disabled><Download /> Download CSV</Button>
            <Button
                variant="outline"
                :disabled="summary.ready === 0"
                @click="markExported"
            >
                <FileCheck2 /> Mark as exported
            </Button>
        </div>
        <p class="max-w-3xl text-xs text-muted-foreground">
            Downloading doesn't change anything. After QuickBooks accepts the
            file, mark the batch as exported so it's left out of the next
            export. Check the import against your QuickBooks plan's journal
            entry template before relying on it.
        </p>
    </div>
</template>
