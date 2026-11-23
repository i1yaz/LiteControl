<script setup lang="ts">
import { shallowRef, computed } from 'vue';
import { Head, useForm, router, usePage } from '@inertiajs/vue3';
import { confirmDialog } from '@shared/Composables/useDialog';
import { usePhpVersions } from '@shared/Composables/usePhpVersions';
import UiPageHeader from '@shared/Ui/UiPageHeader.vue';
import UiButton from '@shared/Ui/UiButton.vue';
import UiModal from '@shared/Ui/UiModal.vue';
import UiInput from '@shared/Ui/UiInput.vue';
import UiSelect from '@shared/Ui/UiSelect.vue';
import UiDataTable from '@shared/Ui/UiDataTable.vue';
import UiBadge from '@shared/Ui/UiBadge.vue';
import UiAlert from '@shared/Ui/UiAlert.vue';
import UiToggle from '@shared/Ui/UiToggle.vue';
import { Globe, Plus, ShieldCheck, ShieldAlert, Folder, Trash2, Pencil, Terminal, Check, LayoutGrid, FolderOpen, PauseCircle, PlayCircle, Download } from '@lucide/vue';

const props = defineProps({
    domains: Object,
    phpVersions: Array,
    users: Array,
});

const { phpOptions } = usePhpVersions(() => props.phpVersions);
const { phpOptions: installedPhpOptions } = usePhpVersions(() =>
    Array.isArray(props.phpVersions) ? props.phpVersions.filter((v) => v?.installed) : []
);

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
    type: 'primary',
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

const tableHeaders = computed(() => {
    const headers = [
        { label: 'Target Host FQDN' },
    ];
    if (isAdmin) {
        headers.push({ label: 'Assigned User' });
    }
    headers.push(
        { label: 'Routing Topology' },
        { label: 'TLS / SSL Ingress' },
        { label: 'PHP Runtime' },
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
    form.type = 'primary';
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

const deleteDomain = async (id, name) => {
    if (await confirmDialog({
        title: 'Delete Domain',
        message: `Are you sure you want to delete domain '${name}'? This removes the vhost configuration and website files.`,
        confirmText: 'Delete',
        variant: 'danger',
    })) {
        router.delete(`/domains/${id}`);
    }
};

const changePhp = (domainId, newVer) => {
    router.post(`/domains/${domainId}/php`, { php_version: newVer });
};

const missingPhpVersion = computed(() => {
    if (page.props.flash?.missing_php_version) {
        return String(page.props.flash.missing_php_version);
    }
    const err = page.props.flash?.error || '';
    const match = err.match(/PHP version lsphp(\d+) is not installed/i);
    return match ? match[1] : null;
});

const formatPhpVersion = (ver) => {
    if (!ver || ver.length < 2) return ver;
    return `${ver[0]}.${ver.slice(1)}`;
};

const installingPhp = shallowRef(false);
const installPhpVersion = async (ver) => {
    if (await confirmDialog({
        title: 'Install PHP Version',
        message: `Install lsphp${ver} (PHP ${formatPhpVersion(ver)}) from the official LiteSpeed repository?`,
        confirmText: 'Install',
    })) {
        installingPhp.value = true;
        router.post('/php/versions/install', { version: ver }, {
            onFinish: () => {
                installingPhp.value = false;
            },
        });
    }
};

const toggleSuspend = async (dom) => {
    const isSuspended = dom.status === 'suspended';
    const action = isSuspended ? 'unsuspend' : 'suspend';
    if (await confirmDialog({
        title: isSuspended ? 'Unsuspend Domain' : 'Suspend Domain',
        message: `Are you sure you want to ${action} domain '${dom.domain}'?`,
        confirmText: isSuspended ? 'Unsuspend' : 'Suspend',
        variant: isSuspended ? 'primary' : 'warning',
    })) {
        router.post(`/domains/${dom.id}/${action}`);
    }
};

const copiedUser = shallowRef(null);
const copySshCommand = (username) => {
    if (!username) return;
    const serverIp = page.props.panel?.server_ip || window.location.hostname || '127.0.0.1';
    const cmd = `ssh ${username}@${serverIp}`;
    navigator.clipboard?.writeText(cmd).then(() => {
        copiedUser.value = username;
        setTimeout(() => {
            if (copiedUser.value === username) {
                copiedUser.value = null;
            }
        }, 2000);
    });
};
</script>

<template>
    <Head :title="__('Virtual Host Routing - LiteControl')" />

    <div class="space-y-6">
        <UiPageHeader
            :title="__('Domain Routing Registry')"
            subtitle="Network Gateway // HTTP_VHOST_ROUTER"
        >
            <template #actions>
                <UiButton @click="openAddModal" variant="primary">
                    <Plus class="w-4 h-4 mr-1.5 inline" />
                    Provision Domain
                </UiButton>
            </template>
        </UiPageHeader>

        <!-- Flash Notice / Warning / Error if present -->
        <UiAlert v-if="$page.props.flash?.error" variant="danger">
            <span class="font-bold mr-1.5">{{ __('[ERROR]:') }}</span>
            <span>{{ $page.props.flash.error }}</span>
        </UiAlert>
        <UiAlert v-if="$page.props.flash?.warning" variant="warning">
            <span class="font-bold mr-1.5">{{ __('[WARN]:') }}</span>
            <span>{{ $page.props.flash.warning }}</span>
        </UiAlert>

        <!-- Quick Install Missing PHP Version (Admin Only) -->
        <div v-if="isAdmin && missingPhpVersion" class="p-3 bg-theme-info-bg border border-theme-info-border rounded-theme-md text-xs text-theme-info-text flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div class="space-y-1">
                <div>
                    PHP <strong>{{ formatPhpVersion(missingPhpVersion) }}</strong> (<code>lsphp{{ missingPhpVersion }}</code>) is not installed on this server.
                </div>
                <div class="text-[11px] text-theme-text-secondary">
                    Or install via CLI: <code class="px-1.5 py-0.5 rounded-theme-sm bg-theme-bg-input text-theme-text-primary border border-theme-border select-all">sudo lc-cli php install {{ missingPhpVersion }}</code>
                </div>
            </div>
            <UiButton
                @click="installPhpVersion(missingPhpVersion)"
                :disabled="installingPhp"
                variant="primary"
                class="shrink-0"
            >
                <Download class="w-3.5 h-3.5 mr-1.5 inline" />
                {{ installingPhp ? `Installing PHP ${formatPhpVersion(missingPhpVersion)}...` : `Install PHP ${formatPhpVersion(missingPhpVersion)}` }}
            </UiButton>
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
                    <UiBadge variant="neutral" class="uppercase">
                        {{ dom.type }}
                    </UiBadge>
                </td>
                <td class="py-3.5 px-4">
                    <UiBadge v-if="dom.ssl_enabled" variant="success">
                        <ShieldCheck class="w-3.5 h-3.5" /> SECURE_TLS
                    </UiBadge>
                    <UiBadge v-else variant="danger">
                        <ShieldAlert class="w-3.5 h-3.5" /> INSECURE_PLAINTEXT
                    </UiBadge>
                </td>
                <td class="py-3.5 px-4">
                    <select
                        :value="dom.php_version"
                        @change="changePhp(dom.id, $event.target.value)"
                        class="px-2 py-1 bg-theme-bg-surface border border-theme-border text-theme-text-secondary rounded-theme-sm text-xs focus:outline-none focus:border-theme-accent"
                    >
                        <option v-for="opt in phpOptions" :key="opt.value" :value="opt.value">
                            {{ opt.label }}
                        </option>
                    </select>
                </td>
                <td class="py-3.5 px-4 text-theme-text-muted truncate max-w-xs" :title="dom.document_root">
                    <span class="flex items-center gap-1.5">
                        <Folder class="w-3.5 h-3.5 text-theme-text-muted shrink-0" />
                        <span>{{ dom.document_root }}</span>
                        <span v-if="dom.public_path" class="text-[10px] px-1 py-0.2 bg-theme-accent-subtle text-theme-accent-text rounded-theme-sm border border-theme-accent-border">
                            /{{ dom.public_path }}
                        </span>
                    </span>
                </td>
                <td class="py-3.5 px-4 text-right">
                    <div class="flex items-center justify-end gap-1.5 flex-wrap">
                        <!-- Manage Button -->
                        <a
                            :href="`/domains/${dom.id}/manage`"
                            class="inline-flex items-center gap-1 px-2.5 py-1 rounded-theme-sm bg-theme-accent hover:bg-theme-accent-hover text-theme-text-on-accent text-xs font-semibold shadow-xs transition-colors"
                            :title="__('Manage Website')"
                        >
                            <LayoutGrid class="w-3.5 h-3.5" />
                            <span>{{ __('Manage') }}</span>
                        </a>

                        <!-- Filemanager Button -->
                        <a
                            :href="`/files?domain_id=${dom.id}`"
                            class="inline-flex items-center gap-1 px-2.5 py-1 rounded-theme-sm bg-theme-bg-surface hover:bg-theme-border text-theme-text-secondary hover:text-theme-text-primary text-xs font-medium border border-theme-border transition-colors"
                            :title="__('Open File Manager')"
                        >
                            <FolderOpen class="w-3.5 h-3.5 text-theme-info-text" />
                            <span>{{ __('Filemanager') }}</span>
                        </a>

                        <!-- Copy SSH Command Button -->
                        <UiButton
                            v-if="dom.user?.username || currentUsername"
                            size="xs"
                            variant="secondary"
                            @click="copySshCommand(dom.user?.username || currentUsername)"
                            :title="copiedUser === (dom.user?.username || currentUsername) ? 'Copied SSH command!' : `Copy SSH command (ssh ${(dom.user?.username || currentUsername)}@${$page.props.panel?.server_ip || 'ip'})`"
                        >
                            <Check v-if="copiedUser === (dom.user?.username || currentUsername)" class="w-3.5 h-3.5 text-theme-success-text" />
                            <Terminal v-else class="w-3.5 h-3.5 text-theme-accent" />
                            <span>{{ copiedUser === (dom.user?.username || currentUsername) ? 'Copied!' : 'SSH' }}</span>
                        </UiButton>

                        <!-- Modify (Quick Edit Modal) -->
                        <UiButton
                            size="xs"
                            variant="secondary"
                            @click="openEditModal(dom)"
                            :title="__('Quick Modify Domain')"
                        >
                            <Pencil class="w-3.5 h-3.5 text-theme-success-text" />
                            <span>{{ __('Modify') }}</span>
                        </UiButton>

                        <!-- Suspend / Unsuspend Button -->
                        <UiButton
                            size="xs"
                            :variant="dom.status === 'suspended' ? 'warning' : 'secondary'"
                            @click="toggleSuspend(dom)"
                            :title="dom.status === 'suspended' ? 'Activate / Unsuspend Domain' : 'Suspend Domain'"
                        >
                            <PlayCircle v-if="dom.status === 'suspended'" class="w-3.5 h-3.5 text-theme-warning-text" />
                            <PauseCircle v-else class="w-3.5 h-3.5 text-theme-warning-text" />
                            <span>{{ dom.status === 'suspended' ? 'Unsuspend' : 'Suspend' }}</span>
                        </UiButton>

                        <!-- Delete Button (Danger Tinted) -->
                        <UiButton
                            size="xs"
                            variant="danger-soft"
                            @click="deleteDomain(dom.id, dom.domain)"
                            :title="__('Delete VHost')"
                        >
                            <Trash2 class="w-3.5 h-3.5" />
                            <span>{{ __('Delete') }}</span>
                        </UiButton>
                    </div>
                </td>
            </tr>
        </UiDataTable>

        <!-- Add Domain Modal -->
        <UiModal
            :show="showModal"
            :title="__('Provision Virtual Host')"
            max-width="md"
            @close="showModal = false"
        >
            <form @submit.prevent="submit" class="space-y-4">
                <div v-if="isAdmin">
                    <UiSelect
                        id="user_id"
                        v-model="form.user_id"
                        :label="__('Target User')"
                        :options="userOptions"
                        required
                    />
                </div>

                <div>
                    <UiInput
                        id="domain"
                        v-model="form.domain"
                        type="text"
                        :label="__('Target FQDN / Domain Name')"
                        :placeholder="__('app.example.com')"
                        :error="form.errors.domain"
                        required
                    />
                </div>

                <!-- Web Root Subpath (Pointing Target) -->
                <div>
                    <UiInput
                        id="public_path"
                        v-model="form.public_path"
                        type="text"
                        :label="__('Web Root Subpath (Pointing Target)')"
                        :placeholder="__('public (leave empty for standard public_html)')"
                    >
                        <template #label-right>
                            <span class="text-xs text-theme-text-muted font-normal lowercase">{{ __('e.g. "public" for Laravel') }}</span>
                        </template>
                    </UiInput>
                    <p class="text-[11px] text-theme-text-muted mt-1">
                        Target DocRoot: <span class="text-theme-accent">{{ addDocRootPreview }}</span>
                    </p>
                </div>

                <div>
                    <UiSelect
                        id="php_version"
                        v-model="form.php_version"
                        :label="__('PHP Runtime')"
                        :options="installedPhpOptions"
                    />
                </div>

                <!-- Issue SSL Toggle (Default ON) -->
                <UiToggle
                    v-model="form.ssl_enabled"
                    :label="__('Generate Let\'s Encrypt SSL')"
                    :description="__('Default: ON (Requires DNS A-record pointed)')"
                    class="pt-2 border-t border-theme-border"
                />

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
                    <UiInput
                        :model-value="editingDomain?.domain"
                        :label="__('Target FQDN (Read-Only)')"
                        disabled
                    />
                </div>

                <!-- Web Root Subpath (Pointing Target) -->
                <div>
                    <UiInput
                        id="edit_public_path"
                        v-model="editForm.public_path"
                        type="text"
                        :label="__('Web Root Subpath (Pointing Target)')"
                        :placeholder="__('public (leave empty for standard public_html)')"
                    >
                        <template #label-right>
                            <span class="text-xs text-theme-text-muted font-normal lowercase">{{ __('e.g. "public" for Laravel') }}</span>
                        </template>
                    </UiInput>
                    <p class="text-[11px] text-theme-text-muted mt-1">
                        Updated DocRoot: <span class="text-theme-accent">{{ editDocRootPreview }}</span>
                    </p>
                </div>

                <div>
                    <UiSelect
                        id="edit_php_version"
                        v-model="editForm.php_version"
                        :label="__('PHP Runtime')"
                        :options="phpOptions"
                    />
                </div>

                <!-- SSL Certificate Status & Issue Toggle -->
                <UiToggle
                    v-model="editForm.ssl_enabled"
                    :disabled="editingDomain?.ssl_enabled"
                    :label="__('TLS / SSL Encryption')"
                    :description="editingDomain?.ssl_enabled ? 'Active Let\'s Encrypt Certificate' : 'Disabled (toggle ON to request SSL)'"
                    class="pt-2 border-t border-theme-border"
                />

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
