<script setup lang="ts">
import { shallowRef, computed, onMounted, onUnmounted } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { confirmDialog, alertDialog } from '@shared/Composables/useDialog';
import UiModal from '@shared/Ui/UiModal.vue';
import {
    FolderTree,
    Folder,
    FileText,
    FileCode,
    File,
    Upload,
    Plus,
    Trash2,
    Edit3,
    ArrowUp,
    RefreshCw,
    Save,
    HardDrive,
    KeyRound,
    ShieldCheck,
    Globe
} from '@lucide/vue';

const props = defineProps({
    baseDir: String,
    initialPath: String,
    targetDomain: Object,
    availableDomains: {
        type: Array,
        default: () => [],
    },
});

const onDomainChange = (e) => {
    const val = e.target.value;
    if (val) {
        router.get('/files', { domain_id: val });
    } else {
        router.get('/files');
    }
};

const currentPath = shallowRef('');
const items = shallowRef([]);
const loading = shallowRef(false);

const showCreateModal = shallowRef(false);
const newItemName = shallowRef('');
const newItemType = shallowRef('file');

const contextMenu = shallowRef({
    visible: false,
    x: 0,
    y: 0,
    targetType: 'empty',
    item: null,
});

const closeContextMenu = () => {
    if (contextMenu.value.visible) {
        contextMenu.value = { ...contextMenu.value, visible: false, item: null };
    }
};

const positionContextMenu = (e, targetType, item = null) => {
    const menuWidth = 192;
    const menuHeight = targetType === 'empty' ? 132 : 140;
    const x = Math.min(e.clientX, window.innerWidth - menuWidth - 8);
    const y = Math.min(e.clientY, window.innerHeight - menuHeight - 8);
    contextMenu.value = {
        visible: true,
        x: Math.max(8, x),
        y: Math.max(8, y),
        targetType,
        item,
    };
};

const openContextMenu = (e, item) => {
    positionContextMenu(e, item.is_dir ? 'dir' : 'file', item);
};

const openEmptyContextMenu = (e) => {
    positionContextMenu(e, 'empty', null);
};

const openCreateModal = (type = 'file') => {
    newItemType.value = type;
    newItemName.value = '';
    showCreateModal.value = true;
    closeContextMenu();
};

const handleGlobalKeydown = (e) => {
    if (e.key === 'Escape') {
        closeContextMenu();
    }
};

onMounted(() => {
    window.addEventListener('click', closeContextMenu);
    window.addEventListener('keydown', handleGlobalKeydown);
});

onUnmounted(() => {
    window.removeEventListener('click', closeContextMenu);
    window.removeEventListener('keydown', handleGlobalKeydown);
});

const showEditorModal = shallowRef(false);
const editingPath = shallowRef('');
const editorContent = shallowRef('');
const editorLoading = shallowRef(false);

// cPanel-style permissions modal state
const showChmodModal = shallowRef(false);
const chmodTarget = shallowRef(null);
const chmodPerms = shallowRef({
    userRead: true,
    userWrite: true,
    userExec: false,
    groupRead: true,
    groupWrite: false,
    groupExec: false,
    worldRead: true,
    worldWrite: false,
    worldExec: false,
});
const chmodLoading = shallowRef(false);

const computedOctal = computed(() => {
    let u = (chmodPerms.value.userRead ? 4 : 0) + (chmodPerms.value.userWrite ? 2 : 0) + (chmodPerms.value.userExec ? 1 : 0);
    let g = (chmodPerms.value.groupRead ? 4 : 0) + (chmodPerms.value.groupWrite ? 2 : 0) + (chmodPerms.value.groupExec ? 1 : 0);
    let w = (chmodPerms.value.worldRead ? 4 : 0) + (chmodPerms.value.worldWrite ? 2 : 0) + (chmodPerms.value.worldExec ? 1 : 0);
    return `0${u}${g}${w}`;
});

const openChmodModal = (item) => {
    chmodTarget.value = item;
    // Parse existing permissions (e.g., '0755' or '0644')
    const raw = String(item.permissions || '0644').slice(-3);
    const u = parseInt(raw[0], 10) || 0;
    const g = parseInt(raw[1], 10) || 0;
    const w = parseInt(raw[2], 10) || 0;

    chmodPerms.value = {
        userRead: Boolean(u & 4),
        userWrite: Boolean(u & 2),
        userExec: Boolean(u & 1),
        groupRead: Boolean(g & 4),
        groupWrite: Boolean(g & 2),
        groupExec: Boolean(g & 1),
        worldRead: Boolean(w & 4),
        worldWrite: Boolean(w & 2),
        worldExec: Boolean(w & 1),
    };
    showChmodModal.value = true;
};

const saveChmod = async () => {
    if (!chmodTarget.value) return;
    chmodLoading.value = true;
    try {
        const res = await fetch('/files/chmod', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
            },
            body: JSON.stringify({
                path: chmodTarget.value.path,
                permissions: computedOctal.value,
                domain_id: props.targetDomain?.id || null,
            }),
        });
        const json = await res.json();
        if (json.status === 'success') {
            showChmodModal.value = false;
            loadFiles(currentPath.value);
        } else {
            await alertDialog({ title: 'Error', message: json.message || 'Failed to update permissions.', variant: 'danger' });
        }
    } catch (e) {
        await alertDialog({ title: 'Network Error', message: 'Network error updating permissions.', variant: 'danger' });
    } finally {
        chmodLoading.value = false;
    }
};

// CyberPanel-style one-click fix permissions
const fixDomainPermissions = async () => {
    if (!props.targetDomain) return;
    if (!(await confirmDialog({
        title: 'Repair Permissions',
        message: `Restore standard permissions (755 folders, 644 files) for '${props.targetDomain.domain}'?`,
        confirmText: 'Repair',
        variant: 'warning',
    }))) return;

    loading.value = true;
    try {
        const res = await fetch(`/domains/${props.targetDomain.id}/fix-permissions`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
            },
        });
        await alertDialog({ title: 'Permissions Repair', message: `Permissions repair initiated for ${props.targetDomain.domain}.` });
        loadFiles(currentPath.value);
    } catch (e) {
        await alertDialog({ title: 'Error', message: 'Error initiating permissions repair.', variant: 'danger' });
    } finally {
        loading.value = false;
    }
};

const pathSegments = computed(() => {
    if (!currentPath.value) return [];
    const segments = currentPath.value.split('/');
    return segments.map((segment, idx) => ({
        name: segment,
        path: segments.slice(0, idx + 1).join('/'),
    }));
});

const targetDomainParam = computed(() => {
    return props.targetDomain ? `&domain_id=${props.targetDomain.id}` : '';
});

const loadFiles = async (path = '') => {
    loading.value = true;
    try {
        const res = await fetch(`/files/list?path=${encodeURIComponent(path)}${targetDomainParam.value}`, {
            headers: {
                'Accept': 'application/json',
            },
        });
        let json = null;
        try {
            json = await res.json();
        } catch {
            // Non-JSON response (e.g. HTML error page or redirect)
        }
        if (res.ok && json?.status === 'success') {
            currentPath.value = json.current_path;
            items.value = json.items || [];
        } else {
            await alertDialog({
                title: 'Error',
                message: json?.message || `Error listing directory (HTTP ${res.status}).`,
                variant: 'danger',
            });
        }
    } catch (e) {
        await alertDialog({ title: 'Network Error', message: 'Network error loading files.', variant: 'danger' });
    } finally {
        loading.value = false;
    }
};

const navigateTo = (path) => {
    loadFiles(path);
};

const navigateUp = () => {
    if (!currentPath.value) return;
    const parts = currentPath.value.split('/');
    parts.pop();
    loadFiles(parts.join('/'));
};

const createItem = async () => {
    if (!newItemName.value) return;
    try {
        const res = await fetch('/files/create', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
            },
            body: JSON.stringify({
                path: currentPath.value,
                name: newItemName.value,
                type: newItemType.value,
                domain_id: props.targetDomain?.id || null,
            }),
        });
        const json = await res.json();
        if (json.status === 'success') {
            showCreateModal.value = false;
            newItemName.value = '';
            loadFiles(currentPath.value);
        } else {
            await alertDialog({ title: 'Error', message: json.message || 'Error creating item.', variant: 'danger' });
        }
    } catch (e) {
        await alertDialog({ title: 'Request Error', message: 'Request error.', variant: 'danger' });
    }
};

const deleteItem = async (path, name) => {
    if (!(await confirmDialog({
        title: 'Delete Item',
        message: `Are you sure you want to delete '${name}'?`,
        confirmText: 'Delete',
        variant: 'danger',
    }))) return;
    try {
        const res = await fetch('/files/delete', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
            },
            body: JSON.stringify({
                path,
                domain_id: props.targetDomain?.id || null,
            }),
        });
        const json = await res.json();
        if (json.status === 'success') {
            loadFiles(currentPath.value);
        } else {
            await alertDialog({ title: 'Error', message: json.message || 'Failed to delete item.', variant: 'danger' });
        }
    } catch (e) {
        await alertDialog({ title: 'Request Error', message: 'Request error.', variant: 'danger' });
    }
};

const openEditor = async (path) => {
    editingPath.value = path;
    editorLoading.value = true;
    showEditorModal.value = true;
    try {
        const res = await fetch(`/files/content?path=${encodeURIComponent(path)}${targetDomainParam.value}`);
        const json = await res.json();
        if (json.status === 'success') {
            editorContent.value = json.content;
        } else {
            await alertDialog({ title: 'Error', message: json.message || 'Could not open file.', variant: 'danger' });
            showEditorModal.value = false;
        }
    } catch (e) {
        await alertDialog({ title: 'Network Error', message: 'Network error.', variant: 'danger' });
        showEditorModal.value = false;
    } finally {
        editorLoading.value = false;
    }
};

const saveEditorContent = async () => {
    editorLoading.value = true;
    try {
        const res = await fetch('/files/save', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
            },
            body: JSON.stringify({
                path: editingPath.value,
                content: editorContent.value,
                domain_id: props.targetDomain?.id || null,
            }),
        });
        const json = await res.json();
        if (json.status === 'success') {
            showEditorModal.value = false;
            loadFiles(currentPath.value);
        } else {
            await alertDialog({ title: 'Error', message: json.message || 'Failed to save file.', variant: 'danger' });
        }
    } catch (e) {
        await alertDialog({ title: 'Save Error', message: 'Save error.', variant: 'danger' });
    } finally {
        editorLoading.value = false;
    }
};

const handleFileUpload = async (e) => {
    const files = e.target.files;
    if (!files || files.length === 0) return;

    const formData = new FormData();
    formData.append('path', currentPath.value);
    if (props.targetDomain?.id) {
        formData.append('domain_id', props.targetDomain.id);
    }
    for (let i = 0; i < files.length; i++) {
        formData.append('files[]', files[i]);
    }

    try {
        const res = await fetch('/files/upload', {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
            },
            body: formData,
        });
        const json = await res.json();
        if (json.status === 'success') {
            loadFiles(currentPath.value);
        } else {
            await alertDialog({ title: 'Upload Failed', message: json.message || 'Upload failed.', variant: 'danger' });
        }
    } catch (e) {
        await alertDialog({ title: 'Upload Error', message: 'Upload network error.', variant: 'danger' });
    }
};

onMounted(() => {
    loadFiles(props.initialPath || '');
});
</script>

<template>
    <Head :title="__('File Manager')" />

    <div class="space-y-6">
            <!-- Header -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 class="text-xl sm:text-2xl font-bold text-white flex items-center gap-2.5">
                        <FolderTree class="w-6 h-6 text-cyan-400" />
                        <span>{{ __('Web File Manager') }}</span>
                    </h1>
                    <p class="text-xs sm:text-sm text-slate-400 mt-1">
                        Securely browse, create, edit, and upload files within your home directory (`{{ baseDir }}`).
                    </p>
                </div>

                <!-- Action Toolbar -->
                <div class="flex flex-wrap items-center gap-2">
                    <!-- Domain Switcher -->
                    <div v-if="availableDomains && availableDomains.length > 0" class="relative flex items-center">
                        <Globe class="w-3.5 h-3.5 text-slate-400 absolute left-2.5 pointer-events-none" />
                        <select
                            :value="targetDomain?.id || ''"
                            @change="onDomainChange"
                            class="pl-8 pr-8 py-2 rounded-lg bg-slate-900 border border-slate-700 hover:border-slate-600 text-slate-200 text-xs font-medium focus:ring-1 focus:ring-cyan-500 focus:border-cyan-500 transition-colors cursor-pointer"
                        >
                            <option value="">Home Directory ({{ baseDir }})</option>
                            <option v-for="d in availableDomains" :key="d.id" :value="d.id">
                                {{ d.domain }}
                            </option>
                        </select>
                    </div>

                    <button
                        v-if="targetDomain"
                        @click="fixDomainPermissions"
                        class="inline-flex items-center gap-2 px-3.5 py-2 rounded-lg bg-emerald-600/20 hover:bg-emerald-600/30 text-emerald-300 text-xs font-semibold border border-emerald-500/30 transition-colors"
                        :title="__('Reset permissions to standard 755 (dirs) / 644 (files)')"
                    >
                        <ShieldCheck class="w-3.5 h-3.5 text-emerald-400" />
                        <span>{{ __('Fix Permissions') }}</span>
                    </button>

                    <label class="inline-flex items-center gap-2 px-3.5 py-2 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-semibold cursor-pointer transition-colors border border-slate-700">
                        <Upload class="w-3.5 h-3.5 text-cyan-400" />
                        <span>{{ __('Upload') }}</span>
                        <input type="file" multiple class="hidden" @change="handleFileUpload" />
                    </label>

                    <button
                        @click="showCreateModal = true"
                        class="inline-flex items-center gap-2 px-3.5 py-2 rounded-lg bg-cyan-600 hover:bg-cyan-500 text-white text-xs font-semibold transition-colors shadow-sm shadow-cyan-600/30"
                    >
                        <Plus class="w-3.5 h-3.5" />
                        <span>{{ __('New Item') }}</span>
                    </button>
                </div>
            </div>

            <!-- Path Breadcrumbs & Up Button -->
            <div class="p-3 bg-slate-900 border border-slate-800 rounded-xl flex items-center justify-between gap-3 text-xs shadow-sm">
                <div class="flex items-center gap-2 overflow-x-auto font-mono text-slate-300">
                    <button
                        @click="navigateTo('')"
                        class="px-2 py-1 rounded hover:bg-slate-800 text-cyan-400 font-semibold"
                    >
                        home
                    </button>
                    <template v-if="pathSegments.length > 0">
                        <span class="text-slate-600">/</span>
                        <span
                            v-for="(seg, idx) in pathSegments"
                            :key="seg.path"
                            class="flex items-center gap-2"
                        >
                            <button
                                @click="navigateTo(seg.path)"
                                class="text-slate-300 hover:text-cyan-400 hover:underline transition-colors"
                            >
                                {{ seg.name }}
                            </button>
                            <span v-if="idx < pathSegments.length - 1" class="text-slate-600">/</span>
                        </span>
                    </template>
                </div>

                <div class="flex items-center gap-2 shrink-0">
                    <button
                        @click="navigateUp"
                        :disabled="!currentPath"
                        class="p-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 disabled:opacity-40 text-slate-300 transition-colors"
                        :title="__('Up one directory')"
                    >
                        <ArrowUp class="w-4 h-4" />
                    </button>
                    <button
                        @click="loadFiles(currentPath)"
                        class="p-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 transition-colors"
                        :title="__('Refresh')"
                    >
                        <RefreshCw class="w-4 h-4" :class="{ 'animate-spin': loading }" />
                    </button>
                </div>
            </div>

            <!-- File List Table -->
            <div
                class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden shadow-sm min-h-[260px]"
                @contextmenu.prevent="openEmptyContextMenu($event)"
            >
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm text-slate-300">
                        <thead class="bg-slate-950/50 text-xs font-semibold text-slate-400 uppercase tracking-wider border-b border-slate-800">
                            <tr>
                                <th class="px-5 py-3">{{ __('Name') }}</th>
                                <th class="px-5 py-3">{{ __('Size') }}</th>
                                <th class="px-5 py-3">{{ __('Permissions') }}</th>
                                <th class="px-5 py-3">{{ __('Last Modified') }}</th>
                                <th class="px-5 py-3 text-right">{{ __('Actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800/60 font-mono text-xs">
                            <tr
                                v-for="item in items"
                                :key="item.name"
                                @contextmenu.prevent.stop="openContextMenu($event, item)"
                                class="hover:bg-slate-800/40 transition-colors"
                            >
                                <td class="px-5 py-3">
                                    <div class="flex items-center gap-2.5">
                                        <Folder v-if="item.is_dir" class="w-4 h-4 text-cyan-400 shrink-0" />
                                        <FileCode v-else-if="['php', 'js', 'json', 'css', 'html'].includes(item.extension)" class="w-4 h-4 text-purple-400 shrink-0" />
                                        <FileText v-else class="w-4 h-4 text-slate-400 shrink-0" />

                                        <button
                                            v-if="item.is_dir"
                                            @click="navigateTo(item.path)"
                                            class="font-semibold text-white hover:text-cyan-400 hover:underline transition-colors text-left"
                                        >
                                            {{ item.name }}
                                        </button>
                                        <button
                                            v-else
                                            @click="openEditor(item.path)"
                                            class="text-slate-200 hover:text-cyan-400 hover:underline transition-colors text-left"
                                        >
                                            {{ item.name }}
                                        </button>
                                    </div>
                                </td>
                                <td class="px-5 py-3 text-slate-400">
                                    {{ item.is_dir ? '—' : item.size_human }}
                                </td>
                                <td class="px-5 py-3 text-slate-400">
                                    <button
                                        @click="openChmodModal(item)"
                                        class="hover:text-cyan-400 hover:underline transition-colors font-mono cursor-pointer"
                                        :title="__('Click to edit permissions (chmod)')"
                                    >
                                        {{ item.permissions }}
                                    </button>
                                </td>
                                <td class="px-5 py-3 text-slate-400">
                                    {{ item.modified_at || '—' }}
                                </td>
                                <td class="px-5 py-3 text-right">
                                    <div class="flex items-center justify-end gap-2">
                                        <button
                                            @click="openChmodModal(item)"
                                            :title="__('Change Permissions')"
                                            class="p-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-amber-400 transition-colors"
                                        >
                                            <KeyRound class="w-3.5 h-3.5" />
                                        </button>
                                        <button
                                            v-if="!item.is_dir"
                                            @click="openEditor(item.path)"
                                            :title="__('Edit in Code Editor')"
                                            class="p-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-cyan-400 transition-colors"
                                        >
                                            <Edit3 class="w-3.5 h-3.5" />
                                        </button>
                                        <button
                                            @click="deleteItem(item.path, item.name)"
                                            :title="__('Delete')"
                                            class="p-1.5 rounded-lg bg-slate-800 hover:bg-rose-500/20 text-rose-400 transition-colors"
                                        >
                                            <Trash2 class="w-3.5 h-3.5" />
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="!items || items.length === 0">
                                <td colspan="5" class="px-5 py-8 text-center text-slate-500 text-sm">
                                    This directory is empty.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Create New File / Folder Modal -->
            <UiModal :show="showCreateModal" :title="__('Create New Item')" max-width="sm" @close="showCreateModal = false">
                <div class="space-y-3">
                    <div class="flex gap-2">
                        <button
                            @click="newItemType = 'file'"
                            :class="['flex-1 py-1.5 rounded-lg text-xs font-semibold border transition-colors', newItemType === 'file' ? 'bg-cyan-600 text-white border-cyan-500' : 'bg-slate-800 text-slate-400 border-slate-700']"
                        >
                            File
                        </button>
                        <button
                            @click="newItemType = 'folder'"
                            :class="['flex-1 py-1.5 rounded-lg text-xs font-semibold border transition-colors', newItemType === 'folder' ? 'bg-cyan-600 text-white border-cyan-500' : 'bg-slate-800 text-slate-400 border-slate-700']"
                        >
                            Folder
                        </button>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-slate-300 uppercase mb-1">{{ __('Name') }}</label>
                        <input
                            v-model="newItemName"
                            type="text"
                            :placeholder="__('e.g. index.php or assets')"
                            class="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-lg text-sm text-slate-200 focus:outline-none focus:border-cyan-500"
                        />
                    </div>

                    <div class="pt-3 flex items-center justify-end gap-2 border-t border-slate-800">
                        <button
                            @click="showCreateModal = false"
                            class="px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-xs font-semibold text-slate-300"
                        >
                            Cancel
                        </button>
                        <button
                            @click="createItem"
                            class="px-4 py-1.5 rounded-lg bg-cyan-600 hover:bg-cyan-500 text-xs font-semibold text-white shadow-sm"
                        >
                            Create
                        </button>
                    </div>
                </div>
            </UiModal>

            <!-- Code Editor Modal -->
            <UiModal :show="showEditorModal" max-width="4xl" @close="showEditorModal = false">
                <template #header>
                    <div class="flex items-center gap-2 text-xs font-mono text-cyan-400">
                        <FileCode class="w-4 h-4" />
                        <span>{{ editingPath }}</span>
                    </div>
                </template>

                <div class="h-[70vh] flex flex-col overflow-hidden">
                    <textarea
                        v-model="editorContent"
                        class="w-full flex-1 p-3 bg-slate-950 border border-slate-800 rounded-lg font-mono text-xs text-slate-100 focus:outline-none focus:border-cyan-500 resize-none leading-relaxed"
                        spellcheck="false"
                    ></textarea>
                </div>

                <template #footer>
                    <button
                        @click="showEditorModal = false"
                        class="px-3.5 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-xs font-semibold text-slate-300 transition-colors"
                    >
                        Cancel
                    </button>
                    <button
                        @click="saveEditorContent"
                        :disabled="editorLoading"
                        class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-lg bg-cyan-600 hover:bg-cyan-500 disabled:opacity-50 text-xs font-semibold text-white shadow-sm"
                    >
                        <Save class="w-3.5 h-3.5" />
                        <span>{{ __('Save') }}</span>
                    </button>
                </template>
            </UiModal>

            <!-- cPanel-Style Change Permissions Modal -->
            <UiModal :show="showChmodModal" :title="__('Change Permissions')" max-width="md" @close="showChmodModal = false">
                <div class="space-y-4">
                    <div v-if="chmodTarget" class="text-xs text-slate-400 break-all font-mono">
                        {{ chmodTarget.name }} ({{ chmodTarget.is_dir ? 'Directory' : 'File' }})
                    </div>

                    <!-- Permissions Checkbox Matrix Table -->
                    <div class="border border-slate-800 rounded-lg overflow-hidden text-xs">
                        <table class="w-full text-center">
                            <thead class="bg-slate-950/70 border-b border-slate-800 text-slate-400 font-semibold uppercase">
                                <tr>
                                    <th class="py-2.5 px-3 text-left">{{ __('Mode') }}</th>
                                    <th class="py-2.5 px-3">{{ __('User') }}</th>
                                    <th class="py-2.5 px-3">{{ __('Group') }}</th>
                                    <th class="py-2.5 px-3">{{ __('World') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-800 font-mono text-slate-300">
                                <tr>
                                    <td class="py-2.5 px-3 text-left font-semibold text-slate-200">{{ __('Read (4)') }}</td>
                                    <td class="py-2.5 px-3">
                                        <input type="checkbox" v-model="chmodPerms.userRead" class="rounded border-slate-700 bg-slate-900 text-cyan-500 focus:ring-0" />
                                    </td>
                                    <td class="py-2.5 px-3">
                                        <input type="checkbox" v-model="chmodPerms.groupRead" class="rounded border-slate-700 bg-slate-900 text-cyan-500 focus:ring-0" />
                                    </td>
                                    <td class="py-2.5 px-3">
                                        <input type="checkbox" v-model="chmodPerms.worldRead" class="rounded border-slate-700 bg-slate-900 text-cyan-500 focus:ring-0" />
                                    </td>
                                </tr>
                                <tr>
                                    <td class="py-2.5 px-3 text-left font-semibold text-slate-200">{{ __('Write (2)') }}</td>
                                    <td class="py-2.5 px-3">
                                        <input type="checkbox" v-model="chmodPerms.userWrite" class="rounded border-slate-700 bg-slate-900 text-cyan-500 focus:ring-0" />
                                    </td>
                                    <td class="py-2.5 px-3">
                                        <input type="checkbox" v-model="chmodPerms.groupWrite" class="rounded border-slate-700 bg-slate-900 text-cyan-500 focus:ring-0" />
                                    </td>
                                    <td class="py-2.5 px-3">
                                        <input type="checkbox" v-model="chmodPerms.worldWrite" class="rounded border-slate-700 bg-slate-900 text-cyan-500 focus:ring-0" />
                                    </td>
                                </tr>
                                <tr>
                                    <td class="py-2.5 px-3 text-left font-semibold text-slate-200">{{ __('Execute (1)') }}</td>
                                    <td class="py-2.5 px-3">
                                        <input type="checkbox" v-model="chmodPerms.userExec" class="rounded border-slate-700 bg-slate-900 text-cyan-500 focus:ring-0" />
                                    </td>
                                    <td class="py-2.5 px-3">
                                        <input type="checkbox" v-model="chmodPerms.groupExec" class="rounded border-slate-700 bg-slate-900 text-cyan-500 focus:ring-0" />
                                    </td>
                                    <td class="py-2.5 px-3">
                                        <input type="checkbox" v-model="chmodPerms.worldExec" class="rounded border-slate-700 bg-slate-900 text-cyan-500 focus:ring-0" />
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Octal Display -->
                    <div class="flex items-center justify-between p-3 bg-slate-950 border border-slate-800 rounded-lg text-xs">
                        <span class="text-slate-400 font-semibold uppercase">{{ __('Permission Value:') }}</span>
                        <span class="font-mono text-base font-bold text-cyan-400">{{ computedOctal }}</span>
                    </div>

                    <!-- Modal Actions -->
                    <div class="pt-3 flex items-center justify-end gap-2 border-t border-slate-800">
                        <button
                            @click="showChmodModal = false"
                            class="px-3.5 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-xs font-semibold text-slate-300 transition-colors"
                        >
                            Cancel
                        </button>
                        <button
                            @click="saveChmod"
                            :disabled="chmodLoading"
                            class="px-4 py-1.5 rounded-lg bg-cyan-600 hover:bg-cyan-500 disabled:opacity-50 text-xs font-semibold text-white shadow-sm transition-colors"
                        >
                            {{ chmodLoading ? 'Saving...' : 'Change Permissions' }}
                        </button>
                    </div>
                </div>
            </UiModal>

            <!-- Context Menu -->
            <div
                v-if="contextMenu.visible"
                :style="{ top: `${contextMenu.y}px`, left: `${contextMenu.x}px` }"
                @click.stop
                class="fixed z-50 w-48 rounded-lg bg-slate-900 border border-slate-700 shadow-xl py-1 text-xs text-slate-200"
            >
                <template v-if="contextMenu.targetType === 'file' && contextMenu.item">
                    <button
                        @click="openEditor(contextMenu.item.path); closeContextMenu()"
                        class="w-full px-3 py-2 text-left hover:bg-slate-800 flex items-center gap-2 text-cyan-400"
                    >
                        <Edit3 class="w-3.5 h-3.5" />
                        <span>{{ __('Edit') }}</span>
                    </button>
                    <button
                        @click="openChmodModal(contextMenu.item); closeContextMenu()"
                        class="w-full px-3 py-2 text-left hover:bg-slate-800 flex items-center gap-2 text-amber-400"
                    >
                        <KeyRound class="w-3.5 h-3.5" />
                        <span>{{ __('Change Permissions') }}</span>
                    </button>
                    <div class="my-1 border-t border-slate-800"></div>
                    <button
                        @click="deleteItem(contextMenu.item.path, contextMenu.item.name); closeContextMenu()"
                        class="w-full px-3 py-2 text-left hover:bg-rose-500/20 flex items-center gap-2 text-rose-400"
                    >
                        <Trash2 class="w-3.5 h-3.5" />
                        <span>{{ __('Delete') }}</span>
                    </button>
                </template>

                <template v-else-if="contextMenu.targetType === 'dir' && contextMenu.item">
                    <button
                        @click="navigateTo(contextMenu.item.path); closeContextMenu()"
                        class="w-full px-3 py-2 text-left hover:bg-slate-800 flex items-center gap-2 text-cyan-400"
                    >
                        <Folder class="w-3.5 h-3.5" />
                        <span>{{ __('Open') }}</span>
                    </button>
                    <button
                        @click="openChmodModal(contextMenu.item); closeContextMenu()"
                        class="w-full px-3 py-2 text-left hover:bg-slate-800 flex items-center gap-2 text-amber-400"
                    >
                        <KeyRound class="w-3.5 h-3.5" />
                        <span>{{ __('Change Permissions') }}</span>
                    </button>
                    <div class="my-1 border-t border-slate-800"></div>
                    <button
                        @click="deleteItem(contextMenu.item.path, contextMenu.item.name); closeContextMenu()"
                        class="w-full px-3 py-2 text-left hover:bg-rose-500/20 flex items-center gap-2 text-rose-400"
                    >
                        <Trash2 class="w-3.5 h-3.5" />
                        <span>{{ __('Delete') }}</span>
                    </button>
                </template>

                <template v-else>
                    <button
                        @click="openCreateModal('file')"
                        class="w-full px-3 py-2 text-left hover:bg-slate-800 flex items-center gap-2 text-cyan-400"
                    >
                        <Plus class="w-3.5 h-3.5" />
                        <span>{{ __('Create File') }}</span>
                    </button>
                    <button
                        @click="openCreateModal('dir')"
                        class="w-full px-3 py-2 text-left hover:bg-slate-800 flex items-center gap-2 text-slate-200"
                    >
                        <Folder class="w-3.5 h-3.5" />
                        <span>{{ __('Create Folder') }}</span>
                    </button>
                    <div class="my-1 border-t border-slate-800"></div>
                    <button
                        @click="fetchDirectory(currentPath); closeContextMenu()"
                        class="w-full px-3 py-2 text-left hover:bg-slate-800 flex items-center gap-2 text-slate-300"
                    >
                        <RefreshCw class="w-3.5 h-3.5" />
                        <span>{{ __('Refresh') }}</span>
                    </button>
                </template>
            </div>
        </div>
</template>

