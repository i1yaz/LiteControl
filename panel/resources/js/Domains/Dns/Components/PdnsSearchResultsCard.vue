<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Search, X, FileText, Globe, MessageSquare } from '@lucide/vue';
import UiCard from '@shared/Ui/UiCard.vue';
import UiBadge from '@shared/Ui/UiBadge.vue';
import UiButton from '@shared/Ui/UiButton.vue';
import UiDataTable from '@shared/Ui/UiDataTable.vue';

defineProps({
    query: {
        type: String,
        required: true,
    },
    results: {
        type: Array as () => Array<Record<string, any>>,
        default: () => [],
    },
});

const emit = defineEmits(['clear']);

const headers = [
    { label: 'Match Type' },
    { label: 'Zone' },
    { label: 'Record Name' },
    { label: 'RR Type' },
    { label: 'TTL' },
    { label: 'Content / Comment' },
];

const objectTypeBadgeVariant = (type: string) => {
    switch (type) {
        case 'zone':
            return 'primary';
        case 'comment':
            return 'warning';
        default:
            return 'info';
    }
};
</script>

<template>
    <UiCard
        :title="__('Cross-Zone Search Results')"
        :subtitle="__('Live PowerDNS /search-data matches across zones, RRSets, and comments for: ') + query"
    >
        <template #header>
            <div class="flex items-center gap-2">
                <UiBadge variant="primary">
                    <Search class="w-3.5 h-3.5" />
                    <span>{{ results.length }} {{ __('matches') }}</span>
                </UiBadge>
                <UiButton
                    type="button"
                    variant="secondary"
                    size="sm"
                    @click="emit('clear')"
                >
                    <X class="w-3.5 h-3.5" />
                    <span>{{ __('Clear Search') }}</span>
                </UiButton>
            </div>
        </template>

        <UiDataTable
            :headers="headers"
            :items-count="results.length"
            :empty-message="__('No records, comments, or zones matched your global search query.')"
        >
            <tr
                v-for="(item, index) in results"
                :key="`${item.zone_id}-${item.name}-${item.type}-${index}`"
                class="hover:bg-theme-bg-surface/40 transition-colors"
            >
                <td class="py-3 px-4">
                    <UiBadge :variant="objectTypeBadgeVariant(item.object_type)">
                        <Globe v-if="item.object_type === 'zone'" class="w-3.5 h-3.5" />
                        <MessageSquare v-else-if="item.object_type === 'comment'" class="w-3.5 h-3.5" />
                        <FileText v-else class="w-3.5 h-3.5" />
                        <span class="uppercase">{{ item.object_type || 'record' }}</span>
                    </UiBadge>
                </td>
                <td class="py-3 px-4 font-mono text-xs text-theme-text-primary">
                    <Link
                        v-if="item.zone_id || item.zone"
                        :href="`/dns/zones/${encodeURIComponent(String(item.zone_id || item.zone))}/records`"
                        class="text-theme-accent hover:underline font-semibold"
                    >
                        {{ item.zone_id || item.zone }}
                    </Link>
                    <span v-else>—</span>
                </td>
                <td class="py-3 px-4 font-mono text-xs text-theme-text-primary">
                    <div>{{ item.name || '—' }}</div>
                    <div
                        v-if="item.name_display && item.name_display !== item.name.replace(/\.$/, '')"
                        class="text-[11px] text-theme-text-muted"
                    >
                        {{ item.name_display }}
                    </div>
                </td>
                <td class="py-3 px-4">
                    <UiBadge v-if="item.type" variant="neutral">
                        <span class="font-mono">{{ item.type }}</span>
                    </UiBadge>
                    <span v-else class="text-theme-text-muted">—</span>
                </td>
                <td class="py-3 px-4 font-mono text-xs text-theme-text-secondary">
                    {{ item.ttl !== null && item.ttl !== undefined ? `${item.ttl}s` : '—' }}
                </td>
                <td class="py-3 px-4 font-mono text-xs text-theme-text-primary break-all max-w-md">
                    <div class="flex items-center gap-2">
                        <span>{{ item.content || '—' }}</span>
                        <UiBadge v-if="item.disabled" variant="warning">
                            {{ __('Disabled') }}
                        </UiBadge>
                    </div>
                </td>
            </tr>
        </UiDataTable>
    </UiCard>
</template>
