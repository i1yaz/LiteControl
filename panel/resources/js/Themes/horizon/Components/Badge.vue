<script setup>
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
            return 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20';
        case 'warning':
            return 'bg-amber-500/10 text-amber-400 border-amber-500/20';
        case 'danger':
            return 'bg-rose-500/10 text-rose-400 border-rose-500/20';
        case 'info':
        case 'primary':
            return 'bg-sky-500/10 text-sky-400 border-sky-500/20';
        case 'neutral':
        default:
            return 'bg-slate-800 text-slate-300 border-slate-700/60';
    }
});
</script>

<template>
    <span :class="['inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium border', variantClasses]">
        <slot />
    </span>
</template>
