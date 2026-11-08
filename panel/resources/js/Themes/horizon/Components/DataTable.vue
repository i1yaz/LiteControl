<script setup>
defineProps({
    headers: {
        type: Array,
        default: () => [],
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
    <div class="border border-slate-800 rounded-xl bg-slate-900/60 overflow-hidden shadow-sm backdrop-blur">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="bg-slate-900/90 border-b border-slate-800 text-slate-400 font-medium text-xs">
                    <tr>
                        <slot name="header">
                            <th
                                v-for="(col, idx) in headers"
                                :key="idx"
                                :class="[
                                    'py-3.5 px-4 font-semibold',
                                    typeof col === 'object' ? col.class : ''
                                ]"
                            >
                                {{ typeof col === 'object' ? col.label : col }}
                            </th>
                        </slot>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/80 text-slate-200">
                    <slot />
                    <tr v-if="itemsCount === 0">
                        <td :colspan="headers.length || 10" class="py-10 text-center text-slate-500">
                            <slot name="empty">
                                {{ emptyMessage }}
                            </slot>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div v-if="$slots.footer" class="p-4 border-t border-slate-800 bg-slate-900/80">
            <slot name="footer" />
        </div>
    </div>
</template>
