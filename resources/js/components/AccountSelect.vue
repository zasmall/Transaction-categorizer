<script setup lang="ts">
import { computed } from 'vue';
import { cn } from '@/lib/utils';
import type { AccountOption } from '@/types';

const props = defineProps<{
    accounts: AccountOption[];
    placeholder?: string;
    class?: string;
}>();

const model = defineModel<number | null>({ default: null });

const groups = computed(() => {
    const byType = new Map<string, AccountOption[]>();

    for (const account of props.accounts) {
        byType.set(account.type, [
            ...(byType.get(account.type) ?? []),
            account,
        ]);
    }

    return [...byType.entries()];
});
</script>

<template>
    <select
        v-model="model"
        :class="
            cn(
                'h-9 w-full rounded-md border border-input bg-transparent px-2 py-1 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:opacity-50 aria-invalid:border-destructive dark:bg-input/30',
                props.class,
            )
        "
    >
        <option :value="null" disabled>
            {{ placeholder ?? 'Choose an account…' }}
        </option>
        <optgroup v-for="[type, options] in groups" :key="type" :label="type">
            <option
                v-for="account in options"
                :key="account.id"
                :value="account.id"
            >
                {{ account.code }} · {{ account.name }}
            </option>
        </optgroup>
    </select>
</template>
