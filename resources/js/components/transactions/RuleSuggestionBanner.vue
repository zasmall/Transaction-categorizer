<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Sparkles, X } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { create as createRule } from '@/routes/clients/rules';
import type { ClientSummary } from '@/types';

defineProps<{
    client: ClientSummary;
}>();

const page = usePage();
const suggestion = computed(() => page.flash.ruleSuggestion);
const dismissed = ref(false);

watch(suggestion, () => (dismissed.value = false));
</script>

<template>
    <Alert v-if="suggestion && !dismissed" class="border-primary/40">
        <Sparkles />
        <AlertTitle>Turn this into a rule?</AlertTitle>
        <AlertDescription>
            <p>
                Always put payees containing
                <strong>“{{ suggestion.payee }}”</strong> in
                <strong>{{ suggestion.account }}</strong
                >.
                <template v-if="suggestion.similar > 0">
                    It would also categorize {{ suggestion.similar }} other
                    {{
                        suggestion.similar === 1
                            ? 'transaction'
                            : 'transactions'
                    }}
                    right now.
                </template>
            </p>
            <div class="mt-3 flex gap-2">
                <Button size="sm" as-child>
                    <Link
                        :href="
                            createRule(client.slug, {
                                query: {
                                    payee: suggestion.payee,
                                    account_id: suggestion.account_id,
                                },
                            })
                        "
                    >
                        Create rule
                    </Link>
                </Button>
                <Button size="sm" variant="ghost" @click="dismissed = true">
                    <X /> Not now
                </Button>
            </div>
        </AlertDescription>
    </Alert>
</template>
