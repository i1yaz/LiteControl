<script setup lang="ts">
import { shallowRef, computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { Cpu, RefreshCw } from '@lucide/vue';
import { confirmDialog } from '@shared/Composables/useDialog';
import UiPageHeader from '@shared/Ui/UiPageHeader.vue';
import UiInput from '@shared/Ui/UiInput.vue';
import UiButton from '@shared/Ui/UiButton.vue';
import UiDataTable from '@shared/Ui/UiDataTable.vue';

const props = defineProps({
    processes: Array,
});

const search = shallowRef('');

const filteredProcesses = computed(() => {
    if (!search.value) return props.processes || [];
    const q = search.value.toLowerCase();
    return (props.processes || []).filter(p =>
        (p.command && p.command.toLowerCase().includes(q)) ||
        (p.user && p.user.toLowerCase().includes(q)) ||
        String(p.pid).includes(q)
    );
});

const killProcess = async (pid) => {
    if (await confirmDialog({
        title: 'Terminate Process',
        message: `Are you sure you want to terminate PID ${pid}?`,
        confirmText: 'Terminate',
        variant: 'danger',
    })) {
        router.post(`/server/processes/${pid}/kill`);
    }
};

const refresh = () => {
    router.reload({ only: ['processes'] });
};
</script>

<template>
    <Head :title="__('Process Manager')" />

    <div class="space-y-6">
        <UiPageHeader
            :title="__('Process Manager')"
            subtitle="Active Linux server processes sorted by CPU and memory consumption."
        >
            <template #icon>
                <Cpu class="w-6 h-6 text-theme-accent" />
            </template>
            <template #actions>
                <UiInput
                    v-model="search"
                    type="text"
                    :placeholder="__('Filter processes...')"
                />
                <UiButton
                    variant="secondary"
                    @click="refresh"
                    :title="__('Refresh')"
                >
                    <RefreshCw class="w-4 h-4" />
                </UiButton>
            </template>
        </UiPageHeader>

        <!-- Process Table -->
        <UiDataTable
            :headers="[
                __('PID'),
                __('User'),
                __('CPU %'),
                __('Mem %'),
                __('Command'),
                { label: __('Actions'), class: 'text-right' }
            ]"
            :items-count="filteredProcesses.length"
            empty-message="No processes found matching your filter."
        >
            <tr v-for="p in filteredProcesses" :key="p.pid" class="hover:bg-theme-bg-surface/40 transition-colors font-mono text-xs">
                <td class="px-4 py-3 text-theme-info font-semibold">{{ p.pid }}</td>
                <td class="px-4 py-3 text-theme-text-secondary font-sans">{{ p.user }}</td>
                <td class="px-4 py-3">
                    <span :class="Number(p.cpu) > 10 ? 'text-theme-warning-text font-bold' : 'text-theme-text-secondary'">
                        {{ p.cpu }}%
                    </span>
                </td>
                <td class="px-4 py-3 text-theme-text-secondary">{{ p.mem }}%</td>
                <td class="px-4 py-3 max-w-md truncate text-theme-text-primary" :title="p.command">
                    {{ p.command }}
                </td>
                <td class="px-4 py-3 text-right font-sans">
                    <UiButton
                        size="xs"
                        variant="danger-soft"
                        @click="killProcess(p.pid)"
                    >
                        Kill
                    </UiButton>
                </td>
            </tr>
        </UiDataTable>
    </div>
</template>
