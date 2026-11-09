<script setup>
import { shallowRef, computed } from 'vue';
import { Head, useForm, router, usePage } from '@inertiajs/vue3';
import UiPageHeader from '@/Components/ui/UiPageHeader.vue';
import UiButton from '@/Components/ui/UiButton.vue';
import UiModal from '@/Components/ui/UiModal.vue';
import UiInput from '@/Components/ui/UiInput.vue';
import UiSelect from '@/Components/ui/UiSelect.vue';
import UiDataTable from '@/Components/ui/UiDataTable.vue';
import { Globe, Plus, ShieldCheck, ShieldAlert, Folder, Trash2, Pencil } from 'lucide-vue-next';

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

const userOptions = computed(() => {
    return (props.users || []).map(u => ({
        value: u.id,
        label: `${u.username} (${u.name})`,
    }));
});

const phpOptions = [
    { value: '83', label: 'PHP 8.3 (Default OLS)' },
    { value: '82', label: 'PHP 8.2' },
    { value: '81', label: 'PHP 8.1' },
    { value: '80', label: 'PHP 8.0' },
    { value: '74', label: 'PHP 7.4' },
];

const tableHeaders = computed(() => {
    const headers = [
        { label: 'Target Host FQDN' },
    ];
    if (isAdmin) {
        headers.push({ label: 'Assigned Principal' });
    }
    headers.push(
        { label: 'Routing Topology' },
        { label: 'TLS / SSL Ingress' },
        { label: 'PHP SuEXEC Runtime' },
        { label: 'Storage Root' },
        { label: 'Operations', class: 'text-right' }
    );
    return headers;
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
        <UiPageHeader
            title="Domain Routing Registry"
            subtitle="Network Gateway // HTTP_VHOST_ROUTER"
        >
            <template #actions>
                <UiButton @click="openAddModal" variant="primary">
                    <Plus class="w-4 h-4 mr-1.5 inline" />
                    Provision Domain
                </UiButton>
            </template>
        </UiPageHeader>

        <!-- Flash Notice / Warning if present -->
        <div v-if="$page.props.flash?.warning" class="p-3 bg-amber-500/10 border border-amber-500/20 rounded text-xs text-amber-300 flex items-start gap-2.5">
            <span class="font-bold shrink-0">[WARN]:</span>
            <span>{{ $page.props.flash.warning }}</span>
        </div>

        <!-- VHost Grid Matrix via Theme-Aware DataTable -->
        <UiDataTable
            :headers="tableHeaders"
            :items-count="domains.data?.length || 0"
            empty-message="NO INGRESS NODES REGISTERED"
        >
            <tr
                v-for="dom in domains.data"
                :key="dom.id"
                class="hover:bg-theme-bg-surface/50 transition-colors group"
            >
                <td class="py-3.5 px-4 font-bold text-theme-text-primary flex items-center gap-2">
                    <span class="w-1.5 h-1.5 rounded-full bg-theme-accent"></span>
                    <a
                        :href="'http://' + dom.domain"
                        target="_blank"
                        class="hover:text-theme-accent transition-colors underline decoration-theme-border underline-offset-4"
                    >
                        {{ dom.domain }}
                    </a>
                </td>
                <td v-if="isAdmin" class="py-3.5 px-4 text-theme-text-secondary">
                    {{ dom.user?.username || '—' }}
                </td>
                <td class="py-3.5 px-4">
                    <span class="px-1.5 py-0.5 rounded text-[10px] uppercase bg-theme-bg-surface text-theme-text-secondary border border-theme-border">
                        {{ dom.type }}
                    </span>
                </td>
                <td class="py-3.5 px-4">
                    <span
                        v-if="dom.ssl_enabled"
                        class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded text-xs bg-emerald-500/10 text-emerald-400 border border-emerald-500/30"
                    >
                        <ShieldCheck class="w-3.5 h-3.5" /> SECURE_TLS
                    </span>
                    <span
                        v-else
                        class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded text-xs bg-rose-500/10 text-rose-400 border border-rose-500/30"
                    >
                        <ShieldAlert class="w-3.5 h-3.5" /> INSECURE_PLAINTEXT
                    </span>
                </td>
                <td class="py-3.5 px-4">
                    <select
                        :value="dom.php_version"
                        @change="changePhp(dom.id, $event.target.value)"
                        class="px-2 py-1 bg-theme-bg-surface border border-theme-border text-theme-text-secondary rounded text-xs focus:outline-none focus:border-theme-accent"
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
                <td class="py-3.5 px-4 text-theme-text-muted truncate max-w-xs" :title="dom.document_root">
                    <span class="flex items-center gap-1.5">
                        <Folder class="w-3.5 h-3.5 text-theme-text-muted shrink-0" />
                        <span>{{ dom.document_root }}</span>
                        <span v-if="dom.public_path" class="text-[10px] px-1 py-0.2 bg-theme-accent/10 text-theme-accent rounded border border-theme-accent/20">
                            /{{ dom.public_path }}
                        </span>
                    </span>
                </td>
                <td class="py-3.5 px-4 text-right">
                    <div class="flex items-center justify-end gap-1.5">
                        <button
                            @click="openEditModal(dom)"
                            class="text-theme-text-muted hover:text-emerald-400 transition-colors p-1"
                            title="Configure Domain & Pointing"
                        >
                            <Pencil class="w-4 h-4" />
                        </button>
                        <button
                            @click="deleteDomain(dom.id, dom.domain)"
                            class="text-theme-text-muted hover:text-rose-400 transition-colors p-1"
                            title="Terminate VHost"
                        >
                            <Trash2 class="w-4 h-4" />
                        </button>
                    </div>
                </td>
            </tr>
        </UiDataTable>

        <!-- Add Domain Modal -->
        <UiModal
            :show="showModal"
            title="Provision Virtual Host"
            max-width="md"
            @close="showModal = false"
        >
            <form @submit.prevent="submit" class="space-y-4">
                <div v-if="isAdmin">
                    <UiSelect
                        id="user_id"
                        v-model="form.user_id"
                        label="Target Principal"
                        :options="userOptions"
                        required
                    />
                </div>

                <div>
                    <UiInput
                        id="domain"
                        v-model="form.domain"
                        type="text"
                        label="Target FQDN / Domain Name"
                        placeholder="app.example.com"
                        :error="form.errors.domain"
                        required
                    />
                </div>

                <!-- Web Root Subpath (Pointing Target) -->
                <div>
                    <label class="block text-xs font-semibold text-theme-text-secondary uppercase tracking-wider mb-1 flex items-center justify-between">
                        <span>Web Root Subpath (Pointing Target)</span>
                        <span class="text-theme-text-muted font-normal lowercase">e.g. "public" for Laravel</span>
                    </label>
                    <input
                        v-model="form.public_path"
                        type="text"
                        placeholder="public (leave empty for standard public_html)"
                        class="w-full px-3 py-2 bg-theme-bg-surface border border-theme-border rounded text-xs text-theme-text-primary placeholder:text-theme-text-muted focus:outline-none focus:border-theme-accent transition-colors"
                    />
                    <p class="text-[11px] text-theme-text-muted mt-1">
                        Target DocRoot: <span class="text-theme-accent">{{ addDocRootPreview }}</span>
                    </p>
                </div>

                <div>
                    <UiSelect
                        id="php_version"
                        v-model="form.php_version"
                        label="SuEXEC PHP Engine Profile"
                        :options="phpOptions"
                    />
                </div>

                <!-- Issue SSL Toggle (Default ON) -->
                <div class="pt-2 border-t border-theme-border flex items-center justify-between">
                    <div>
                        <span class="text-xs text-theme-text-primary block font-medium">Generate Let's Encrypt SSL</span>
                        <span class="text-[11px] text-theme-text-muted">Default: ON (Requires DNS A-record pointed)</span>
                    </div>
                    <label class="relative inline-flex items-center cursor-pointer">
                        <input
                            v-model="form.ssl_enabled"
                            type="checkbox"
                            class="sr-only peer"
                        />
                        <div class="w-8 h-4 bg-theme-border peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-3 after:w-3 after:transition-all peer-checked:bg-emerald-500"></div>
                    </label>
                </div>

                <div class="pt-4 flex items-center justify-end gap-3 border-t border-theme-border">
                    <UiButton
                        type="button"
                        variant="secondary"
                        @click="showModal = false"
                    >
                        Abort
                    </UiButton>
                    <UiButton
                        type="submit"
                        variant="primary"
                        :loading="form.processing"
                        :disabled="form.processing"
                    >
                        {{ form.processing ? 'Deploying...' : 'Provision' }}
                    </UiButton>
                </div>
            </form>
        </UiModal>

        <!-- Edit Domain Modal -->
        <UiModal
            :show="showEditModal"
            :title="`Configure Domain: ${editingDomain?.domain}`"
            max-width="md"
            @close="showEditModal = false"
        >
            <form @submit.prevent="submitEdit" class="space-y-4">
                <div>
                    <label class="block text-xs font-semibold text-theme-text-secondary uppercase tracking-wider mb-1">Target FQDN (Read-Only)</label>
                    <input
                        :value="editingDomain?.domain"
                        disabled
                        class="w-full px-3 py-2 bg-theme-bg-surface/50 border border-theme-border rounded text-xs text-theme-text-muted cursor-not-allowed"
                    />
                </div>

                <!-- Web Root Subpath (Pointing Target) -->
                <div>
                    <label class="block text-xs font-semibold text-theme-text-secondary uppercase tracking-wider mb-1 flex items-center justify-between">
                        <span>Web Root Subpath (Pointing Target)</span>
                        <span class="text-theme-text-muted font-normal lowercase">e.g. "public" for Laravel</span>
                    </label>
                    <input
                        v-model="editForm.public_path"
                        type="text"
                        placeholder="public (leave empty for standard public_html)"
                        class="w-full px-3 py-2 bg-theme-bg-surface border border-theme-border rounded text-xs text-theme-text-primary placeholder:text-theme-text-muted focus:outline-none focus:border-theme-accent transition-colors"
                    />
                    <p class="text-[11px] text-theme-text-muted mt-1">
                        Updated DocRoot: <span class="text-theme-accent">{{ editDocRootPreview }}</span>
                    </p>
                </div>

                <div>
                    <UiSelect
                        id="edit_php_version"
                        v-model="editForm.php_version"
                        label="SuEXEC PHP Engine Profile"
                        :options="phpOptions"
                    />
                </div>

                <!-- SSL Certificate Status & Issue Toggle -->
                <div class="pt-2 border-t border-theme-border flex items-center justify-between">
                    <div>
                        <span class="text-xs text-theme-text-primary block font-medium">TLS / SSL Encryption</span>
                        <span class="text-[11px] text-theme-text-muted">
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
                        <div class="w-8 h-4 bg-theme-border peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-3 after:w-3 after:transition-all peer-checked:bg-emerald-500 peer-disabled:opacity-60"></div>
                    </label>
                </div>

                <div class="pt-4 flex items-center justify-end gap-3 border-t border-theme-border">
                    <UiButton
                        type="button"
                        variant="secondary"
                        @click="showEditModal = false"
                    >
                        Abort
                    </UiButton>
                    <UiButton
                        type="submit"
                        variant="primary"
                        :loading="editForm.processing"
                        :disabled="editForm.processing"
                    >
                        {{ editForm.processing ? 'Updating...' : 'Save Configuration' }}
                    </UiButton>
                </div>
            </form>
        </UiModal>
    </div>
</template>
