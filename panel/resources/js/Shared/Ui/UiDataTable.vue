<script setup lang="ts">
import { computed } from 'vue';
import { useTheme } from '@shared/Composables/useTheme';

defineOptions({ inheritAttrs: false });

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

const { resolveComponent } = useTheme();
const ThemeOverride = computed(() => resolveComponent('DataTable'));
</script>

<template>
    <component :is="ThemeOverride" v-if="ThemeOverride" v-bind="{ ...$props, ...$attrs }">
        <template v-for="(_, slot) in $slots" #[slot]="slotProps">
            <slot :name="slot" v-bind="slotProps" />
        </template>
    </component>
    <div v-else v-bind="$attrs" class="border border-theme-border rounded-theme-lg bg-theme-bg-card overflow-hidden shadow-xs">
        <div v-if="$slots.toolbar" class="px-5 py-3 border-b border-theme-border bg-theme-bg-surface/50 flex items-center justify-between">
            <slot name="toolbar" />
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs sm:text-sm">
                <thead class="bg-theme-bg-surface/60 border-b border-theme-border text-theme-text-secondary font-semibold text-xs uppercase tracking-wider theme-ui-control">
                    <tr>
                        <slot name="header">
                            <th
                                v-for="(col, idx) in headers"
                                :key="idx"
                                :class="[
                                    'py-3.5 px-4 font-semibold',
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
                        <td :colspan="headers.length || 10" class="py-10 text-center text-theme-text-muted text-xs sm:text-sm">
                            <slot name="empty">
                                {{ emptyMessage }}
                            </slot>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div v-if="$slots.footer" class="p-4 border-t border-theme-border bg-theme-bg-surface/40">
            <slot name="footer" />
        </div>
    </div>
</template>
