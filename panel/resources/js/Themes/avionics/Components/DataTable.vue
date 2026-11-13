<script setup lang="ts">
defineProps({
    headers: {
        type: Array,
        default: () => [], // ['Domain', 'Document Root', 'PHP', 'Actions'] or [{ key, label, class }]
    },
    emptyMessage: {
        type: String,
        default: 'No records found.',
    },
    itemsCount: {
        type: Number,
        default: 0,
    },
});
</script>

<template>
    <div class="border border-[#1e2634] rounded-sm bg-[#0c1017] overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left font-mono text-xs">
                <thead class="bg-[#10151e] border-b border-[#1e2634] text-[#8e9cae] uppercase tracking-wider text-[11px]">
                    <tr>
                        <slot name="header">
                            <th
                                v-for="(col, idx) in headers"
                                :key="idx"
                                :class="[
                                    'py-3 px-4 font-semibold',
                                    typeof col === 'object' ? col.class : ''
                                ]"
                            >
                                {{ typeof col === 'object' ? col.label : col }}
                            </th>
                        </slot>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#18202c] text-[#d6dce7]">
                    <slot />
                    <tr v-if="itemsCount === 0">
                        <td :colspan="headers.length || 10" class="py-8 text-center text-[#586477]">
                            <slot name="empty">
                                {{ emptyMessage }}
                            </slot>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div v-if="$slots.footer" class="p-3 border-t border-[#1e2634] bg-[#0d1219]">
            <slot name="footer" />
        </div>
    </div>
</template>
