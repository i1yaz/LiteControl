<script setup lang="ts">
import { computed } from 'vue';
import { useTheme } from '@/Composables/useTheme';

const { resolveComponent } = useTheme();
const concrete = computed(() => resolveComponent('Badge'));
</script>

<template>
    <component :is="concrete" v-if="concrete" v-bind="$attrs">
        <template v-for="(_, slot) in $slots" #[slot]="slotProps">
            <slot :name="slot" v-bind="slotProps" />
        </template>
    </component>
    <span v-else class="px-2 py-0.5 rounded text-xs bg-slate-800 text-slate-200" v-bind="$attrs">
        <slot />
    </span>
</template>
