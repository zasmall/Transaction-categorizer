<script setup lang="ts">
import { Form, Head, Link, router, setLayoutProps } from '@inertiajs/vue3';
import { Pencil, Play, Plus, Trash2 } from '@lucide/vue';
import CategorizationRuleController from '@/actions/App/Http/Controllers/CategorizationRuleController';
import ClientNav from '@/components/clients/ClientNav.vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatDateTime } from '@/lib/format';
import { dashboard } from '@/routes';
import { create, destroy, edit, index } from '@/routes/clients/rules';
import type { CategorizationRule, ClientSummary } from '@/types';

const props = defineProps<{
    client: ClientSummary;
    rules: CategorizationRule[];
}>();

setLayoutProps({
    breadcrumbs: [
        { title: 'Clients', href: dashboard() },
        { title: props.client.name, href: index(props.client.slug) },
        { title: 'Rules', href: index(props.client.slug) },
    ],
});

function remove(rule: CategorizationRule) {
    if (
        confirm(
            `Delete “${rule.name}”? Transactions it already categorized keep their account and history.`,
        )
    ) {
        router.delete(destroy.url([props.client.slug, rule.id]), {
            preserveScroll: true,
        });
    }
}
</script>

<template>
    <Head :title="`Rules · ${client.name}`" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <Heading
            :title="client.name"
            description="Rules run first on every import, lowest priority number first. The first match wins and is approved automatically."
        />

        <ClientNav :client="client" />

        <div class="flex flex-wrap gap-2">
            <Button as-child>
                <Link :href="create(client.slug)"><Plus /> New rule</Link>
            </Button>
            <Form
                v-if="rules.length > 0"
                v-bind="CategorizationRuleController.apply.form(client.slug)"
                v-slot="{ processing }"
                :options="{ preserveScroll: true }"
            >
                <Button type="submit" variant="outline" :disabled="processing">
                    <Play /> Run rules on uncategorized
                </Button>
            </Form>
        </div>

        <p v-if="rules.length === 0" class="text-sm text-muted-foreground">
            No rules yet. Categorize a transaction by hand and you'll be offered
            a rule for it, or create one here.
        </p>

        <div v-else class="overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th class="px-4 py-2 font-medium">Priority</th>
                        <th class="px-4 py-2 font-medium">Rule</th>
                        <th class="px-4 py-2 font-medium">Categorize to</th>
                        <th class="px-4 py-2 text-right font-medium">
                            Matches
                        </th>
                        <th class="px-4 py-2 font-medium">Last matched</th>
                        <th class="px-4 py-2">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr
                        v-for="rule in rules"
                        :key="rule.id"
                        :class="{ 'text-muted-foreground': !rule.is_active }"
                    >
                        <td class="px-4 py-3 tabular-nums">
                            {{ rule.priority }}
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2 font-medium">
                                {{ rule.name }}
                                <Badge v-if="!rule.is_active" variant="outline"
                                    >Paused</Badge
                                >
                            </div>
                            <p class="text-xs text-muted-foreground">
                                {{ rule.summary }}
                            </p>
                        </td>
                        <td class="px-4 py-3">{{ rule.account }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">
                            {{ rule.hits_count }}
                        </td>
                        <td
                            class="px-4 py-3 whitespace-nowrap text-muted-foreground"
                        >
                            {{
                                rule.last_matched_at
                                    ? formatDateTime(rule.last_matched_at)
                                    : 'Never'
                            }}
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex justify-end gap-1">
                                <Button variant="ghost" size="icon" as-child>
                                    <Link
                                        :href="edit([client.slug, rule.id])"
                                        :aria-label="`Edit ${rule.name}`"
                                    >
                                        <Pencil />
                                    </Link>
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    :aria-label="`Delete ${rule.name}`"
                                    @click="remove(rule)"
                                >
                                    <Trash2 />
                                </Button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
