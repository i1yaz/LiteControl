<script setup>
import UiAlert from '@shared/Ui/UiAlert.vue';
import StatusBadge from '@domains/Mail/Components/StatusBadge.vue';

defineProps({
    purges: {
        type: Array,
        default: () => [],
    },
});
</script>

<template>
    <UiAlert
        v-if="purges.length > 0"
        variant="warning"
        role="status"
        aria-live="polite"
    >
        <div class="space-y-2">
            <p class="font-semibold text-theme-warning-text">
                Pending Mail Data Purge ({{ purges.length }})
            </p>
            <p class="text-xs opacity-90">
                The following domains or mailboxes are currently being purged in the background and cannot be re-created until cleanup finishes.
            </p>
            <ul class="space-y-1.5 pt-1">
                <li
                    v-for="purge in purges"
                    :key="purge.id ?? `${purge.entity_type}-${purge.email || purge.domain}`"
                    class="flex flex-wrap items-center justify-between gap-2 rounded-theme-sm border border-theme-warning-border/60 bg-theme-bg-surface/40 px-2.5 py-1.5 text-xs"
                >
                    <div class="flex flex-wrap items-center gap-2 min-w-0">
                        <span class="uppercase tracking-wider text-[10px] font-semibold px-1.5 py-0.5 rounded bg-theme-bg-surface text-theme-text-secondary">
                            {{ purge.entity_type }}
                        </span>
                        <span class="font-mono font-medium text-theme-text-primary break-all">
                            {{ purge.email || purge.domain }}
                        </span>
                    </div>
                    <div class="flex items-center gap-2 shrink-0">
                        <span v-if="purge.attempts !== undefined" class="text-[11px] text-theme-text-muted">
                            Attempts: {{ purge.attempts }}
                        </span>
                        <StatusBadge :status="purge.status || 'purging'" />
                    </div>
                    <p
                        v-if="purge.last_error"
                        class="w-full text-[11px] text-theme-danger-text font-mono break-all"
                    >
                        {{ purge.last_error }}
                    </p>
                </li>
            </ul>
        </div>
    </UiAlert>
</template>
