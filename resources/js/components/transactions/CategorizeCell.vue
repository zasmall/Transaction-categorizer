<script setup lang="ts">
import { Check } from '@lucide/vue';
import AccountSelect from '@/components/AccountSelect.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import type { AccountOption, TransactionRow } from '@/types';

defineProps<{
    transaction: Pick<
        TransactionRow,
        'payee' | 'account_id' | 'status' | 'explanation' | 'ai_reason'
    >;
    accounts: AccountOption[];
    busy: boolean;
}>();

const emit = defineEmits<{
    categorize: [accountId: number | null];
    approve: [];
}>();
</script>

<template>
    <AccountSelect
        :model-value="transaction.account_id"
        :accounts="accounts"
        :disabled="busy"
        :aria-label="`Account for ${transaction.payee}`"
        @update:model-value="(id) => emit('categorize', id)"
    />
    <div
        class="mt-1 flex flex-wrap items-center gap-x-1.5 gap-y-1 text-xs text-muted-foreground"
    >
        <Badge v-if="transaction.status === 'uncategorized'" variant="outline"
            >Uncategorized</Badge
        >
        <Badge
            v-else-if="transaction.status === 'suggested'"
            class="border-transparent bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300"
            >Needs review</Badge
        >
        <span class="whitespace-nowrap">{{ transaction.explanation }}</span>
        <Button
            v-if="transaction.status === 'suggested'"
            size="sm"
            variant="outline"
            class="ml-auto h-6 px-2 text-xs"
            :disabled="busy"
            @click="emit('approve')"
        >
            <Check /> Approve
        </Button>
    </div>
    <p
        v-if="transaction.ai_reason"
        class="mt-1 text-xs text-muted-foreground italic"
    >
        {{ transaction.ai_reason }}
    </p>
</template>
