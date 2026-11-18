<script setup lang="ts">
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
</script>

<template>
    <div class="bg-theme-bg-card border border-theme-border rounded-theme-lg p-4 sm:p-5 shadow-lg">
        <div v-if="title || $slots.header" class="flex items-center justify-between pb-3 mb-4 border-b border-theme-border-subtle">
            <div>
                <h3 v-if="title" class="text-sm font-bold font-mono text-theme-text-primary uppercase tracking-wider">
                    {{ title }}
                </h3>
                <p v-if="subtitle" class="text-[10px] font-mono text-theme-text-muted mt-0.5">
                    {{ subtitle }}
                </p>
            </div>
            <div v-if="$slots.header">
                <slot name="header" />
            </div>
        </div>

        <slot />

        <div v-if="$slots.footer" class="mt-4 pt-3 border-t border-theme-border-subtle">
            <slot name="footer" />
        </div>
    </div>
</template>
