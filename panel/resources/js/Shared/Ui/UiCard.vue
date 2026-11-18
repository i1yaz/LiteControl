<script setup lang="ts">
import { computed } from 'vue';
import { useTheme } from '@shared/Composables/useTheme';

defineOptions({ inheritAttrs: false });

defineProps({
    title: {
        type: String,
        default: '',
    },
    subtitle: {
        type: String,
        default: '',
    },
});

const { resolveComponent } = useTheme();
const ThemeOverride = computed(() => resolveComponent('Card'));
</script>

<template>
    <component :is="ThemeOverride" v-if="ThemeOverride" v-bind="{ ...$props, ...$attrs }">
        <template v-for="(_, slot) in $slots" #[slot]="slotProps">
            <slot :name="slot" v-bind="slotProps" />
        </template>
    </component>
    <div v-else v-bind="$attrs" class="bg-theme-bg-card border border-theme-border rounded-theme-lg p-5 sm:p-6 shadow-xs">
        <div v-if="title || $slots.header" class="flex items-center justify-between pb-4 mb-5 border-b border-theme-border-subtle">
            <div>
                <h3 v-if="title" class="text-base font-semibold text-theme-text-primary theme-ui-control">
                    {{ title }}
                </h3>
                <p v-if="subtitle" class="text-xs text-theme-text-secondary mt-0.5">
                    {{ subtitle }}
                </p>
            </div>
            <div v-if="$slots.header">
                <slot name="header" />
            </div>
        </div>

        <slot />

        <div v-if="$slots.footer" class="mt-5 pt-4 border-t border-theme-border-subtle">
            <slot name="footer" />
        </div>
    </div>
</template>
