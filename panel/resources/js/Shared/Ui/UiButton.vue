<script setup lang="ts">
import { computed } from 'vue';
import { useTheme } from '@shared/Composables/useTheme';

defineOptions({ inheritAttrs: false });

const props = defineProps({
    variant: {
        type: String,
        default: 'primary', // primary | secondary | danger | warning | success | ghost
    },
    size: {
        type: String,
        default: 'md', // xs | sm | md | lg
    },
    type: {
        type: String,
        default: 'button',
    },
    disabled: {
        type: Boolean,
        default: false,
    },
    loading: {
        type: Boolean,
        default: false,
    },
});

const { resolveComponent } = useTheme();
const ThemeOverride = computed(() => resolveComponent('Button'));

const sizeClasses = computed(() => {
    switch (props.size) {
        case 'xs':
            return 'px-2.5 py-1 text-xs';
        case 'sm':
            return 'px-3 py-1.5 text-xs';
        case 'lg':
            return 'px-5 py-2.5 text-sm';
        case 'md':
        default:
            return 'px-4 py-2 text-xs sm:text-sm';
    }
});

const variantClasses = computed(() => {
    switch (props.variant) {
        case 'secondary':
            return 'bg-theme-bg-surface hover:bg-theme-border text-theme-text-secondary hover:text-theme-text-primary border border-theme-border';
        case 'danger':
            return 'bg-theme-danger hover:bg-theme-danger-hover text-white border border-theme-danger';
        case 'danger-soft':
            return 'bg-theme-danger-bg hover:bg-theme-danger/20 text-theme-danger-text border border-theme-danger-border';
        case 'warning':
            return 'bg-theme-warning-bg hover:bg-theme-warning/20 text-theme-warning-text border border-theme-warning-border';
        case 'success':
            return 'bg-theme-success hover:bg-theme-success-hover text-white border border-theme-success';
        case 'ghost':
            return 'bg-transparent hover:bg-theme-bg-surface text-theme-text-secondary hover:text-theme-text-primary border border-transparent';
        case 'primary':
        default:
            return 'bg-theme-accent hover:bg-theme-accent-hover text-theme-text-on-accent font-semibold border border-theme-accent shadow-xs';
    }
});
</script>

<template>
    <component :is="ThemeOverride" v-if="ThemeOverride" v-bind="{ ...$props, ...$attrs }">
        <template v-for="(_, slot) in $slots" #[slot]="slotProps">
            <slot :name="slot" v-bind="slotProps" />
        </template>
    </component>
    <button
        v-else
        v-bind="$attrs"
        :type="type"
        :disabled="disabled || loading"
        :class="[
            'inline-flex items-center justify-center gap-2 rounded-theme-md font-medium theme-ui-control transition-all focus:outline-none focus:ring-2 focus:ring-theme-accent/30 disabled:opacity-50 disabled:cursor-not-allowed cursor-pointer',
            sizeClasses,
            variantClasses
        ]"
    >
        <svg
            v-if="loading"
            class="animate-spin -ml-1 mr-1 h-4 w-4 text-current"
            xmlns="http://www.w3.org/2000/svg"
            fill="none"
            viewBox="0 0 24 24"
        >
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
        </svg>
        <slot />
    </button>
</template>
