<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { FileDown, ListChecks, Sparkles, Upload } from '@lucide/vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { Button } from '@/components/ui/button';
import { dashboard, login } from '@/routes';

const steps = [
    {
        icon: Upload,
        title: 'Import statements',
        body: 'Bank and card CSVs from different banks are parsed, cleaned up and de-duplicated by a queued pipeline you can watch in Horizon.',
    },
    {
        icon: ListChecks,
        title: 'Rules first',
        body: 'Deterministic rules categorize what they can. Every decision is recorded, so any transaction can explain why it landed where it did.',
    },
    {
        icon: Sparkles,
        title: 'AI for the rest',
        body: 'Claude suggests accounts for the leftovers with a confidence and a reason. Nothing is approved without a person.',
    },
    {
        icon: FileDown,
        title: 'Export to QuickBooks',
        body: 'Approved transactions export as balanced journal entries, and repeated approvals turn into new rules.',
    },
];
</script>

<template>
    <Head />

    <div class="min-h-screen bg-background text-foreground">
        <header
            class="mx-auto flex max-w-5xl items-center justify-between px-4 py-6 sm:px-6"
        >
            <div class="flex items-center gap-2 font-semibold">
                <AppLogoIcon class="size-6 fill-current" />
                Transaction Categorizer
            </div>
            <Button v-if="$page.props.auth.user" as-child>
                <Link :href="dashboard()">Open the app</Link>
            </Button>
            <Button v-else variant="outline" as-child>
                <Link :href="login()">Log in</Link>
            </Button>
        </header>

        <main class="mx-auto max-w-5xl px-4 pt-10 pb-20 sm:px-6 sm:pt-16">
            <h1
                class="max-w-3xl text-4xl font-semibold tracking-tight text-balance sm:text-5xl"
            >
                Bank transactions in, categorized books out.
            </h1>
            <p class="mt-5 max-w-2xl text-lg text-muted-foreground">
                A bookkeeping tool for categorizing client bank and credit card
                transactions: rules first, AI suggestions second, and a person
                approving everything before it reaches QuickBooks.
            </p>

            <div class="mt-8 flex flex-wrap items-center gap-4">
                <Button size="lg" as-child>
                    <Link :href="$page.props.auth.user ? dashboard() : login()">
                        Try the demo
                    </Link>
                </Button>
                <p
                    v-if="!$page.props.auth.user"
                    class="text-sm text-muted-foreground"
                >
                    Log in as
                    <code class="rounded bg-muted px-1.5 py-0.5"
                        >demo@example.com</code
                    >
                    /
                    <code class="rounded bg-muted px-1.5 py-0.5">password</code>
                </p>
            </div>

            <ol class="mt-16 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                <li
                    v-for="(step, index) in steps"
                    :key="step.title"
                    class="rounded-xl border p-5"
                >
                    <div
                        class="flex items-center gap-2 text-sm text-muted-foreground"
                    >
                        <component :is="step.icon" class="size-4" />
                        Step {{ index + 1 }}
                    </div>
                    <h2 class="mt-3 font-medium">{{ step.title }}</h2>
                    <p class="mt-2 text-sm text-muted-foreground">
                        {{ step.body }}
                    </p>
                </li>
            </ol>

            <p class="mt-16 text-sm text-muted-foreground">
                Built with Laravel 13, Vue 3, Inertia, Redis + Horizon and
                Claude.
            </p>
        </main>
    </div>
</template>
