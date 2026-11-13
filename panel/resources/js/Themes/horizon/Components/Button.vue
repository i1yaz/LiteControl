<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps({
    variant: {
        type: String,
        default: 'primary', // primary | secondary | danger | ghost
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
            return 'px-2.5 py-1 text-xs';
        case 'sm':
            return 'px-3 py-1.5 text-xs';
        case 'lg':
            return 'px-5 py-2.5 text-base';
        case 'md':
        default:
            return 'px-4 py-2 text-sm';
    }
});

const variantClasses = computed(() => {
    switch (props.variant) {
        case 'secondary':
            return 'bg-slate-800 hover:bg-slate-700 text-slate-200 border border-slate-700/80 hover:text-white shadow-sm';
        case 'danger':
            return 'bg-rose-600 hover:bg-rose-500 text-white shadow-sm';
        case 'ghost':
            return 'bg-transparent hover:bg-slate-800 text-slate-400 hover:text-white';
        case 'primary':
        default:
            return 'bg-sky-600 hover:bg-sky-500 text-white font-medium shadow-sm shadow-sky-500/20';
    }
});
</script>

<template>
    <button
        :type="type"
        :disabled="disabled || loading"
        :class="[
            'inline-flex items-center justify-center gap-2 rounded-lg font-medium transition-all focus:outline-none focus:ring-2 focus:ring-sky-500/30 disabled:opacity-50 disabled:cursor-not-allowed cursor-pointer',
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
