import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { approve, update } from '@/routes/clients/transactions';
import type { TransactionRow } from '@/types';

/**
 * Categorize or approve one transaction in place, tracking which row is saving.
 */
export function useCategorize(clientSlug: string) {
    const saving = ref<number | null>(null);

    const options = (id: number) => ({
        preserveScroll: true,
        onStart: () => (saving.value = id),
        onFinish: () => (saving.value = null),
    });

    function categorize(
        transaction: Pick<TransactionRow, 'id' | 'account_id'>,
        accountId: number | null,
    ) {
        if (accountId === null || accountId === transaction.account_id) {
            return;
        }

        router.patch(
            update.url([clientSlug, transaction.id]),
            { account_id: accountId },
            options(transaction.id),
        );
    }

    function approveSuggestion(transaction: Pick<TransactionRow, 'id'>) {
        router.post(
            approve.url([clientSlug, transaction.id]),
            {},
            options(transaction.id),
        );
    }

    return { saving, categorize, approveSuggestion };
}
