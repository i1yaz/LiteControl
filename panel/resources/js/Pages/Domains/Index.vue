<script setup>
import { shallowRef } from 'vue';
import { Head, useForm, router, usePage } from '@inertiajs/vue3';
import PanelLayout from '@/Layouts/PanelLayout.vue';
import Modal from '@/Components/Modal.vue';
import { Globe, Plus, ShieldCheck, ShieldAlert, Folder, Trash2, X, RefreshCw } from 'lucide-vue-next';

const props = defineProps({
    domains: Object,
    phpVersions: Array,
    users: Array,
});

const page = usePage();
const isAdmin = page.props.auth?.user?.role === 'admin';

const showModal = shallowRef(false);

const form = useForm({
    domain: '',
    php_version: '83',
    type: 'addon',
    user_id: props.users?.[0]?.id || '',
});

const openAddModal = () => {
    form.reset();
    showModal.value = true;
};

const submit = () => {
    form.post('/domains', {
        onSuccess: () => {
            showModal.value = false;
        },
    });
};

const deleteDomain = (id, name) => {
    if (confirm(`Are you sure you want to delete domain '${name}'? This removes the vhost configuration and website files.`)) {
        router.delete(`/domains/${id}`);
    }
};

const changePhp = (domainId, newVer) => {
    router.post(`/domains/${domainId}/php`, { php_version: newVer });
};
</script>

<template>
    <PanelLayout>
        <Head title="Domains Management" />

        <div class="space-y-6">
            <!-- Header -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 class="text-xl sm:text-2xl font-bold text-white flex items-center gap-2.5">
                        <Globe class="w-6 h-6 text-emerald-400" />
                        <span>Domains Management</span>
                    </h1>
                    <p class="text-xs sm:text-sm text-slate-400 mt-1">
                        Configure virtual hosts, document roots, and per-domain SuEXEC PHP external processors.
                    </p>
                </div>

                <button
                    @click="openAddModal"
                    class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white text-sm font-medium transition-colors shadow-sm shadow-emerald-600/30"
                >
                    <Plus class="w-4 h-4" />
                    <span>Add Domain</span>
                </button>
            </div>

            <!-- Table -->
            <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden shadow-sm">
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm text-slate-300">
                        <thead class="bg-slate-950/50 text-xs font-semibold text-slate-400 uppercase tracking-wider border-b border-slate-800">
                            <tr>
                                <th class="px-5 py-3">Domain</th>
                                <th v-if="isAdmin" class="px-5 py-3">User</th>
                                <th class="px-5 py-3">Document Root</th>
                                <th class="px-5 py-3">PHP Version</th>
                                <th class="px-5 py-3">SSL Status</th>
                                <th class="px-5 py-3 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800/60">
                            <tr v-for="dom in domains.data" :key="dom.id" class="hover:bg-slate-800/40 transition-colors">
                                <td class="px-5 py-3.5">
                                    <div class="flex items-center gap-3">
                                        <div class="w-9 h-9 rounded-lg bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 flex items-center justify-center font-bold text-xs uppercase">
                                            <Globe class="w-4 h-4" />
                                        </div>
                                        <div>
                                            <div class="font-semibold text-white flex items-center gap-2">
                                                <a :href="`http://${dom.domain}`" target="_blank" class="hover:underline hover:text-emerald-400">
                                                    {{ dom.domain }}
                                                </a>
                                                <span class="px-1.5 py-0.5 rounded text-[10px] font-semibold uppercase bg-slate-800 text-slate-400 border border-slate-700">
                                                    {{ dom.type }}
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td v-if="isAdmin" class="px-5 py-3.5 text-xs text-slate-300">
                                    {{ dom.user?.username || '—' }}
                                </td>
                                <td class="px-5 py-3.5 text-xs font-mono text-slate-400">
                                    <span class="flex items-center gap-1.5 truncate max-w-xs" :title="dom.document_root">
                                        <Folder class="w-3.5 h-3.5 text-slate-500 shrink-0" />
                                        {{ dom.document_root }}
                                    </span>
                                </td>
                                <td class="px-5 py-3.5 text-xs">
                                    <select
                                        :value="dom.php_version"
                                        @change="changePhp(dom.id, $event.target.value)"
                                        class="px-2.5 py-1 rounded bg-slate-950 border border-slate-800 text-slate-300 text-xs focus:outline-none focus:border-emerald-500"
                                    >
                                        <option value="74">PHP 7.4</option>
                                        <option value="80">PHP 8.0</option>
                                        <option value="81">PHP 8.1</option>
                                        <option value="82">PHP 8.2</option>
                                        <option value="83">PHP 8.3 (OLS Default)</option>
                                        <option value="84">PHP 8.4</option>
                                        <option value="85">PHP 8.5</option>
                                    </select>
                                </td>
                                <td class="px-5 py-3.5">
                                    <span
                                        v-if="dom.ssl_enabled"
                                        class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-semibold bg-emerald-500/10 text-emerald-400 border border-emerald-500/20"
                                    >
                                        <ShieldCheck class="w-3.5 h-3.5" />
                                        SSL Active
                                    </span>
                                    <span
                                        v-else
                                        class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-semibold bg-amber-500/10 text-amber-400 border border-amber-500/20"
                                    >
                                        <ShieldAlert class="w-3.5 h-3.5" />
                                        Self-Signed
                                    </span>
                                </td>
                                <td class="px-5 py-3.5 text-right">
                                    <button
                                        @click="deleteDomain(dom.id, dom.domain)"
                                        title="Delete Domain"
                                        class="p-1.5 rounded-lg bg-slate-800 hover:bg-rose-500/20 text-rose-400 transition-colors"
                                    >
                                        <Trash2 class="w-4 h-4" />
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="!domains.data || domains.data.length === 0">
                                <td :colspan="isAdmin ? 6 : 5" class="px-5 py-8 text-center text-slate-500 text-sm">
                                    No domains configured yet.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Add Domain Modal -->
            <Modal :show="showModal" max-width="md" @close="showModal = false">
                <div class="p-6 space-y-4">
                    <div class="flex items-center justify-between border-b border-slate-800 pb-3">
                        <h3 class="text-base font-bold text-white">Add New Domain</h3>
                        <button @click="showModal = false" class="text-slate-400 hover:text-white">
                            <X class="w-5 h-5" />
                        </button>
                    </div>

                    <form @submit.prevent="submit" class="space-y-4">
                        <div v-if="isAdmin">
                            <label class="block text-xs font-semibold text-slate-300 uppercase mb-1">Assign User</label>
                            <select
                                v-model="form.user_id"
                                required
                                class="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-lg text-sm text-slate-200 focus:outline-none focus:border-emerald-500"
                            >
                                <option v-for="u in users" :key="u.id" :value="u.id">
                                    {{ u.username }} ({{ u.name }})
                                </option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-300 uppercase mb-1">Domain Name</label>
                            <input
                                v-model="form.domain"
                                type="text"
                                placeholder="example.com"
                                required
                                class="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-lg text-sm text-slate-200 focus:outline-none focus:border-emerald-500"
                            />
                            <p v-if="form.errors.domain" class="text-xs text-rose-400 mt-1">{{ form.errors.domain }}</p>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-300 uppercase mb-1">PHP Version</label>
                            <select
                                v-model="form.php_version"
                                class="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-lg text-sm text-slate-200 focus:outline-none focus:border-emerald-500"
                            >
                                <option value="83">PHP 8.3 (Default)</option>
                                <option value="82">PHP 8.2</option>
                                <option value="81">PHP 8.1</option>
                                <option value="80">PHP 8.0</option>
                                <option value="74">PHP 7.4</option>
                            </select>
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
                                {{ form.processing ? 'Provisioning...' : 'Add Domain' }}
                            </button>
                        </div>
                    </form>
                </div>
            </Modal>
        </div>
    </PanelLayout>
</template>

