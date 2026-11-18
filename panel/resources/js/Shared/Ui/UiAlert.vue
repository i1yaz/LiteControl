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
    <component :is="ThemeOverride" v-if="ThemeOverride" v-bind="{ ...$props, ...$attrs }">
        <template v-for="(_, slot) in $slots" #[slot]="slotProps">
            <slot :name="slot" v-bind="slotProps" />
        </template>
    </component>
    <div v-else v-bind="$attrs" :class="['p-4 rounded-theme-lg border text-xs sm:text-sm flex items-start gap-3', variantClasses]">
        <component :is="iconComponent" class="w-4 h-4 sm:w-5 sm:h-5 shrink-0 mt-0.5" />
        <div class="flex-1">
            <slot />
        </div>
    </div>
</template>
