<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import type { RouteFormDefinition } from '@/wayfinder';
import { ref } from 'vue';
import AccountSelect from '@/components/AccountSelect.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import type { AccountOption, Option, RuleFormValues } from '@/types';

const props = withDefaults(
    defineProps<{
        action: RouteFormDefinition<'post'>;
        rule: RuleFormValues;
        accounts: AccountOption[];
        matchFields: Option[];
        operators: Option[];
        submitLabel: string;
        // Vue casts an omitted boolean prop to false, so the default must be declared here.
        applyToExistingByDefault?: boolean;
    }>(),
    { applyToExistingByDefault: true },
);

const accountId = ref(props.rule.account_id);

const directions: Option[] = [
    { value: 'any', label: 'Money in or out' },
    { value: 'outflow', label: 'Money out only' },
    { value: 'inflow', label: 'Money in only' },
];

const selectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-2 py-1 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30';
</script>

<template>
    <Form
        v-bind="action"
        class="max-w-2xl space-y-6"
        v-slot="{ errors, processing }"
    >
        <div class="grid gap-2">
            <Label for="name">Rule name</Label>
            <Input
                id="name"
                name="name"
                :default-value="rule.name"
                required
                placeholder="e.g. Coffee shops"
            />
            <InputError :message="errors.name" />
        </div>

        <fieldset class="space-y-3">
            <legend class="text-sm font-medium">When</legend>
            <div class="grid gap-2 sm:grid-cols-[1fr_1fr_2fr]">
                <select
                    name="match_field"
                    aria-label="Field to match"
                    :class="selectClass"
                >
                    <option
                        v-for="field in matchFields"
                        :key="field.value"
                        :value="field.value"
                        :selected="field.value === rule.match_field"
                    >
                        {{ field.label }}
                    </option>
                </select>
                <select
                    name="operator"
                    aria-label="How to match"
                    :class="selectClass"
                >
                    <option
                        v-for="operator in operators"
                        :key="operator.value"
                        :value="operator.value"
                        :selected="operator.value === rule.operator"
                    >
                        {{ operator.label }}
                    </option>
                </select>
                <Input
                    name="pattern"
                    aria-label="Text to match"
                    :default-value="rule.pattern"
                    required
                    placeholder="blue bottle"
                />
            </div>
            <InputError :message="errors.pattern" />
            <p class="text-xs text-muted-foreground">
                Matching ignores upper and lower case. Payees are cleaned up
                bank descriptions, e.g. “Blue Bottle Coffee Oakland”.
            </p>
        </fieldset>

        <fieldset class="space-y-3">
            <legend class="text-sm font-medium">Only if (optional)</legend>
            <div class="grid gap-2 sm:grid-cols-3">
                <select
                    name="direction"
                    aria-label="Direction"
                    :class="selectClass"
                >
                    <option
                        v-for="direction in directions"
                        :key="direction.value"
                        :value="direction.value"
                        :selected="direction.value === rule.direction"
                    >
                        {{ direction.label }}
                    </option>
                </select>
                <Input
                    name="amount_min"
                    inputmode="decimal"
                    aria-label="Minimum amount"
                    :default-value="rule.amount_min ?? ''"
                    placeholder="Min $"
                />
                <Input
                    name="amount_max"
                    inputmode="decimal"
                    aria-label="Maximum amount"
                    :default-value="rule.amount_max ?? ''"
                    placeholder="Max $"
                />
            </div>
            <InputError :message="errors.amount_min || errors.amount_max" />
        </fieldset>

        <div class="grid gap-2">
            <Label for="account_id">Then categorize to</Label>
            <AccountSelect
                id="account_id"
                v-model="accountId"
                :accounts="accounts"
                :aria-invalid="!!errors.account_id"
            />
            <input type="hidden" name="account_id" :value="accountId ?? ''" />
            <InputError :message="errors.account_id" />
        </div>

        <div class="grid gap-2 sm:max-w-40">
            <Label for="priority">Priority</Label>
            <Input
                id="priority"
                name="priority"
                type="number"
                min="1"
                max="10000"
                :default-value="rule.priority"
                required
            />
            <p class="text-xs text-muted-foreground">
                Lower runs first. The first matching rule wins.
            </p>
            <InputError :message="errors.priority" />
        </div>

        <div class="space-y-3">
            <Label class="flex items-center gap-3 font-normal">
                <Checkbox
                    name="is_active"
                    value="1"
                    :default-value="rule.is_active"
                />
                Active
            </Label>
            <Label class="flex items-center gap-3 font-normal">
                <Checkbox
                    name="apply_to_existing"
                    value="1"
                    :default-value="applyToExistingByDefault"
                />
                Also apply rules to existing uncategorized transactions
            </Label>
        </div>

        <Button type="submit" :disabled="processing">
            <Spinner v-if="processing" />
            {{ submitLabel }}
        </Button>
    </Form>
</template>
