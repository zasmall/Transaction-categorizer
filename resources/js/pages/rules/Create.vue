<script setup lang="ts">
import { Head, setLayoutProps } from '@inertiajs/vue3';
import CategorizationRuleController from '@/actions/App/Http/Controllers/CategorizationRuleController';
import ClientNav from '@/components/clients/ClientNav.vue';
import Heading from '@/components/Heading.vue';
import RuleForm from '@/components/rules/RuleForm.vue';
import { dashboard } from '@/routes';
import { create, index } from '@/routes/clients/rules';
import type {
    AccountOption,
    ClientSummary,
    Option,
    RuleFormValues,
} from '@/types';

const props = defineProps<{
    client: ClientSummary;
    rule: RuleFormValues;
    accounts: AccountOption[];
    matchFields: Option[];
    operators: Option[];
}>();

setLayoutProps({
    breadcrumbs: [
        { title: 'Clients', href: dashboard() },
        { title: props.client.name, href: index(props.client.slug) },
        { title: 'Rules', href: index(props.client.slug) },
        { title: 'New rule', href: create(props.client.slug) },
    ],
});
</script>

<template>
    <Head :title="`New rule · ${client.name}`" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <Heading
            :title="client.name"
            description="Rules categorize matching transactions automatically, before any AI is involved."
        />

        <ClientNav :client="client" />

        <RuleForm
            :action="CategorizationRuleController.store.form(client.slug)"
            :rule="rule"
            :accounts="accounts"
            :match-fields="matchFields"
            :operators="operators"
            submit-label="Create rule"
        />
    </div>
</template>
