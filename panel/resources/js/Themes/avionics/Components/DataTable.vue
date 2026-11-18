<script setup lang="ts">
defineProps({
    headers: {
        type: Array,
        default: () => [],
    },
    emptyMessage: {
        type: String,
        default: 'No records found.',
    },
    itemsCount: {
        type: Number,
        default: 0,
    },
});
</script>

<template>
    <div class="border border-theme-border rounded-theme-lg bg-theme-bg-card overflow-hidden">
        <div v-if="$slots.toolbar" class="px-4 py-2.5 border-b border-theme-border bg-theme-bg-surface/60 flex items-center justify-between font-mono text-xs">
            <slot name="toolbar" />
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left font-mono text-xs">
                <thead class="bg-theme-bg-surface/80 border-b border-theme-border text-theme-text-secondary uppercase tracking-wider text-[11px]">
                    <tr>
                        <slot name="header">
                            <th
                                v-for="(col, idx) in headers"
                                :key="idx"
                                :class="[
                                    'py-3 px-4 font-semibold',
                                    typeof col === 'object' ? col.class : ''
                                ]"
                            >
                                {{ typeof col === 'object' ? col.label : col }}
                            </th>
                        </slot>
                    </tr>
                </thead>
                <tbody class="divide-y divide-theme-border-subtle text-theme-text-primary">
                    <slot />
                    <tr v-if="itemsCount === 0">
                        <td :colspan="headers.length || 10" class="py-8 text-center text-theme-text-muted">
                            <slot name="empty">
                                {{ emptyMessage }}
                            </slot>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div v-if="$slots.footer" class="p-3 border-t border-theme-border bg-theme-bg-surface/40">
            <slot name="footer" />
        </div>
    </div>
</template>
