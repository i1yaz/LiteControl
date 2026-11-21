<script setup>
import { computed } from 'vue';
import UiBadge from '@shared/Ui/UiBadge.vue';

const props = defineProps({
    status: {
        type: String,
        required: true,
    },
    label: {
        type: String,
        default: '',
    },
});

const STATUS_MAP = {
    active: { variant: 'success', text: 'Active', dot: 'bg-theme-success' },
    inactive: { variant: 'neutral', text: 'Inactive', dot: 'bg-theme-text-muted' },
    pending: { variant: 'warning', text: 'Pending', dot: 'bg-theme-warning' },
    provisioning: { variant: 'info', text: 'Provisioning', dot: 'bg-theme-info animate-pulse' },
    purging: { variant: 'warning', text: 'Purging', dot: 'bg-theme-warning animate-pulse' },
    rotating: { variant: 'info', text: 'Rotating', dot: 'bg-theme-info animate-pulse' },
    retired: { variant: 'neutral', text: 'Retired', dot: 'bg-theme-text-muted' },
    failed: { variant: 'danger', text: 'Failed', dot: 'bg-theme-danger' },
};

const normalized = computed(() => String(props.status || '').toLowerCase());
const meta = computed(() => STATUS_MAP[normalized.value] || {
    variant: 'neutral',
    text: props.status || 'Unknown',
    dot: 'bg-theme-text-muted',
});
</script>

<template>
    <UiBadge :variant="meta.variant">
        <span class="w-1.5 h-1.5 rounded-full shrink-0" :class="meta.dot" aria-hidden="true" />
        <span>{{ label || meta.text }}</span>
    </UiBadge>
</template>
