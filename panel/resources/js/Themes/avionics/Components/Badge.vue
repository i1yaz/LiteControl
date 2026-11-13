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
            return 'bg-emerald-950/40 text-emerald-400 border-emerald-500/30';
        case 'warning':
            return 'bg-amber-950/40 text-amber-400 border-amber-500/30';
        case 'danger':
            return 'bg-rose-950/40 text-rose-400 border-rose-500/30';
        case 'info':
            return 'bg-cyan-950/40 text-cyan-400 border-cyan-500/30';
        case 'primary':
            return 'bg-[#ff6b35]/15 text-[#ff8e62] border-[#ff6b35]/30';
        case 'neutral':
        default:
            return 'bg-[#151921] text-[#98a6b8] border-[#242c3b]';
    }
});
</script>

<template>
    <span :class="['inline-flex items-center px-2 py-0.5 rounded-xs text-[10px] font-mono font-bold uppercase tracking-wider border', variantClasses]">
        <slot />
    </span>
</template>
