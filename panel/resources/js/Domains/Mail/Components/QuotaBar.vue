<script setup>
import { computed } from 'vue';

const props = defineProps({
    usedBytes: {
        type: Number,
        default: 0,
    },
    quotaMb: {
        type: Number,
        default: 0,
    },
    showLabel: {
        type: Boolean,
        default: true,
    },
});

const formatBytes = (bytes) => {
    const safeBytes = Math.max(0, Number(bytes) || 0);
    if (safeBytes < 1024) return `${safeBytes} B`;
    if (safeBytes < 1024 * 1024) return `${(safeBytes / 1024).toFixed(1)} KB`;
    if (safeBytes < 1024 * 1024 * 1024) return `${(safeBytes / (1024 * 1024)).toFixed(1)} MB`;
    return `${(safeBytes / (1024 * 1024 * 1024)).toFixed(2)} GB`;
};

const formatQuotaMb = (mb) => {
    const safeMb = Number(mb) || 0;
    if (safeMb <= 0) return 'Unlimited';
    if (safeMb >= 1024) return `${(safeMb / 1024).toFixed(safeMb % 1024 === 0 ? 0 : 1)} GB`;
    return `${safeMb} MB`;
};

const quotaBytes = computed(() => Math.max(0, Number(props.quotaMb) || 0) * 1024 * 1024);

const percentage = computed(() => {
    if (quotaBytes.value <= 0) return 0;
    return Math.min(100, Math.round((Math.max(0, Number(props.usedBytes) || 0) / quotaBytes.value) * 100));
});

const severity = computed(() => {
    if (percentage.value >= 95) return 'danger';
    if (percentage.value >= 80) return 'warning';
    return 'normal';
});

const barColorClass = computed(() => {
    if (severity.value === 'danger') return 'bg-theme-danger';
    if (severity.value === 'warning') return 'bg-theme-warning';
    return 'bg-theme-accent';
});

const textColorClass = computed(() => {
    if (severity.value === 'danger') return 'text-theme-danger-text font-semibold';
    if (severity.value === 'warning') return 'text-theme-warning-text font-semibold';
    return 'text-theme-text-secondary';
});
</script>

<template>
    <div class="w-full min-w-[130px] space-y-1">
        <div v-if="showLabel" class="flex items-center justify-between gap-2 text-[11px]">
            <span :class="textColorClass">
                {{ formatBytes(usedBytes) }} / {{ formatQuotaMb(quotaMb) }}
            </span>
            <span v-if="quotaMb > 0" :class="textColorClass">
                {{ percentage }}%
            </span>
        </div>
        <div
            role="progressbar"
            :aria-valuenow="percentage"
            aria-valuemin="0"
            aria-valuemax="100"
            :aria-label="`Quota usage ${percentage}%`"
            class="w-full h-2 rounded-full bg-theme-bg-surface border border-theme-border-subtle overflow-hidden"
        >
            <div
                class="h-full rounded-full transition-all duration-300"
                :class="barColorClass"
                :style="{ width: `${percentage}%` }"
            />
        </div>
    </div>
</template>
