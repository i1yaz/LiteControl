<script setup>
import { shallowRef, computed } from 'vue';
import { Head, useForm, router, usePage } from '@inertiajs/vue3';
import Modal from '@/Components/Modal.vue';
import { Globe, Plus, ShieldCheck, ShieldAlert, Folder, Trash2, X, Pencil } from 'lucide-vue-next';

const props = defineProps({
    domains: Object,
    phpVersions: Array,
    users: Array,
});

const page = usePage();
const isAdmin = page.props.auth?.user?.role === 'admin';
const currentUsername = page.props.auth?.user?.username || '';

const showModal = shallowRef(false);
const showEditModal = shallowRef(false);
const editingDomain = shallowRef(null);

const form = useForm({
    domain: '',
    php_version: '83',
    public_path: '',
    ssl_enabled: true,
    type: 'addon',
    user_id: props.users?.[0]?.id || '',
});

const editForm = useForm({
    php_version: '83',
    public_path: '',
    ssl_enabled: false,
});

const selectedUser = computed(() => {
    if (!isAdmin) return currentUsername;
    const u = props.users?.find(item => item.id == form.user_id);
    return u ? u.username : currentUsername;
});

const addDocRootPreview = computed(() => {
    const user = selectedUser.value || '<user>';
    const dom = form.domain.trim() || '<domain>';
    const sub = form.public_path.trim().replace(/^\/+|\/+$/g, '');
    const base = `/home/${user}/${dom}/public_html`;
    return sub ? `${base}/${sub}` : base;
});

const editDocRootPreview = computed(() => {
    if (!editingDomain.value) return '';
    const user = editingDomain.value.user?.username || currentUsername;
    const dom = editingDomain.value.domain;
    const sub = editForm.public_path.trim().replace(/^\/+|\/+$/g, '');
    const base = `/home/${user}/${dom}/public_html`;
    return sub ? `${base}/${sub}` : base;
});

const openAddModal = () => {
    form.reset();
    form.ssl_enabled = true;
    showModal.value = true;
};

const openEditModal = (dom) => {
    editingDomain.value = dom;
    editForm.php_version = dom.php_version || '83';
    editForm.public_path = dom.public_path || '';
    editForm.ssl_enabled = dom.ssl_enabled || false;
    editForm.clearErrors();
    showEditModal.value = true;
};

const submit = () => {
    form.post('/domains', {
        onSuccess: () => {
            showModal.value = false;
        },
    });
};

const submitEdit = () => {
    if (!editingDomain.value) return;
    editForm.put(`/domains/${editingDomain.value.id}`, {
        onSuccess: () => {
            showEditModal.value = false;
            editingDomain.value = null;
        },
    });
};

const deleteDomain = (id, name) => {
    if (confirm(`Are you sure you want to terminate domain '${name}'? This removes the vhost configuration and website files.`)) {
        router.delete(`/domains/${id}`);
    }
};

const changePhp = (domainId, newVer) => {
    router.post(`/domains/${domainId}/php`, { php_version: newVer });
};
</script>

<template>
    <Head title="Virtual Host Routing - LiteControl" />

    <div class="space-y-6">
        <!-- Asymmetrical Spec Header -->
        <div class="flex flex-col sm:flex-row sm:items-end justify-between gap-4 pb-4 border-b border-[#1b222d]">
            <div>
                <div class="flex items-center gap-2 mb-1">
                    <span class="text-[10px] font-mono tracking-widest uppercase text-[#ff6b35]">Network Gateway</span>
                    <span class="text-[#3a4454] font-mono">/</span>
                    <span class="text-[10px] font-mono text-[#6c7d93]">HTTP_VHOST_ROUTER</span>
                </div>
                <h1 class="text-2xl font-bold tracking-tight text-white font-mono uppercase flex items-center gap-3">
                    <Globe class="w-6 h-6 text-[#ff6b35]" />
                    <span>Domain Routing Registry</span>
                </h1>
            </div>

            <button
                @click="openAddModal"
                class="inline-flex items-center gap-2 px-4 py-2 bg-[#ff6b35] hover:bg-[#ff8252] text-black text-xs font-mono font-bold uppercase tracking-wider transition-colors shadow-[0_0_15px_rgba(255,107,53,0.25)] rounded-xs"
            >
                <Plus class="w-4 h-4 stroke-[2.5]" />
                <span>Provision Domain</span>
            </button>
        </div>

        <!-- Flash Notice / Warning if present -->
        <div v-if="$page.props.flash?.warning" class="p-3 bg-amber-500/10 border border-amber-500/20 rounded-xs text-xs font-mono text-amber-300 flex items-start gap-2.5">
            <span class="font-bold shrink-0">[WARN]:</span>
            <span>{{ $page.props.flash.warning }}</span>
        </div>

        <!-- VHost Grid Matrix -->
        <div class="bg-[#0b0e14] border border-[#1b2330] rounded-xs overflow-hidden">
            <div class="px-5 py-3 border-b border-[#1b2330] bg-[#0f131a] flex items-center justify-between">
                <span class="text-[11px] font-mono uppercase text-[#7a8b9f] tracking-wider">
                    Configured Ingress Nodes ({{ domains.total ?? domains.data?.length ?? 0 }})
                </span>
                <span class="text-[10px] font-mono text-[#00f0a8]">STATUS: OLS_DAEMON_OK</span>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs font-mono text-[#cad3e0]">
                    <thead class="bg-[#090c11] text-[10px] font-semibold text-[#5a6b80] uppercase tracking-widest border-b border-[#1b2330]">
                        <tr>
                            <th class="px-5 py-3.5">Target Host FQDN</th>
                            <th v-if="isAdmin" class="px-5 py-3.5">Assigned Principal</th>
                            <th class="px-5 py-3.5">Routing Topology</th>
                            <th class="px-5 py-3.5">TLS / SSL Ingress</th>
                            <th class="px-5 py-3.5">SuEXEC PHP Engine</th>
                            <th class="px-5 py-3.5">Document Root Target</th>
                            <th class="px-5 py-3.5 text-right">Operations</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#151b24]">
                        <tr v-for="dom in domains.data" :key="dom.id" class="hover:bg-[#12161f] transition-colors group">
                            <td class="px-5 py-3.5 font-bold text-white">
                                <div class="flex items-center gap-2">
                                    <span class="w-1.5 h-1.5 rounded-full bg-[#ff6b35]/60 group-hover:bg-[#ff6b35]"></span>
                                    <span>{{ dom.domain }}</span>
                                </div>
                            </td>
                            <td v-if="isAdmin" class="px-5 py-3.5 text-[#8ea0b5]">
                                {{ dom.user?.username || '—' }}
                            </td>
                            <td class="px-5 py-3.5">
                                <span class="inline-flex items-center px-1.5 py-0.5 rounded-xs text-[10px] uppercase font-mono bg-[#161c26] text-[#8ea0b5] border border-[#222c3c]">
                                    {{ dom.type }}
                                </span>
                            </td>
                            <td class="px-5 py-3.5">
                                <span
                                    v-if="dom.ssl_enabled"
                                    class="inline-flex items-center gap-1 text-[11px] text-[#00f0a8]"
                                >
                                    <ShieldCheck class="w-3.5 h-3.5" />
                                    <span>SECURE_TLS</span>
                                </span>
                                <span
                                    v-else
                                    class="inline-flex items-center gap-1 text-[11px] text-[#64748b]"
                                >
                                    <ShieldAlert class="w-3.5 h-3.5" />
                                    <span>INSECURE_PLAINTEXT</span>
                                </span>
                            </td>
                            <td class="px-5 py-3.5">
                                <select
                                    :value="dom.php_version"
                                    @change="changePhp(dom.id, $event.target.value)"
                                    class="px-2 py-0.5 bg-[#171d27] border border-[#252f3f] text-[#8ea0b5] rounded-xs text-[10px] focus:outline-none focus:border-[#ff6b35]"
                                >
                                    <option value="74">PHP 7.4</option>
                                    <option value="80">PHP 8.0</option>
                                    <option value="81">PHP 8.1</option>
                                    <option value="82">PHP 8.2</option>
                                    <option value="83">PHP 8.3 (Default)</option>
                                    <option value="84">PHP 8.4</option>
                                    <option value="85">PHP 8.5</option>
                                </select>
                            </td>
                            <td class="px-5 py-3.5 text-[#5e6f85] truncate max-w-xs" :title="dom.document_root">
                                <span class="flex items-center gap-1.5">
                                    <Folder class="w-3.5 h-3.5 text-[#3e4b5e] shrink-0" />
                                    <span>{{ dom.document_root }}</span>
                                    <span v-if="dom.public_path" class="text-[9px] px-1 py-0.2 bg-[#ff6b35]/10 text-[#ff6b35] rounded-xs font-mono">
                                        /{{ dom.public_path }}
                                    </span>
                                </span>
                            </td>
                            <td class="px-5 py-3.5 text-right">
                                <div class="flex items-center justify-end gap-1.5">
                                    <button
                                        @click="openEditModal(dom)"
                                        class="text-[#5e6f85] hover:text-[#00f0a8] transition-colors p-1"
                                        title="Configure Domain & Pointing"
                                    >
                                        <Pencil class="w-4 h-4" />
                                    </button>
                                    <button
                                        @click="deleteDomain(dom.id, dom.domain)"
                                        class="text-[#5e6f85] hover:text-[#ff3366] transition-colors p-1"
                                        title="Terminate VHost"
                                    >
                                        <Trash2 class="w-4 h-4" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!domains.data || domains.data.length === 0">
                            <td :colspan="isAdmin ? 7 : 6" class="px-5 py-8 text-center text-[#5a6b80] text-xs font-mono">
                                NO INGRESS NODES REGISTERED
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Add Domain Modal -->
        <Modal :show="showModal" max-width="md" @close="showModal = false">
            <div class="p-6 space-y-5">
                <div class="flex items-center justify-between border-b border-[#1e2634] pb-3">
                    <div class="flex items-center gap-2">
                        <span class="w-2 h-2 bg-[#ff6b35]"></span>
                        <h3 class="text-sm font-mono font-bold uppercase tracking-wider text-white">Provision Virtual Host</h3>
                    </div>
                    <button @click="showModal = false" class="text-[#64748b] hover:text-white transition-colors">
                        <X class="w-4 h-4" />
                    </button>
                </div>

                <form @submit.prevent="submit" class="space-y-4 font-mono text-xs">
                    <div v-if="isAdmin">
                        <label class="block text-[10px] uppercase tracking-wider text-[#7a8b9f] mb-1.5">Target Principal</label>
                        <select
                            v-model="form.user_id"
                            required
                            class="w-full px-3 py-2 bg-[#080b0f] border border-[#1e2634] rounded-xs text-[#cad3e0] text-xs focus:outline-none focus:border-[#ff6b35]"
                        >
                            <option v-for="u in users" :key="u.id" :value="u.id">
                                {{ u.username }} ({{ u.name }})
                            </option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-[10px] uppercase tracking-wider text-[#7a8b9f] mb-1.5">Target FQDN / Domain Name</label>
                        <input
                            v-model="form.domain"
                            type="text"
                            placeholder="app.example.com"
                            required
                            class="w-full px-3 py-2 bg-[#080b0f] border border-[#1e2634] rounded-xs text-[#cad3e0] text-xs focus:outline-none focus:border-[#ff6b35]"
                        />
                        <p v-if="form.errors.domain" class="text-[10px] text-[#ff3366] mt-1">{{ form.errors.domain }}</p>
                    </div>

                    <!-- Web Root Subpath (Pointing Target) -->
                    <div>
                        <label class="block text-[10px] uppercase tracking-wider text-[#7a8b9f] mb-1.5 flex items-center justify-between">
                            <span>Web Root Subpath (Pointing Target)</span>
                            <span class="text-[#5a6b80] font-normal">e.g. "public" for Laravel</span>
                        </label>
                        <input
                            v-model="form.public_path"
                            type="text"
                            placeholder="public (leave empty for standard public_html)"
                            class="w-full px-3 py-2 bg-[#080b0f] border border-[#1e2634] rounded-xs text-[#cad3e0] text-xs focus:outline-none focus:border-[#ff6b35]"
                        />
                        <p class="text-[10px] text-[#5e6f85] mt-1">
                            Target DocRoot: <span class="text-[#00f0a8]">{{ addDocRootPreview }}</span>
                        </p>
                    </div>

                    <div>
                        <label class="block text-[10px] uppercase tracking-wider text-[#7a8b9f] mb-1.5">SuEXEC PHP Engine Profile</label>
                        <select
                            v-model="form.php_version"
                            class="w-full px-3 py-2 bg-[#080b0f] border border-[#1e2634] rounded-xs text-[#cad3e0] text-xs focus:outline-none focus:border-[#ff6b35]"
                        >
                            <option value="83">PHP 8.3 (Default OLS)</option>
                            <option value="82">PHP 8.2</option>
                            <option value="81">PHP 8.1</option>
                            <option value="80">PHP 8.0</option>
                            <option value="74">PHP 7.4</option>
                        </select>
                    </div>

                    <!-- Issue SSL Toggle (Default ON) -->
                    <div class="pt-2 border-t border-[#1e2634] flex items-center justify-between">
                        <div>
                            <span class="text-xs text-white block">Generate Let's Encrypt SSL</span>
                            <span class="text-[10px] text-[#5e6f85]">Default: ON (Requires DNS A-record pointed)</span>
                        </div>
                        <label class="relative inline-flex items-center cursor-pointer">
                            <input
                                v-model="form.ssl_enabled"
                                type="checkbox"
                                class="sr-only peer"
                            />
                            <div class="w-8 h-4 bg-[#1e2634] peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-3 after:w-3 after:transition-all peer-checked:bg-[#00f0a8]"></div>
                        </label>
                    </div>

                    <div class="pt-4 flex items-center justify-end gap-3 border-t border-[#1e2634]">
                        <button
                            type="button"
                            @click="showModal = false"
                            class="px-4 py-2 rounded-xs bg-[#161c26] hover:bg-[#202837] text-xs font-mono uppercase tracking-wider text-[#8ea0b5] transition-colors"
                        >
                            Abort
                        </button>
                        <button
                            type="submit"
                            :disabled="form.processing"
                            class="px-5 py-2 rounded-xs bg-[#ff6b35] hover:bg-[#ff8252] disabled:opacity-50 text-xs font-mono font-bold uppercase tracking-wider text-black transition-colors shadow-[0_0_15px_rgba(255,107,53,0.25)]"
                        >
                            {{ form.processing ? 'Deploying...' : 'Provision' }}
                        </button>
                    </div>
                </form>
            </div>
        </Modal>

        <!-- Edit Domain Modal -->
        <Modal :show="showEditModal" max-width="md" @close="showEditModal = false">
            <div class="p-6 space-y-5">
                <div class="flex items-center justify-between border-b border-[#1e2634] pb-3">
                    <div class="flex items-center gap-2">
                        <span class="w-2 h-2 bg-[#00f0a8]"></span>
                        <h3 class="text-sm font-mono font-bold uppercase tracking-wider text-white">
                            Configure Domain: {{ editingDomain?.domain }}
                        </h3>
                    </div>
                    <button @click="showEditModal = false" class="text-[#64748b] hover:text-white transition-colors">
                        <X class="w-4 h-4" />
                    </button>
                </div>

                <form @submit.prevent="submitEdit" class="space-y-4 font-mono text-xs">
                    <div>
                        <label class="block text-[10px] uppercase tracking-wider text-[#7a8b9f] mb-1.5">Target FQDN (Read-Only)</label>
                        <input
                            :value="editingDomain?.domain"
                            disabled
                            class="w-full px-3 py-2 bg-[#12161f] border border-[#1e2634] rounded-xs text-[#8ea0b5] text-xs cursor-not-allowed"
                        />
                    </div>

                    <!-- Web Root Subpath (Pointing Target) -->
                    <div>
                        <label class="block text-[10px] uppercase tracking-wider text-[#7a8b9f] mb-1.5 flex items-center justify-between">
                            <span>Web Root Subpath (Pointing Target)</span>
                            <span class="text-[#5a6b80] font-normal">e.g. "public" for Laravel</span>
                        </label>
                        <input
                            v-model="editForm.public_path"
                            type="text"
                            placeholder="public (leave empty for standard public_html)"
                            class="w-full px-3 py-2 bg-[#080b0f] border border-[#1e2634] rounded-xs text-[#cad3e0] text-xs focus:outline-none focus:border-[#00f0a8]"
                        />
                        <p class="text-[10px] text-[#5e6f85] mt-1">
                            Updated DocRoot: <span class="text-[#00f0a8]">{{ editDocRootPreview }}</span>
                        </p>
                    </div>

                    <div>
                        <label class="block text-[10px] uppercase tracking-wider text-[#7a8b9f] mb-1.5">SuEXEC PHP Engine Profile</label>
                        <select
                            v-model="editForm.php_version"
                            class="w-full px-3 py-2 bg-[#080b0f] border border-[#1e2634] rounded-xs text-[#cad3e0] text-xs focus:outline-none focus:border-[#00f0a8]"
                        >
                            <option value="83">PHP 8.3 (Default OLS)</option>
                            <option value="82">PHP 8.2</option>
                            <option value="81">PHP 8.1</option>
                            <option value="80">PHP 8.0</option>
                            <option value="74">PHP 7.4</option>
                        </select>
                    </div>

                    <!-- SSL Certificate Status & Issue Toggle -->
                    <div class="pt-2 border-t border-[#1e2634] flex items-center justify-between">
                        <div>
                            <span class="text-xs text-white block">TLS / SSL Encryption</span>
                            <span class="text-[10px] text-[#5e6f85]">
                                {{ editingDomain?.ssl_enabled ? 'Active Let\'s Encrypt Certificate' : 'Disabled (toggle ON to request SSL)' }}
                            </span>
                        </div>
                        <label class="relative inline-flex items-center cursor-pointer">
                            <input
                                v-model="editForm.ssl_enabled"
                                type="checkbox"
                                :disabled="editingDomain?.ssl_enabled"
                                class="sr-only peer"
                            />
                            <div class="w-8 h-4 bg-[#1e2634] peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-3 after:w-3 after:transition-all peer-checked:bg-[#00f0a8] peer-disabled:opacity-60"></div>
                        </label>
                    </div>

                    <div class="pt-4 flex items-center justify-end gap-3 border-t border-[#1e2634]">
                        <button
                            type="button"
                            @click="showEditModal = false"
                            class="px-4 py-2 rounded-xs bg-[#161c26] hover:bg-[#202837] text-xs font-mono uppercase tracking-wider text-[#8ea0b5] transition-colors"
                        >
                            Abort
                        </button>
                        <button
                            type="submit"
                            :disabled="editForm.processing"
                            class="px-5 py-2 rounded-xs bg-[#00f0a8] hover:bg-[#00c98d] disabled:opacity-50 text-xs font-mono font-bold uppercase tracking-wider text-black transition-colors shadow-[0_0_15px_rgba(0,240,168,0.25)]"
                        >
                            {{ editForm.processing ? 'Updating...' : 'Save Configuration' }}
                        </button>
                    </div>
                </form>
            </div>
        </Modal>
    </div>
</template>
