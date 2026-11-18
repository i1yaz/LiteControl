<script setup lang="ts">
import { useTranslation } from '@shared/Composables/useTranslation';
import { Languages } from '@lucide/vue';

const { __, locale, availableLocales, switchLocale } = useTranslation();
</script>

<template>
    <div v-if="availableLocales.length > 0" class="inline-flex items-center gap-1.5" :title="__('Language')">
        <Languages class="w-4 h-4 text-theme-text-muted" />
        <div class="inline-flex rounded-theme-md border border-theme-border bg-theme-bg-surface p-0.5">
            <button
                v-for="item in availableLocales"
                :key="item.code"
                type="button"
                class="px-2 py-0.5 text-xs font-medium rounded-theme-sm theme-ui-control transition-colors cursor-pointer"
                :class="
                    item.code === locale
                        ? 'bg-theme-accent text-theme-text-on-accent font-semibold shadow-xs'
                        : 'text-theme-text-secondary hover:text-theme-text-primary hover:bg-theme-bg-card'
                "
                @click="switchLocale(item.code)"
            >
                {{ item.code.toUpperCase() }}
            </button>
        </div>
    </div>
</template>
