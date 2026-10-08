<script setup lang="ts">
import { Head, usePoll } from '@inertiajs/vue3';
import { ChevronRight } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { index } from '@/routes/webhooks';

type Receipt = {
    id: number;
    event_id: string;
    event_type: string;
    delivery_id: string | null;
    payload: string;
    received_at: string;
};

defineProps<{ receipts: Receipt[] }>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Webhooks', href: index() }],
    },
});

usePoll(5000, { only: ['receipts'] });

function receivedAt(iso: string): string {
    return new Date(iso).toLocaleString();
}
</script>

<template>
    <Head title="Webhooks" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <Heading
            title="Webhook receipts"
            description="Events delivered by Webhook Relay to POST /api/webhooks/relay. Every request is signature-verified; redeliveries of the same event are recorded once."
        />

        <div
            v-if="receipts.length === 0"
            class="rounded-xl border border-dashed p-10 text-center text-sm text-muted-foreground"
        >
            Nothing received yet. Point a Webhook Relay endpoint at this app and
            set <code class="font-mono">RELAY_WEBHOOK_SECRET</code> to its
            signing secret.
        </div>

        <ol v-else class="space-y-2">
            <li v-for="receipt in receipts" :key="receipt.id">
                <details class="group rounded-lg border">
                    <summary
                        class="flex cursor-pointer list-none flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3 text-sm"
                    >
                        <ChevronRight
                            class="size-4 transition-transform group-open:rotate-90"
                        />
                        <Badge variant="secondary" class="font-mono">
                            {{ receipt.event_type }}
                        </Badge>
                        <span class="font-mono text-muted-foreground">
                            {{ receipt.event_id }}
                        </span>
                        <span class="ml-auto text-muted-foreground">
                            {{ receivedAt(receipt.received_at) }}
                        </span>
                    </summary>
                    <div class="space-y-2 border-t px-4 py-3">
                        <p
                            v-if="receipt.delivery_id"
                            class="text-xs text-muted-foreground"
                        >
                            Delivery
                            <span class="font-mono">{{
                                receipt.delivery_id
                            }}</span>
                        </p>
                        <pre
                            class="max-h-96 overflow-auto rounded-md border bg-muted/40 p-3 font-mono text-xs break-all whitespace-pre-wrap"
                        ><code>{{ receipt.payload }}</code></pre>
                    </div>
                </details>
            </li>
        </ol>
    </div>
</template>
