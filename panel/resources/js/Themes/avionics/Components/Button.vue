<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps({
    variant: {
        type: String,
        default: 'primary', // primary | secondary | danger | danger-soft | warning | success | ghost
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

const sizeClasses = computed(() => {
    switch (props.size) {
        case 'xs':
            return 'px-2 py-0.5 text-[10px]';
        case 'sm':
            return 'px-2.5 py-1 text-xs';
        case 'lg':
            return 'px-5 py-2.5 text-sm';
        case 'md':
        default:
            return 'px-3.5 py-1.5 text-xs';
    }
});

const variantClasses = computed(() => {
    switch (props.variant) {
        case 'secondary':
            return 'bg-theme-bg-surface hover:bg-theme-border border border-theme-border-strong text-theme-text-primary';
        case 'danger':
            return 'bg-theme-danger hover:bg-theme-danger-hover border border-theme-danger text-white font-bold';
        case 'danger-soft':
            return 'bg-theme-danger-bg hover:bg-theme-danger/25 border border-theme-danger-border text-theme-danger-text';
        case 'warning':
            return 'bg-theme-warning-bg hover:bg-theme-warning/25 border border-theme-warning-border text-theme-warning-text';
        case 'success':
            return 'bg-theme-success hover:bg-theme-success-hover border border-theme-success text-black font-bold';
        case 'ghost':
            return 'bg-transparent hover:bg-theme-bg-surface text-theme-text-secondary hover:text-theme-text-primary border border-transparent';
        case 'primary':
        default:
            return 'bg-theme-accent hover:bg-theme-accent-hover text-theme-text-on-accent font-bold border border-theme-accent';
    }
});
</script>

<template>
    <button
        :type="type"
        :disabled="disabled || loading"
        :class="[
            'inline-flex items-center justify-center gap-2 rounded-theme-md font-mono uppercase tracking-wider transition-all focus:outline-none disabled:opacity-50 disabled:cursor-not-allowed cursor-pointer',
            sizeClasses,
            variantClasses
        ]"
    >
        <svg
            v-if="loading"
            class="animate-spin -ml-1 mr-1 h-3.5 w-3.5 text-current"
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
