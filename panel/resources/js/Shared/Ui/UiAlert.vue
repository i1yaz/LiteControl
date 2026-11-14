<script setup lang="ts">
import { computed } from 'vue';
import { AlertCircle, CheckCircle2, Info, AlertTriangle } from '@lucide/vue';
import { useTheme } from '@shared/Composables/useTheme';

defineOptions({ inheritAttrs: false });

const props = defineProps({
    variant: {
        type: String,
        default: 'info', // info | success | warning | danger
    },
});

const { resolveComponent } = useTheme();
const ThemeOverride = computed(() => resolveComponent('Alert'));

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
    <component :is="ThemeOverride" v-if="ThemeOverride" v-bind="{ ...$props, ...$attrs }">
        <template v-for="(_, slot) in $slots" #[slot]="slotProps">
            <slot :name="slot" v-bind="slotProps" />
        </template>
    </component>
    <div v-else v-bind="$attrs" :class="['p-4 rounded-xl border text-sm flex items-center gap-3.5', variantClasses]">
        <component :is="iconComponent" class="w-5 h-5 flex-shrink-0" />
        <div class="flex-1">
            <slot />
        </div>
    </div>
</template>
