<script setup lang="ts">
import { computed } from 'vue';
import { useTheme } from '@shared/Composables/useTheme';

defineOptions({ inheritAttrs: false });

const props = defineProps({
    variant: {
        type: String,
        default: 'neutral', // success | warning | danger | info | neutral | primary
    },
});

const { resolveComponent } = useTheme();
const ThemeOverride = computed(() => resolveComponent('Badge'));

const variantClasses = computed(() => {
    switch (props.variant) {
        case 'success':
            return 'bg-theme-success-bg text-theme-success-text border-theme-success-border';
        case 'warning':
            return 'bg-theme-warning-bg text-theme-warning-text border-theme-warning-border';
        case 'danger':
            return 'bg-theme-danger-bg text-theme-danger-text border-theme-danger-border';
        case 'info':
            return 'bg-theme-info-bg text-theme-info-text border-theme-info-border';
        case 'primary':
            return 'bg-theme-accent-subtle text-theme-accent-text border-theme-accent-border';
        case 'neutral':
        default:
            return 'bg-theme-bg-surface text-theme-text-secondary border-theme-border';
    }
});
</script>

<template>
    <component :is="ThemeOverride" v-if="ThemeOverride" v-bind="{ ...$props, ...$attrs }">
        <template v-for="(_, slot) in $slots" #[slot]="slotProps">
            <slot :name="slot" v-bind="slotProps" />
        </template>
    </component>
    <span v-else v-bind="$attrs" :class="['inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-theme-badge text-[11px] font-semibold theme-ui-control border', variantClasses]">
        <slot />
    </span>
</template>
