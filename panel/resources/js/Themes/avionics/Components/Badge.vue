<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps({
    variant: {
        type: String,
        default: 'neutral', // success | warning | danger | info | neutral | primary
    },
});

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
            return 'bg-theme-bg-surface text-theme-text-secondary border-theme-border-strong';
    }
});
</script>

<template>
    <span :class="['inline-flex items-center gap-1.5 px-2 py-0.5 rounded-theme-badge text-[10px] font-mono font-bold uppercase tracking-wider border', variantClasses]">
        <slot />
    </span>
</template>
