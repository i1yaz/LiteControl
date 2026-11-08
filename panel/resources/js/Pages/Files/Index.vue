<script setup>
import { shallowRef, computed, onMounted } from 'vue';
import { Head } from '@inertiajs/vue3';
import Modal from '@/Components/Modal.vue';
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
    X,
    Save,
    HardDrive
} from 'lucide-vue-next';

const props = defineProps({
    baseDir: String,
});

const currentPath = shallowRef('');
const items = shallowRef([]);
const loading = shallowRef(false);

const showCreateModal = shallowRef(false);
const newItemName = shallowRef('');
const newItemType = shallowRef('file');

const showEditorModal = shallowRef(false);
const editingPath = shallowRef('');
const editorContent = shallowRef('');
const editorLoading = shallowRef(false);

const pathSegments = computed(() => {
    if (!currentPath.value) return [];
    const segments = currentPath.value.split('/');
    return segments.map((segment, idx) => ({
        name: segment,
        path: segments.slice(0, idx + 1).join('/'),
    }));
});

const loadFiles = async (path = '') => {
    loading.value = true;
    try {
        const res = await fetch(`/files/list?path=${encodeURIComponent(path)}`);
        const json = await res.json();
        if (json.status === 'success') {
            currentPath.value = json.current_path;
            items.value = json.items || [];
        } else {
            alert(json.message || 'Error listing directory.');
        }
    } catch (e) {
        alert('Network error loading files.');
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
            }),
        });
        const json = await res.json();
        if (json.status === 'success') {
            showCreateModal.value = false;
            newItemName.value = '';
            loadFiles(currentPath.value);
        } else {
            alert(json.message || 'Error creating item.');
        }
    } catch (e) {
        alert('Request error.');
    }
};

const deleteItem = async (path, name) => {
    if (!confirm(`Are you sure you want to delete '${name}'?`)) return;
    try {
        const res = await fetch('/files/delete', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
            },
            body: JSON.stringify({ path }),
        });
        const json = await res.json();
        if (json.status === 'success') {
            loadFiles(currentPath.value);
        } else {
            alert(json.message || 'Failed to delete item.');
        }
    } catch (e) {
        alert('Request error.');
    }
};

const openEditor = async (path) => {
    editingPath.value = path;
    editorLoading.value = true;
    showEditorModal.value = true;
    try {
        const res = await fetch(`/files/content?path=${encodeURIComponent(path)}`);
        const json = await res.json();
        if (json.status === 'success') {
            editorContent.value = json.content;
        } else {
            alert(json.message || 'Could not open file.');
            showEditorModal.value = false;
        }
    } catch (e) {
        alert('Network error.');
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
            }),
        });
        const json = await res.json();
        if (json.status === 'success') {
            showEditorModal.value = false;
            loadFiles(currentPath.value);
        } else {
            alert(json.message || 'Failed to save file.');
        }
    } catch (e) {
        alert('Save error.');
    } finally {
        editorLoading.value = false;
    }
};

const handleFileUpload = async (e) => {
    const files = e.target.files;
    if (!files || files.length === 0) return;

    const formData = new FormData();
    formData.append('path', currentPath.value);
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
            alert(json.message || 'Upload failed.');
        }
    } catch (e) {
        alert('Upload network error.');
    }
};

onMounted(() => {
    loadFiles('');
});
</script>

<template>
    <Head title="File Manager" />

    <div class="space-y-6">
            <!-- Header -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 class="text-xl sm:text-2xl font-bold text-white flex items-center gap-2.5">
                        <FolderTree class="w-6 h-6 text-cyan-400" />
                        <span>Web File Manager</span>
                    </h1>
                    <p class="text-xs sm:text-sm text-slate-400 mt-1">
                        Securely browse, create, edit, and upload files within your home directory (`{{ baseDir }}`).
                    </p>
                </div>

                <!-- Action Toolbar -->
                <div class="flex items-center gap-2">
                    <label class="inline-flex items-center gap-2 px-3.5 py-2 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-semibold cursor-pointer transition-colors border border-slate-700">
                        <Upload class="w-3.5 h-3.5 text-cyan-400" />
                        <span>Upload</span>
                        <input type="file" multiple class="hidden" @change="handleFileUpload" />
                    </label>

                    <button
                        @click="showCreateModal = true"
                        class="inline-flex items-center gap-2 px-3.5 py-2 rounded-lg bg-cyan-600 hover:bg-cyan-500 text-white text-xs font-semibold transition-colors shadow-sm shadow-cyan-600/30"
                    >
                        <Plus class="w-3.5 h-3.5" />
                        <span>New Item</span>
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
                        title="Up one directory"
                    >
                        <ArrowUp class="w-4 h-4" />
                    </button>
                    <button
                        @click="loadFiles(currentPath)"
                        class="p-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 transition-colors"
                        title="Refresh"
                    >
                        <RefreshCw class="w-4 h-4" :class="{ 'animate-spin': loading }" />
                    </button>
                </div>
            </div>

            <!-- File List Table -->
            <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden shadow-sm">
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm text-slate-300">
                        <thead class="bg-slate-950/50 text-xs font-semibold text-slate-400 uppercase tracking-wider border-b border-slate-800">
                            <tr>
                                <th class="px-5 py-3">Name</th>
                                <th class="px-5 py-3">Size</th>
                                <th class="px-5 py-3">Permissions</th>
                                <th class="px-5 py-3">Last Modified</th>
                                <th class="px-5 py-3 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800/60 font-mono text-xs">
                            <tr
                                v-for="item in items"
                                :key="item.name"
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
                                        <span v-else class="text-slate-200">
                                            {{ item.name }}
                                        </span>
                                    </div>
                                </td>
                                <td class="px-5 py-3 text-slate-400">
                                    {{ item.is_dir ? '—' : item.size_human }}
                                </td>
                                <td class="px-5 py-3 text-slate-400">
                                    {{ item.permissions }}
                                </td>
                                <td class="px-5 py-3 text-slate-400">
                                    {{ item.modified_at || '—' }}
                                </td>
                                <td class="px-5 py-3 text-right">
                                    <div class="flex items-center justify-end gap-2">
                                        <button
                                            v-if="!item.is_dir"
                                            @click="openEditor(item.path)"
                                            title="Edit in Code Editor"
                                            class="p-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-cyan-400 transition-colors"
                                        >
                                            <Edit3 class="w-3.5 h-3.5" />
                                        </button>
                                        <button
                                            @click="deleteItem(item.path, item.name)"
                                            title="Delete"
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
            <Modal :show="showCreateModal" max-width="sm" @close="showCreateModal = false">
                <div class="p-5 space-y-4">
                    <div class="flex items-center justify-between border-b border-slate-800 pb-3">
                        <h3 class="text-base font-bold text-white">Create New Item</h3>
                        <button @click="showCreateModal = false" class="text-slate-400 hover:text-white">
                            <X class="w-5 h-5" />
                        </button>
                    </div>

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
                            <label class="block text-xs font-semibold text-slate-300 uppercase mb-1">Name</label>
                            <input
                                v-model="newItemName"
                                type="text"
                                placeholder="e.g. index.php or assets"
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
                </div>
            </Modal>

            <!-- Code Editor Modal -->
            <Modal :show="showEditorModal" max-width="4xl" @close="showEditorModal = false">
                <div class="h-[80vh] flex flex-col overflow-hidden">
                    <!-- Editor Header -->
                    <div class="px-5 py-3 border-b border-slate-800 flex items-center justify-between bg-slate-950">
                        <div class="flex items-center gap-2 text-xs font-mono text-cyan-400">
                            <FileCode class="w-4 h-4" />
                            <span>{{ editingPath }}</span>
                        </div>
                        <div class="flex items-center gap-3">
                            <button
                                @click="saveEditorContent"
                                :disabled="editorLoading"
                                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-cyan-600 hover:bg-cyan-500 disabled:opacity-50 text-xs font-semibold text-white shadow-sm"
                            >
                                <Save class="w-3.5 h-3.5" />
                                <span>Save</span>
                            </button>
                            <button @click="showEditorModal = false" class="text-slate-400 hover:text-white">
                                <X class="w-5 h-5" />
                            </button>
                        </div>
                    </div>

                    <!-- Editor Body -->
                    <div class="flex-1 p-4 bg-slate-950 overflow-hidden flex flex-col">
                        <textarea
                            v-model="editorContent"
                            class="w-full flex-1 p-3 bg-slate-900 border border-slate-800 rounded-lg font-mono text-xs text-slate-100 focus:outline-none focus:border-cyan-500 resize-none leading-relaxed"
                            spellcheck="false"
                        ></textarea>
                    </div>
                </div>
            </Modal>
        </div>
</template>

