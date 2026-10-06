<script setup lang="ts">
import { Head, setLayoutProps } from '@inertiajs/vue3';
import CategorizationRuleController from '@/actions/App/Http/Controllers/CategorizationRuleController';
import ClientNav from '@/components/clients/ClientNav.vue';
import Heading from '@/components/Heading.vue';
import RuleForm from '@/components/rules/RuleForm.vue';
import { dashboard } from '@/routes';
import { edit, index } from '@/routes/clients/rules';
import type {
    AccountOption,
    CategorizationRule,
    ClientSummary,
    Option,
} from '@/types';

const props = defineProps<{
    client: ClientSummary;
    rule: CategorizationRule;
    accounts: AccountOption[];
    matchFields: Option[];
    operators: Option[];
}>();

setLayoutProps({
    breadcrumbs: [
        { title: 'Clients', href: dashboard() },
        { title: props.client.name, href: index(props.client.slug) },
        { title: 'Rules', href: index(props.client.slug) },
        {
            title: props.rule.name,
            href: edit([props.client.slug, props.rule.id]),
        },
    ],
});
</script>

<template>
    <Head :title="`${rule.name} · Rules · ${client.name}`" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <Heading
            :title="client.name"
            :description="`Editing “${rule.name}”. It has categorized ${rule.hits_count} ${rule.hits_count === 1 ? 'transaction' : 'transactions'} so far.`"
        />

        <ClientNav :client="client" />

        <RuleForm
            :action="
                CategorizationRuleController.update.form([client.slug, rule.id])
            "
            :rule="rule"
            :accounts="accounts"
            :match-fields="matchFields"
            :operators="operators"
            submit-label="Save rule"
            :apply-to-existing-by-default="false"
        />
    </div>
</template>
