<script setup lang="ts">
defineProps({
    title: {
        type: String,
        required: true,
    },
    subtitle: {
        type: String,
        default: '',
    },
});
</script>

<template>
    <div class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 border-b border-theme-border-subtle pb-4">
        <div>
            <h1 class="text-xl sm:text-2xl font-bold font-mono tracking-tight text-theme-text-primary uppercase flex items-center gap-2.5">
                <slot name="icon" />
                <span>{{ title }}</span>
            </h1>
            <p v-if="subtitle" class="text-xs font-mono text-theme-text-muted mt-1">
                {{ subtitle }}
            </p>
        </div>
        <div v-if="$slots.actions" class="flex items-center gap-2.5 flex-wrap">
            <slot name="actions" />
        </div>
    </div>
</template>
