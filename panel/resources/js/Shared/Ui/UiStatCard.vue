<script setup lang="ts">
import { computed } from 'vue';
import { useTheme } from '@shared/Composables/useTheme';

defineOptions({ inheritAttrs: false });

const props = defineProps({
    label: {
        type: String,
        required: true,
    },
    value: {
        type: [String, Number],
        default: '',
    },
    subtext: {
        type: String,
        default: '',
    },
    variant: {
        type: String,
        default: 'primary', // primary | success | warning | danger | info | neutral
    },
});

const { resolveComponent } = useTheme();
const ThemeOverride = computed(() => resolveComponent('StatCard'));

const iconBoxClasses = computed(() => {
    switch (props.variant) {
        case 'success':
            return 'bg-theme-success-bg text-theme-success-text border-theme-success-border';
        case 'warning':
            return 'bg-theme-warning-bg text-theme-warning-text border-theme-warning-border';
        case 'danger':
            return 'bg-theme-danger-bg text-theme-danger-text border-theme-danger-border';
        case 'info':
            return 'bg-theme-info-bg text-theme-info-text border-theme-info-border';
        case 'neutral':
            return 'bg-theme-bg-surface text-theme-text-secondary border-theme-border';
        case 'primary':
        default:
            return 'bg-theme-accent-subtle text-theme-accent-text border-theme-accent-border';
    }
});
</script>

<template>
    <component :is="ThemeOverride" v-if="ThemeOverride" v-bind="{ ...$props, ...$attrs }">
        <template v-for="(_, slot) in $slots" #[slot]="slotProps">
            <slot :name="slot" v-bind="slotProps" />
        </template>
    </component>
    <div v-else v-bind="$attrs" class="p-4 rounded-theme-lg bg-theme-bg-card border border-theme-border flex items-center gap-3.5 shadow-xs">
        <div v-if="$slots.icon" :class="['p-2.5 rounded-theme-md border shrink-0 flex items-center justify-center', iconBoxClasses]">
            <slot name="icon" />
        </div>
        <div class="min-w-0 flex-1">
            <p class="text-[11px] font-medium text-theme-text-muted uppercase tracking-wider theme-ui-control truncate">
                {{ label }}
            </p>
            <p class="text-sm sm:text-base font-bold text-theme-text-primary truncate mt-0.5">
                <slot>{{ value }}</slot>
            </p>
            <p v-if="subtext" class="text-[11px] text-theme-text-secondary truncate mt-0.5">
                {{ subtext }}
            </p>
        </div>
    </div>
</template>
