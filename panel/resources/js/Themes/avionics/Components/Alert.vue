<script setup>
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
            return 'bg-emerald-950/40 border-emerald-500/30 text-emerald-300';
        case 'warning':
            return 'bg-amber-950/40 border-amber-500/30 text-amber-300';
        case 'danger':
            return 'bg-rose-950/40 border-rose-500/30 text-rose-300';
        case 'info':
        default:
            return 'bg-cyan-950/40 border-cyan-500/30 text-cyan-300';
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
    <div :class="['p-3.5 rounded-sm border font-mono text-xs flex items-center gap-3', variantClasses]">
        <component :is="iconComponent" class="w-4 h-4 flex-shrink-0" />
        <div class="flex-1">
            <slot />
        </div>
    </div>
</template>
