<script setup lang="ts">
import { Form, Head, Link, setLayoutProps } from '@inertiajs/vue3';
import { computed } from 'vue';
import ImportController from '@/actions/App/Http/Controllers/ImportController';
import Heading from '@/components/Heading.vue';
import ImportStatusBadge from '@/components/imports/ImportStatusBadge.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatDateTime } from '@/lib/format';
import { dashboard } from '@/routes';
import { index, show } from '@/routes/clients/imports';
import type { ClientSummary, Import } from '@/types';

type BankAccountOption = {
    id: number;
    name: string;
    last4: string | null;
    profile: string | null;
};

const props = defineProps<{
    client: ClientSummary;
    bankAccounts: BankAccountOption[];
    imports: Import[];
    maxFileKilobytes: number;
}>();

setLayoutProps({
    breadcrumbs: [
        { title: 'Clients', href: dashboard() },
        { title: props.client.name, href: index(props.client.slug) },
        { title: 'Imports', href: index(props.client.slug) },
    ],
});

const importableAccounts = computed(() =>
    props.bankAccounts.filter((account) => account.profile !== null),
);

const fieldClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 aria-invalid:border-destructive dark:bg-input/30';
</script>

<template>
    <Head :title="`Imports · ${client.name}`" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <Heading
            :title="client.name"
            description="Upload bank and credit card statements. Rows are parsed, cleaned up and de-duplicated in the background."
        />

        <Card class="max-w-2xl">
            <CardHeader>
                <CardTitle>Import a statement</CardTitle>
                <CardDescription>
                    CSV exports up to {{ maxFileKilobytes / 1024 }} MB. Rows
                    already imported into the account are skipped automatically.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <p
                    v-if="importableAccounts.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    None of this client's bank accounts has an import format set
                    yet.
                </p>

                <Form
                    v-else
                    v-bind="ImportController.store.form(client.slug)"
                    class="space-y-5"
                    v-slot="{ errors, processing }"
                >
                    <div class="grid gap-2">
                        <Label for="bank_account_id">Bank account</Label>
                        <select
                            id="bank_account_id"
                            name="bank_account_id"
                            required
                            :class="fieldClass"
                            :aria-invalid="!!errors.bank_account_id"
                        >
                            <option
                                v-for="account in importableAccounts"
                                :key="account.id"
                                :value="account.id"
                            >
                                {{ account.name }}
                                <template v-if="account.last4">
                                    ···{{ account.last4 }}</template
                                >
                                ({{ account.profile }} format)
                            </option>
                        </select>
                        <InputError :message="errors.bank_account_id" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="file">Statement file</Label>
                        <input
                            id="file"
                            type="file"
                            name="file"
                            accept=".csv,text/csv"
                            required
                            :class="[
                                fieldClass,
                                'file:mr-3 file:border-0 file:bg-transparent file:text-sm file:font-medium',
                            ]"
                            :aria-invalid="!!errors.file"
                        />
                        <InputError :message="errors.file" />
                    </div>

                    <Label
                        v-if="errors.file?.includes('already imported')"
                        for="allow_duplicate_file"
                        class="flex items-center gap-3 font-normal"
                    >
                        <Checkbox
                            id="allow_duplicate_file"
                            name="allow_duplicate_file"
                            value="1"
                        />
                        Import anyway
                    </Label>

                    <Button type="submit" :disabled="processing">
                        <Spinner v-if="processing" />
                        Upload and import
                    </Button>
                </Form>
            </CardContent>
        </Card>

        <section class="space-y-3">
            <h2 class="text-base font-medium">Recent imports</h2>

            <p
                v-if="imports.length === 0"
                class="text-sm text-muted-foreground"
            >
                Nothing imported yet.
            </p>

            <div v-else class="overflow-x-auto rounded-xl border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left text-muted-foreground">
                        <tr>
                            <th class="px-4 py-2 font-medium">File</th>
                            <th class="px-4 py-2 font-medium">Account</th>
                            <th class="px-4 py-2 font-medium">Status</th>
                            <th class="px-4 py-2 text-right font-medium">
                                New
                            </th>
                            <th class="px-4 py-2 text-right font-medium">
                                Duplicates
                            </th>
                            <th class="px-4 py-2 text-right font-medium">
                                Failed
                            </th>
                            <th class="px-4 py-2 font-medium">Uploaded</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="item in imports"
                            :key="item.id"
                            class="hover:bg-muted/30"
                        >
                            <td class="px-4 py-2">
                                <Link
                                    :href="show([client.slug, item.id])"
                                    class="font-medium underline-offset-4 hover:underline"
                                >
                                    {{ item.original_filename }}
                                </Link>
                            </td>
                            <td class="px-4 py-2">{{ item.bank_account }}</td>
                            <td class="px-4 py-2">
                                <ImportStatusBadge :status="item.status" />
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums">
                                {{ item.imported_rows }}
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums">
                                {{ item.duplicate_rows }}
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums">
                                {{ item.failed_rows }}
                            </td>
                            <td class="px-4 py-2 text-muted-foreground">
                                {{ formatDateTime(item.created_at) }}
                                <template v-if="item.uploaded_by">
                                    · {{ item.uploaded_by }}</template
                                >
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>
