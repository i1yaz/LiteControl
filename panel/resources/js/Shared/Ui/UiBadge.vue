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
    <component :is="ThemeOverride" v-if="ThemeOverride" v-bind="{ ...$props, ...$attrs }">
        <template v-for="(_, slot) in $slots" #[slot]="slotProps">
            <slot :name="slot" v-bind="slotProps" />
        </template>
    </component>
    <span v-else v-bind="$attrs" :class="['inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium border', variantClasses]">
        <slot />
    </span>
</template>
