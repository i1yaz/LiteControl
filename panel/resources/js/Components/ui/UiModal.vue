<script setup>
import { computed } from 'vue';
import { useTheme } from '@/Composables/useTheme';

const { resolveComponent } = useTheme();
const concrete = computed(() => resolveComponent('Modal'));
</script>

<template>
    <component :is="concrete" v-if="concrete" v-bind="$attrs">
        <template v-for="(_, slot) in $slots" #[slot]="slotProps">
            <slot :name="slot" v-bind="slotProps" />
        </template>
    </component>
</template>
