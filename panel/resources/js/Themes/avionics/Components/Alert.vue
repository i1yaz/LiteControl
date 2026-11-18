<script setup lang="ts">
import { computed } from 'vue';
import { AlertCircle, CheckCircle2, Info, AlertTriangle } from '@lucide/vue';

const props = defineProps({
    variant: {
        type: String,
        default: 'info', // info | success | warning | danger
    },
});

const variantClasses = computed(() => {
    switch (props.variant) {
        case 'success':
            return 'bg-theme-success-bg border-theme-success-border text-theme-success-text';
        case 'warning':
            return 'bg-theme-warning-bg border-theme-warning-border text-theme-warning-text';
        case 'danger':
            return 'bg-theme-danger-bg border-theme-danger-border text-theme-danger-text';
        case 'info':
        default:
            return 'bg-theme-info-bg border-theme-info-border text-theme-info-text';
    }
});

const iconComponent = computed(() => {
    switch (props.variant) {
        case 'success':
            return CheckCircle2;
        case 'warning':
            return AlertTriangle;
        case 'danger':
            return AlertCircle;
        case 'info':
        default:
            return Info;
    }
});
</script>

<template>
    <div :class="['p-3.5 rounded-theme-md border font-mono text-xs flex items-start gap-3', variantClasses]">
        <component :is="iconComponent" class="w-4 h-4 shrink-0 mt-0.5" />
        <div class="flex-1">
            <slot />
        </div>
    </div>
</template>
