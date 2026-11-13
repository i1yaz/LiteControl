<script setup lang="ts">
import { shallowRef, computed } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { confirmDialog } from '@/Composables/useDialog';
import Modal from '@/Components/Modal.vue';
import { Globe, Plus, Folder, Trash2, X } from '@lucide/vue';

const props = defineProps({
    subdomains: Object,
    baseDomains: Array,
    phpVersions: {
        type: Array,
        default: () => [],
    },
});

const showModal = shallowRef(false);

const form = useForm({
    subdomain_prefix: '',
    parent_domain_id: props.baseDomains?.[0]?.id || '',
    public_path: '',
    php_version: props.baseDomains?.[0]?.php_version || '83',
    ssl_enabled: true,
    use_parent_root: false,
});

const selectedParentDomain = computed(() => {
    return props.baseDomains?.find((d) => d.id === form.parent_domain_id) || null;
});

const phpOptions = computed(() => {
    const versions = props.phpVersions?.length ? props.phpVersions : ['74', '80', '81', '82', '83', '84'];
    return versions.map((v) => {
        const str = String(v).replace('.', '');
        const formatted = str.length === 2 ? `${str[0]}.${str[1]}` : str;
        return { value: str, label: `PHP ${formatted}` };
    });
});

const docRootPreview = computed(() => {
    const parent = selectedParentDomain.value?.domain || 'example.com';
    const prefix = form.subdomain_prefix.trim().toLowerCase() || 'sub';
    const baseFolder = form.use_parent_root ? parent : `${prefix}.${parent}`;
    const sub = form.public_path.trim().replace(/^\/+|\/+$/g, '');
    return `/home/<user>/${baseFolder}/public_html${sub ? '/' + sub : ''}`;
});

const submit = () => {
    form.post('/subdomains', {
        onSuccess: () => {
            showModal.value = false;
            form.reset('subdomain_prefix', 'public_path');
            form.ssl_enabled = true;
            form.use_parent_root = false;
        },
    });
};

const deleteSubdomain = async (id, domain) => {
    if (await confirmDialog({
        title: 'Delete Subdomain',
        message: `Are you sure you want to delete subdomain '${domain}'?`,
        confirmText: 'Delete',
        variant: 'danger',
    })) {
        router.delete(`/domains/${id}`);
    }
};
</script>

<template>
            <Head title="Subdomains Management" />

        <div class="space-y-6">
            <!-- Header -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 class="text-xl sm:text-2xl font-bold text-white flex items-center gap-2.5">
                        <Globe class="w-6 h-6 text-emerald-400" />
                        <span>Subdomains</span>
                    </h1>
                    <p class="text-xs sm:text-sm text-slate-400 mt-1">
                        Create and manage subdomains for your hosted domains.
                    </p>
                </div>

                <button
                    @click="showModal = true"
                    class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white text-sm font-medium transition-colors shadow-sm shadow-emerald-600/30"
                >
                    <Plus class="w-4 h-4" />
                    <span>Create Subdomain</span>
                </button>
            </div>

            <!-- Table -->
            <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden shadow-sm">
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm text-slate-300">
                        <thead class="bg-slate-950/50 text-xs font-semibold text-slate-400 uppercase tracking-wider border-b border-slate-800">
                            <tr>
                                <th class="px-5 py-3">Subdomain</th>
                                <th class="px-5 py-3">Base Domain</th>
                                <th class="px-5 py-3">Document Root</th>
                                <th class="px-5 py-3">PHP Version</th>
                                <th class="px-5 py-3 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800/60">
                            <tr v-for="sub in subdomains.data" :key="sub.id" class="hover:bg-slate-800/40 transition-colors">
                                <td class="px-5 py-3.5 font-semibold text-white">
                                    {{ sub.domain }}
                                </td>
                                <td class="px-5 py-3.5 text-xs text-slate-400">
                                    {{ sub.parent_domain?.domain || '—' }}
                                </td>
                                <td class="px-5 py-3.5 text-xs font-mono text-slate-400">
                                    <span class="flex items-center gap-1.5 truncate max-w-xs">
                                        <Folder class="w-3.5 h-3.5 text-slate-500 shrink-0" />
                                        {{ sub.document_root }}
                                    </span>
                                </td>
                                <td class="px-5 py-3.5 text-xs text-slate-300">
                                    PHP {{ sub.php_version }}
                                </td>
                                <td class="px-5 py-3.5 text-right">
                                    <button
                                        @click="deleteSubdomain(sub.id, sub.domain)"
                                        class="p-1.5 rounded-lg bg-slate-800 hover:bg-rose-500/20 text-rose-400 transition-colors"
                                    >
                                        <Trash2 class="w-4 h-4" />
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="!subdomains.data || subdomains.data.length === 0">
                                <td colspan="5" class="px-5 py-8 text-center text-slate-500 text-sm">
                                    No subdomains created yet.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Modal -->
            <Modal :show="showModal" max-width="md" @close="showModal = false">
                <div class="p-6 space-y-4">
                    <div class="flex items-center justify-between border-b border-slate-800 pb-3">
                        <h3 class="text-base font-bold text-white">Create Subdomain</h3>
                        <button @click="showModal = false" class="text-slate-400 hover:text-white">
                            <X class="w-5 h-5" />
                        </button>
                    </div>

                    <form @submit.prevent="submit" class="space-y-4">
                        <div>
                            <label class="block text-xs font-semibold text-slate-300 uppercase mb-1">Subdomain Prefix</label>
                            <input
                                v-model="form.subdomain_prefix"
                                type="text"
                                placeholder="blog, app, api"
                                required
                                class="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-lg text-sm text-slate-200 focus:outline-none focus:border-emerald-500"
                            />
                            <p v-if="form.errors.subdomain_prefix" class="text-xs text-rose-400 mt-1">{{ form.errors.subdomain_prefix }}</p>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-300 uppercase mb-1">Parent Domain</label>
                            <select
                                v-model="form.parent_domain_id"
                                required
                                class="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-lg text-sm text-slate-200 focus:outline-none focus:border-emerald-500"
                            >
                                <option v-for="d in baseDomains" :key="d.id" :value="d.id">
                                    {{ d.domain }}
                                </option>
                            </select>
                        </div>

                        <!-- Point to Parent Domain Folder -->
                        <div class="pt-2 border-t border-slate-800 flex items-center justify-between">
                            <div>
                                <span class="text-xs text-slate-200 block font-medium">Point to Parent Domain Folder</span>
                                <span class="text-[11px] text-slate-400">Share the parent domain's public_html directory</span>
                            </div>
                            <label class="relative inline-flex items-center cursor-pointer">
                                <input
                                    v-model="form.use_parent_root"
                                    type="checkbox"
                                    class="sr-only peer"
                                />
                                <div class="w-8 h-4 bg-slate-700 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-3 after:w-3 after:transition-all peer-checked:bg-emerald-500"></div>
                            </label>
                        </div>

                        <!-- Web Root Subpath (Pointing Target) -->
                        <div>
                            <label class="block text-xs font-semibold text-slate-300 uppercase mb-1 flex items-center justify-between">
                                <span>Web Root Subpath (Pointing Target)</span>
                                <span class="text-slate-400 font-normal lowercase">e.g. "public" for Laravel</span>
                            </label>
                            <input
                                v-model="form.public_path"
                                type="text"
                                placeholder="public (leave empty for standard public_html)"
                                class="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-lg text-sm text-slate-200 placeholder:text-slate-500 focus:outline-none focus:border-emerald-500"
                            />
                            <p v-if="form.errors.public_path" class="text-xs text-rose-400 mt-1">{{ form.errors.public_path }}</p>
                            <p class="text-[11px] text-slate-400 mt-1">
                                Target DocRoot: <span class="text-emerald-400 font-mono">{{ docRootPreview }}</span>
                            </p>
                        </div>

                        <!-- PHP Runtime -->
                        <div>
                            <label class="block text-xs font-semibold text-slate-300 uppercase mb-1">PHP Runtime</label>
                            <select
                                v-model="form.php_version"
                                class="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-lg text-sm text-slate-200 focus:outline-none focus:border-emerald-500"
                            >
                                <option v-for="opt in phpOptions" :key="opt.value" :value="opt.value">
                                    {{ opt.label }}
                                </option>
                            </select>
                            <p v-if="form.errors.php_version" class="text-xs text-rose-400 mt-1">{{ form.errors.php_version }}</p>
                        </div>

                        <!-- Generate Let's Encrypt SSL -->
                        <div class="pt-2 border-t border-slate-800 flex items-center justify-between">
                            <div>
                                <span class="text-xs text-slate-200 block font-medium">Generate Let's Encrypt SSL</span>
                                <span class="text-[11px] text-slate-400">Default: ON (Requires DNS A-record pointed)</span>
                            </div>
                            <label class="relative inline-flex items-center cursor-pointer">
                                <input
                                    v-model="form.ssl_enabled"
                                    type="checkbox"
                                    class="sr-only peer"
                                />
                                <div class="w-8 h-4 bg-slate-700 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-3 after:w-3 after:transition-all peer-checked:bg-emerald-500"></div>
                            </label>
                        </div>

                        <div class="pt-4 flex items-center justify-end gap-3 border-t border-slate-800">
                            <button
                                type="button"
                                @click="showModal = false"
                                class="px-4 py-2 rounded-lg bg-slate-800 hover:bg-slate-700 text-sm font-medium text-slate-300"
                            >
                                Cancel
                            </button>
                            <button
                                type="submit"
                                :disabled="form.processing"
                                class="px-5 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 text-sm font-medium text-white shadow-sm"
                            >
                                {{ form.processing ? 'Creating...' : 'Create Subdomain' }}
                            </button>
                        </div>
                    </form>
                </div>
            </Modal>
        </div>
</template>

