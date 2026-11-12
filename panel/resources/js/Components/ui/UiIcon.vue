<script setup>
import { computed } from 'vue';
import { useTheme } from '@/Composables/useTheme';
import { HelpCircle } from '@lucide/vue';

const props = defineProps({
    name: {
        type: String,
        required: true,
    },
});

const { resolveIcon } = useTheme();
const iconComponent = computed(() => resolveIcon(props.name) || HelpCircle);
</script>

<template>
    <component :is="iconComponent" v-bind="$attrs" />
</template>
