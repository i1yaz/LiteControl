<script setup lang="ts">
import { computed } from 'vue';
import { useTheme } from '@shared/Composables/useTheme';

defineOptions({ inheritAttrs: false });

defineProps({
    title: {
        type: String,
        default: '',
    },
    subtitle: {
        type: String,
        default: '',
    },
});

const { resolveComponent } = useTheme();
const ThemeOverride = computed(() => resolveComponent('Card'));
</script>

<template>
    <component :is="ThemeOverride" v-if="ThemeOverride" v-bind="{ ...$props, ...$attrs }">
        <template v-for="(_, slot) in $slots" #[slot]="slotProps">
            <slot :name="slot" v-bind="slotProps" />
        </template>
    </component>
    <div v-else v-bind="$attrs" class="bg-slate-900/90 border border-slate-800 rounded-xl p-5 sm:p-6 shadow-sm backdrop-blur">
        <div v-if="title || $slots.header" class="flex items-center justify-between pb-4 mb-5 border-b border-slate-800/80">
            <div>
                <h3 v-if="title" class="text-base font-semibold text-white">
                    {{ title }}
                </h3>
                <p v-if="subtitle" class="text-xs text-slate-400 mt-0.5">
                    {{ subtitle }}
                </p>
            </div>
            <div v-if="$slots.header">
                <slot name="header" />
            </div>
        </div>

        <slot />

        <div v-if="$slots.footer" class="mt-5 pt-4 border-t border-slate-800/80">
            <slot name="footer" />
        </div>
    </div>
</template>
