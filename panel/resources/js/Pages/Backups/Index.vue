<script setup>
import { shallowRef } from 'vue';
import { Head, useForm, router, usePage } from '@inertiajs/vue3';
import { confirmDialog } from '@/Composables/useDialog';
import Modal from '@/Components/Modal.vue';
import {
    FolderTree,
    Plus,
    RotateCcw,
    Trash2,
    Calendar,
    HardDrive,
    X,
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
    <Head title="Backup & Restore" />

    <div class="space-y-8">
            <!-- Header -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 class="text-xl sm:text-2xl font-bold text-white flex items-center gap-2.5">
                        <FolderTree class="w-6 h-6 text-cyan-400" />
                        <span>Backup & Restore</span>
                    </h1>
                    <p class="text-xs sm:text-sm text-slate-400 mt-1">
                        Create full account `.tar.gz` snapshots containing website files, MySQL databases, and virtual host configurations.
                    </p>
                </div>

                <div class="flex items-center gap-3">
                    <button
                        @click="showScheduleModal = true"
                        class="inline-flex items-center gap-2 px-3.5 py-2 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-semibold transition-colors border border-slate-700"
                    >
                        <Calendar class="w-3.5 h-3.5 text-indigo-400" />
                        <span>Schedule Backups</span>
                    </button>

                    <button
                        @click="triggerBackup"
                        :disabled="backupForm.processing"
                        class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-cyan-600 hover:bg-cyan-500 disabled:opacity-50 text-white text-sm font-semibold transition-colors shadow-sm shadow-cyan-600/30"
                    >
                        <Plus class="w-4 h-4" />
                        <span>{{ backupForm.processing ? 'Generating Snapshot...' : 'Create Backup Now' }}</span>
                    </button>
                </div>
            </div>

            <!-- Backups List Table -->
            <div class="space-y-4">
                <h2 class="text-sm font-semibold text-slate-300 uppercase tracking-wider flex items-center gap-2">
                    <HardDrive class="w-4 h-4 text-cyan-400" />
                    <span>Available Backup Archives ({{ backups.length }})</span>
                </h2>

                <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden shadow-sm">
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm text-slate-300">
                            <thead class="bg-slate-950/50 text-xs font-semibold text-slate-400 uppercase tracking-wider border-b border-slate-800">
                                <tr>
                                    <th class="px-5 py-3">Archive File</th>
                                    <th v-if="isAdmin" class="px-5 py-3">User</th>
                                    <th class="px-5 py-3">Size</th>
                                    <th class="px-5 py-3">Type</th>
                                    <th class="px-5 py-3">Created</th>
                                    <th class="px-5 py-3 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-800/60 font-mono text-xs">
                                <tr v-for="b in backups" :key="b.id" class="hover:bg-slate-800/40 transition-colors">
                                    <td class="px-5 py-3.5 font-semibold text-white flex items-center gap-2">
                                        <HardDrive class="w-4 h-4 text-cyan-400" />
                                        <span>{{ b.filename }}</span>
                                    </td>
                                    <td v-if="isAdmin" class="px-5 py-3.5 text-slate-400">
                                        {{ b.user?.username || '—' }}
                                    </td>
                                    <td class="px-5 py-3.5 text-slate-400">
                                        {{ Math.round((b.size_bytes || 0) / 1024 / 1024) }} MB
                                    </td>
                                    <td class="px-5 py-3.5">
                                        <span class="px-2 py-0.5 rounded text-[11px] font-semibold uppercase bg-slate-800 text-slate-300 border border-slate-700">
                                            {{ b.type }}
                                        </span>
                                    </td>
                                    <td class="px-5 py-3.5 text-slate-400">
                                        {{ b.created_at ? new Date(b.created_at).toLocaleString() : '—' }}
                                    </td>
                                    <td class="px-5 py-3.5 text-right font-sans">
                                        <div class="flex items-center justify-end gap-2">
                                            <button
                                                @click="triggerRestore(b.path, b.filename)"
                                                title="Restore Backup"
                                                class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-cyan-600/20 text-cyan-300 hover:bg-cyan-600/30 text-xs font-semibold transition-colors"
                                            >
                                                <RotateCcw class="w-3.5 h-3.5" />
                                                <span>Restore</span>
                                            </button>

                                            <button
                                                @click="deleteBackup(b.id, b.filename)"
                                                title="Delete"
                                                class="p-1.5 rounded-lg bg-slate-800 hover:bg-rose-500/20 text-rose-400 transition-colors"
                                            >
                                                <Trash2 class="w-3.5 h-3.5" />
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <tr v-if="!backups || backups.length === 0">
                                    <td :colspan="isAdmin ? 6 : 5" class="px-5 py-8 text-center text-slate-500 text-sm font-sans">
                                        No backups created yet. Click "Create Backup Now" to generate a snapshot.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Schedules Table -->
            <div class="space-y-4 pt-4 border-t border-slate-800">
                <h2 class="text-sm font-semibold text-slate-300 uppercase tracking-wider flex items-center gap-2">
                    <Calendar class="w-4 h-4 text-indigo-400" />
                    <span>Automated Backup Schedules ({{ schedules.length }})</span>
                </h2>

                <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden shadow-sm">
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm text-slate-300">
                            <thead class="bg-slate-950/50 text-xs font-semibold text-slate-400 uppercase tracking-wider border-b border-slate-800">
                                <tr>
                                    <th class="px-5 py-3">Frequency</th>
                                    <th v-if="isAdmin" class="px-5 py-3">User</th>
                                    <th class="px-5 py-3">Retention</th>
                                    <th class="px-5 py-3">Destination</th>
                                    <th class="px-5 py-3">Status</th>
                                    <th class="px-5 py-3 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-800/60 text-xs">
                                <tr v-for="s in schedules" :key="s.id" class="hover:bg-slate-800/40 transition-colors">
                                    <td class="px-5 py-3.5 font-semibold text-white capitalize flex items-center gap-2">
                                        <Clock class="w-3.5 h-3.5 text-indigo-400" />
                                        <span>{{ s.frequency }}</span>
                                    </td>
                                    <td v-if="isAdmin" class="px-5 py-3.5 text-slate-400">
                                        {{ s.user?.username || '—' }}
                                    </td>
                                    <td class="px-5 py-3.5 text-slate-400">
                                        Keep last {{ s.retention_count }} backups
                                    </td>
                                    <td class="px-5 py-3.5 text-slate-300 font-mono text-[11px] uppercase">
                                        {{ s.destination }}
                                    </td>
                                    <td class="px-5 py-3.5">
                                        <span class="inline-flex items-center gap-1 text-emerald-400 font-semibold">
                                            <CheckCircle2 class="w-3.5 h-3.5" />
                                            Active
                                        </span>
                                    </td>
                                    <td class="px-5 py-3.5 text-right">
                                        <button
                                            @click="deleteSchedule(s.id)"
                                            class="p-1.5 rounded-lg bg-slate-800 hover:bg-rose-500/20 text-rose-400 transition-colors"
                                        >
                                            <Trash2 class="w-3.5 h-3.5" />
                                        </button>
                                    </td>
                                </tr>
                                <tr v-if="!schedules || schedules.length === 0">
                                    <td :colspan="isAdmin ? 6 : 5" class="px-5 py-8 text-center text-slate-500 text-sm">
                                        No automated backup schedules configured.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Schedule Modal -->
            <Modal :show="showScheduleModal" max-width="md" @close="showScheduleModal = false">
                <div class="p-6 space-y-4">
                    <div class="flex items-center justify-between border-b border-slate-800 pb-3">
                        <h3 class="text-base font-bold text-white">Configure Automated Backups</h3>
                        <button @click="showScheduleModal = false" class="text-slate-400 hover:text-white">
                            <X class="w-5 h-5" />
                        </button>
                    </div>

                    <form @submit.prevent="submitSchedule" class="space-y-4">
                        <div>
                            <label class="block text-xs font-semibold text-slate-300 uppercase mb-1">Frequency</label>
                            <select
                                v-model="scheduleForm.frequency"
                                class="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-lg text-sm text-slate-200 focus:outline-none focus:border-cyan-500"
                            >
                                <option value="daily">Daily</option>
                                <option value="weekly">Weekly</option>
                                <option value="monthly">Monthly</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-300 uppercase mb-1">Retention Count</label>
                            <input
                                v-model="scheduleForm.retention_count"
                                type="number"
                                min="1"
                                max="30"
                                required
                                class="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-lg text-sm text-slate-200 focus:outline-none focus:border-cyan-500"
                            />
                            <p class="text-[11px] text-slate-500 mt-1">Older archives beyond this limit are automatically pruned.</p>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-300 uppercase mb-1">Storage Destination</label>
                            <select
                                v-model="scheduleForm.destination"
                                class="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-lg text-sm text-slate-200 focus:outline-none focus:border-cyan-500"
                            >
                                <option value="local">Local (/home/user/backups)</option>
                                <option value="sftp">Remote SFTP</option>
                                <option value="s3">Amazon S3 / S3-Compatible</option>
                            </select>
                        </div>

                        <div class="pt-4 flex items-center justify-end gap-3 border-t border-slate-800">
                            <button
                                type="button"
                                @click="showScheduleModal = false"
                                class="px-4 py-2 rounded-lg bg-slate-800 hover:bg-slate-700 text-sm font-medium text-slate-300"
                            >
                                Cancel
                            </button>
                            <button
                                type="submit"
                                :disabled="scheduleForm.processing"
                                class="px-5 py-2 rounded-lg bg-cyan-600 hover:bg-cyan-500 disabled:opacity-50 text-sm font-medium text-white shadow-sm"
                            >
                                Save Schedule
                            </button>
                        </div>
                    </form>
                </div>
            </Modal>
        </div>
</template>

