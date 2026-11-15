<script setup lang="ts">
import { useTranslation } from '@shared/Composables/useTranslation';
import { Languages } from '@lucide/vue';

const { __, locale, availableLocales, switchLocale } = useTranslation();
</script>

<template>
    <div v-if="availableLocales.length > 0" class="inline-flex items-center gap-1" :title="__('Language')">
        <Languages class="w-4 h-4 opacity-70" />
        <div class="inline-flex rounded-md border border-base-300/60 bg-base-200/50 p-0.5">
            <button
                v-for="item in availableLocales"
                :key="item.code"
                type="button"
                class="px-2 py-0.5 text-xs font-medium rounded transition-colors cursor-pointer"
                :class="
                    item.code === locale
                        ? 'bg-primary text-primary-content shadow-xs'
                        : 'text-base-content/70 hover:text-base-content hover:bg-base-300/50'
                "
                @click="switchLocale(item.code)"
            >
                {{ item.code.toUpperCase() }}
            </button>
        </div>
    </div>
</template>
