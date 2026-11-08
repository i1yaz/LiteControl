<script setup>
import { shallowRef, computed } from 'vue';
import { Head, useForm, router, usePage } from '@inertiajs/vue3';
import UiPageHeader from '@/Components/ui/UiPageHeader.vue';
import UiButton from '@/Components/ui/UiButton.vue';
import UiModal from '@/Components/ui/UiModal.vue';
import UiInput from '@/Components/ui/UiInput.vue';
import UiSelect from '@/Components/ui/UiSelect.vue';
import UiDataTable from '@/Components/ui/UiDataTable.vue';
import { Globe, Plus, ShieldCheck, ShieldAlert, Folder, Trash2 } from 'lucide-vue-next';

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
        { label: 'Security Perimeter' },
        { label: 'PHP SuEXEC Runtime' },
        { label: 'Storage Root' },
        { label: 'Operations', class: 'text-right' }
    );
    return headers;
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
                    <span class="px-1.5 py-0.5 rounded text-[10px] uppercase bg-theme-bg-surface text-theme-text-secondary border border-theme-border ml-1">
                        {{ dom.type }}
                    </span>
                </td>
                <td v-if="isAdmin" class="py-3.5 px-4 text-theme-text-secondary">
                    {{ dom.user?.username || '—' }}
                </td>
                <td class="py-3.5 px-4">
                    <span
                        v-if="dom.ssl_enabled || dom.ssl_status"
                        class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded text-xs bg-emerald-500/10 text-emerald-400 border border-emerald-500/30"
                    >
                        <ShieldCheck class="w-3.5 h-3.5" /> TLS Active
                    </span>
                    <span
                        v-else
                        class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded text-xs bg-rose-500/10 text-rose-400 border border-rose-500/30"
                    >
                        <ShieldAlert class="w-3.5 h-3.5" /> Plaintext
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
                        {{ dom.document_root }}
                    </span>
                </td>
                <td class="py-3.5 px-4 text-right">
                    <button
                        @click="deleteDomain(dom.id, dom.domain)"
                        class="text-theme-text-muted hover:text-rose-400 transition-colors p-1"
                        title="Terminate VHost"
                    >
                        <Trash2 class="w-4 h-4" />
                    </button>
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

                <div>
                    <UiSelect
                        id="php_version"
                        v-model="form.php_version"
                        label="SuEXEC PHP Engine Profile"
                        :options="phpOptions"
                    />
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
    </div>
</template>
