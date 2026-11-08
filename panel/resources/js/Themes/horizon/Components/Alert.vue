<script setup>
import { computed } from 'vue';
import { AlertCircle, CheckCircle2, Info, AlertTriangle } from 'lucide-vue-next';

const props = defineProps({
    variant: {
        type: String,
        default: 'info', // info | success | warning | danger
    },
});

const variantClasses = computed(() => {
    switch (props.variant) {
        case 'success':
            return 'bg-emerald-500/10 border-emerald-500/20 text-emerald-300';
        case 'warning':
            return 'bg-amber-500/10 border-amber-500/20 text-amber-300';
        case 'danger':
            return 'bg-rose-500/10 border-rose-500/20 text-rose-300';
        case 'info':
        default:
            return 'bg-sky-500/10 border-sky-500/20 text-sky-300';
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
    <div :class="['p-4 rounded-xl border text-sm flex items-center gap-3.5', variantClasses]">
        <component :is="iconComponent" class="w-5 h-5 flex-shrink-0" />
        <div class="flex-1">
            <slot />
        </div>
    </div>
</template>
