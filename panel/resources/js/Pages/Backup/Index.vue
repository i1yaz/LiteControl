<script setup lang="ts">
import { shallowRef } from 'vue';
import { Head, useForm, router, usePage } from '@inertiajs/vue3';
import { confirmDialog } from '@shared/Composables/useDialog';
import UiPageHeader from '@shared/Ui/UiPageHeader.vue';
import UiButton from '@shared/Ui/UiButton.vue';
import UiDataTable from '@shared/Ui/UiDataTable.vue';
import UiBadge from '@shared/Ui/UiBadge.vue';
import UiInput from '@shared/Ui/UiInput.vue';
import UiSelect from '@shared/Ui/UiSelect.vue';
import UiModal from '@shared/Ui/UiModal.vue';
import {
    FolderTree,
    Plus,
    RotateCcw,
    Trash2,
    Calendar,
    HardDrive,
    Clock,
    CheckCircle2
} from '@lucide/vue';

const props = defineProps({
    backups: Array,
    cliBackups: Array,
    schedules: Array,
    users: Array,
});

const page = usePage();
const isAdmin = page.props.auth?.user?.role === 'admin';

const showScheduleModal = shallowRef(false);

const backupForm = useForm({
    user_id: props.users?.[0]?.id || '',
});

const scheduleForm = useForm({
    frequency: 'daily',
    retention_count: 7,
    destination: 'local',
});

const triggerBackup = () => {
    backupForm.post('/backups/create');
};

const triggerRestore = async (path, name) => {
    if (await confirmDialog({
        title: 'Restore Backup',
        message: `Are you sure you want to restore backup '${name}'? Existing database and home files will be overwritten.`,
        confirmText: 'Restore',
        variant: 'warning',
    })) {
        router.post('/backups/restore', { backup_path: path });
    }
};

const deleteBackup = async (id, name) => {
    if (await confirmDialog({
        title: 'Delete Backup',
        message: `Delete backup '${name}'?`,
        confirmText: 'Delete',
        variant: 'danger',
    })) {
        router.delete(`/backups/${id}`);
    }
};

const submitSchedule = () => {
    scheduleForm.post('/backups/schedules', {
        onSuccess: () => {
            showScheduleModal.value = false;
        },
    });
};

const deleteSchedule = async (id) => {
    if (await confirmDialog({
        title: 'Delete Schedule',
        message: 'Delete this automated backup schedule?',
        confirmText: 'Delete',
        variant: 'danger',
    })) {
        router.delete(`/backups/schedules/${id}`);
    }
};
</script>

<template>
    <Head :title="__('Backup & Restore')" />

    <div class="space-y-8">
        <UiPageHeader
            :title="__('Backup & Restore')"
            subtitle="Create full account `.tar.gz` snapshots containing website files, MySQL databases, and virtual host configurations."
        >
            <template #icon>
                <FolderTree class="w-6 h-6 text-theme-accent" />
            </template>
            <template #actions>
                <UiButton
                    variant="secondary"
                    @click="showScheduleModal = true"
                >
                    <Calendar class="w-3.5 h-3.5 text-theme-info" />
                    <span>{{ __('Schedule Backups') }}</span>
                </UiButton>

                <UiButton
                    variant="primary"
                    :disabled="backupForm.processing"
                    :loading="backupForm.processing"
                    @click="triggerBackup"
                >
                    <Plus class="w-4 h-4" />
                    <span>{{ backupForm.processing ? 'Generating Snapshot...' : 'Create Backup Now' }}</span>
                </UiButton>
            </template>
        </UiPageHeader>

        <!-- Backups List Table -->
        <div class="space-y-4">
            <h2 class="text-sm font-semibold text-theme-text-secondary uppercase tracking-wider flex items-center gap-2">
                <HardDrive class="w-4 h-4 text-theme-accent" />
                <span>Available Backup Archives ({{ backups.length }})</span>
            </h2>

            <UiDataTable
                :headers="[
                    __('Archive File'),
                    ...(isAdmin ? [__('User')] : []),
                    __('Size'),
                    __('Type'),
                    __('Created'),
                    { label: __('Actions'), class: 'text-right' },
                ]"
                :items-count="backups?.length || 0"
                empty-message="No backups created yet. Click &quot;Create Backup Now&quot; to generate a snapshot."
            >
                <tr v-for="b in backups" :key="b.id" class="hover:bg-theme-bg-surface/40 transition-colors font-mono text-xs">
                    <td class="px-5 py-3.5 font-semibold text-theme-text-primary flex items-center gap-2">
                        <HardDrive class="w-4 h-4 text-theme-accent" />
                        <span>{{ b.filename }}</span>
                    </td>
                    <td v-if="isAdmin" class="px-5 py-3.5 text-theme-text-secondary">
                        {{ b.user?.username || '—' }}
                    </td>
                    <td class="px-5 py-3.5 text-theme-text-secondary">
                        {{ Math.round((b.size_bytes || 0) / 1024 / 1024) }} MB
                    </td>
                    <td class="px-5 py-3.5">
                        <UiBadge variant="neutral" class="uppercase">
                            {{ b.type }}
                        </UiBadge>
                    </td>
                    <td class="px-5 py-3.5 text-theme-text-secondary">
                        {{ b.created_at ? new Date(b.created_at).toLocaleString() : '—' }}
                    </td>
                    <td class="px-5 py-3.5 text-right font-sans">
                        <div class="flex items-center justify-end gap-2">
                            <UiButton
                                variant="secondary"
                                size="xs"
                                @click="triggerRestore(b.path, b.filename)"
                                :title="__('Restore Backup')"
                            >
                                <RotateCcw class="w-3.5 h-3.5 text-theme-accent" />
                                <span>{{ __('Restore') }}</span>
                            </UiButton>

                            <button
                                @click="deleteBackup(b.id, b.filename)"
                                :title="__('Delete')"
                                class="p-1.5 rounded-theme-md bg-theme-bg-surface hover:bg-theme-danger-bg text-theme-danger border border-theme-border hover:border-theme-danger-border transition-colors"
                            >
                                <Trash2 class="w-3.5 h-3.5" />
                            </button>
                        </div>
                    </td>
                </tr>
            </UiDataTable>
        </div>

        <!-- Schedules Table -->
        <div class="space-y-4 pt-4 border-t border-theme-border">
            <h2 class="text-sm font-semibold text-theme-text-secondary uppercase tracking-wider flex items-center gap-2">
                <Calendar class="w-4 h-4 text-theme-info" />
                <span>Automated Backup Schedules ({{ schedules.length }})</span>
            </h2>

            <UiDataTable
                :headers="[
                    __('Frequency'),
                    ...(isAdmin ? [__('User')] : []),
                    __('Retention'),
                    __('Destination'),
                    __('Status'),
                    { label: __('Actions'), class: 'text-right' },
                ]"
                :items-count="schedules?.length || 0"
                empty-message="No automated backup schedules configured."
            >
                <tr v-for="s in schedules" :key="s.id" class="hover:bg-theme-bg-surface/40 transition-colors text-xs">
                    <td class="px-5 py-3.5 font-semibold text-theme-text-primary capitalize flex items-center gap-2">
                        <Clock class="w-3.5 h-3.5 text-theme-info" />
                        <span>{{ s.frequency }}</span>
                    </td>
                    <td v-if="isAdmin" class="px-5 py-3.5 text-theme-text-secondary">
                        {{ s.user?.username || '—' }}
                    </td>
                    <td class="px-5 py-3.5 text-theme-text-secondary">
                        Keep last {{ s.retention_count }} backups
                    </td>
                    <td class="px-5 py-3.5 text-theme-text-secondary font-mono text-[11px] uppercase">
                        {{ s.destination }}
                    </td>
                    <td class="px-5 py-3.5">
                        <UiBadge variant="success">
                            <CheckCircle2 class="w-3.5 h-3.5" />
                            <span>Active</span>
                        </UiBadge>
                    </td>
                    <td class="px-5 py-3.5 text-right">
                        <button
                            @click="deleteSchedule(s.id)"
                            class="p-1.5 rounded-theme-md bg-theme-bg-surface hover:bg-theme-danger-bg text-theme-danger border border-theme-border hover:border-theme-danger-border transition-colors"
                        >
                            <Trash2 class="w-3.5 h-3.5" />
                        </button>
                    </td>
                </tr>
            </UiDataTable>
        </div>

        <!-- Schedule Modal -->
        <UiModal :show="showScheduleModal" :title="__('Configure Automated Backups')" max-width="md" @close="showScheduleModal = false">
            <form @submit.prevent="submitSchedule" class="space-y-4">
                <div>
                    <UiSelect
                        id="schedule_frequency"
                        v-model="scheduleForm.frequency"
                        :label="__('Frequency')"
                        :options="[
                            { value: 'daily', label: __('Daily') },
                            { value: 'weekly', label: __('Weekly') },
                            { value: 'monthly', label: __('Monthly') },
                        ]"
                    />
                </div>

                <div>
                    <UiInput
                        id="schedule_retention"
                        v-model="scheduleForm.retention_count"
                        type="number"
                        min="1"
                        max="30"
                        required
                        :label="__('Retention Count')"
                        :hint="__('Older archives beyond this limit are automatically pruned.')"
                    />
                </div>

                <div>
                    <UiSelect
                        id="schedule_destination"
                        v-model="scheduleForm.destination"
                        :label="__('Storage Destination')"
                        :options="[
                            { value: 'local', label: __('Local (/home/user/backups)') },
                            { value: 'sftp', label: __('Remote SFTP') },
                            { value: 's3', label: __('Amazon S3 / S3-Compatible') },
                        ]"
                    />
                </div>

                <div class="pt-4 flex items-center justify-end gap-3 border-t border-theme-border">
                    <UiButton
                        type="button"
                        variant="secondary"
                        @click="showScheduleModal = false"
                    >
                        Cancel
                    </UiButton>
                    <UiButton
                        type="submit"
                        variant="primary"
                        :disabled="scheduleForm.processing"
                        :loading="scheduleForm.processing"
                    >
                        Save Schedule
                    </UiButton>
                </div>
            </form>
        </UiModal>
    </div>
</template>

