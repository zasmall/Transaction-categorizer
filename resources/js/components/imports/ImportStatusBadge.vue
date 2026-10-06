<script setup lang="ts">
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Spinner } from '@/components/ui/spinner';
import type { ImportRowStatus, ImportStatus } from '@/types';

const props = defineProps<{
    status: ImportStatus | ImportRowStatus;
}>();

const styles: Record<ImportStatus | ImportRowStatus, string> = {
    pending: 'bg-muted text-muted-foreground',
    parsing: 'bg-sky-100 text-sky-800 dark:bg-sky-950 dark:text-sky-300',
    normalizing: 'bg-sky-100 text-sky-800 dark:bg-sky-950 dark:text-sky-300',
    persisting: 'bg-sky-100 text-sky-800 dark:bg-sky-950 dark:text-sky-300',
    categorizing: 'bg-sky-100 text-sky-800 dark:bg-sky-950 dark:text-sky-300',
    normalized: 'bg-sky-100 text-sky-800 dark:bg-sky-950 dark:text-sky-300',
    completed:
        'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
    imported:
        'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
    completed_with_errors:
        'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
    duplicate: 'bg-muted text-muted-foreground',
    failed: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300',
};

const inProgress = computed(() =>
    ['parsing', 'normalizing', 'persisting', 'categorizing'].includes(
        props.status,
    ),
);

const label = computed(() => {
    const text = props.status.replaceAll('_', ' ');

    return text.charAt(0).toUpperCase() + text.slice(1);
});
</script>

<template>
    <Badge :class="['border-transparent', styles[status]]">
        <Spinner v-if="inProgress" />
        {{ label }}
    </Badge>
</template>
