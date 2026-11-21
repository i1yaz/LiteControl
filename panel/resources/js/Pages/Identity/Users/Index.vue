<script setup lang="ts">
import { shallowRef, computed } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { confirmDialog } from '@shared/Composables/useDialog';
import UiPageHeader from '@shared/Ui/UiPageHeader.vue';
import UiButton from '@shared/Ui/UiButton.vue';
import UiModal from '@shared/Ui/UiModal.vue';
import UiInput from '@shared/Ui/UiInput.vue';
import UiPasswordInput from '@shared/Ui/UiPasswordInput.vue';
import UiSelect from '@shared/Ui/UiSelect.vue';
import UiTextarea from '@shared/Ui/UiTextarea.vue';
import UiBadge from '@shared/Ui/UiBadge.vue';
import UiDataTable from '@shared/Ui/UiDataTable.vue';
import {
    UserPlus,
    Search,
    Globe,
    Database,
    HardDrive,
    Lock,
    Unlock,
    Trash2,
    LogIn,
    Pencil,
    Key,
    Package as PackageIcon,
    Terminal,
    Check
} from '@lucide/vue';

const props = defineProps({
    users: Object,
    diskUsageMap: Object,
    filters: Object,
    packages: Array,
    installedPhpVersions: {
        type: Array,
        default: () => [],
    },
});

const search = shallowRef(props.filters?.search || '');

const handleSearch = () => {
    router.get('/users', { search: search.value }, { preserveState: true, replace: true });
};

const suspendUser = async (id, username) => {
    if (await confirmDialog({
        title: 'Suspend User',
        message: `Are you sure you want to suspend user '${username}'? Their virtual hosts will be deactivated.`,
        confirmText: 'Suspend',
        variant: 'warning',
    })) {
        router.post(`/users/${id}/suspend`);
    }
};

const unsuspendUser = (id, username) => {
    router.post(`/users/${id}/unsuspend`);
};

const deleteUser = async (id, username) => {
    if (await confirmDialog({
        title: 'Terminate User',
        message: `Terminate user '${username}'? ALL vhosts, schemas, and storage volumes will be permanently purged!`,
        confirmText: 'Terminate',
        variant: 'danger',
    })) {
        router.delete(`/users/${id}`);
    }
};

const impersonateUser = async (id, username) => {
    if (await confirmDialog({
        title: 'Impersonate User',
        message: `Impersonate as "${username}"?`,
        confirmText: 'Impersonate',
    })) {
        router.post(`/users/${id}/impersonate`);
    }
};

const showEditModal = shallowRef(false);
const editingUser = shallowRef(null);

const editForm = useForm({
    name: '',
    email: '',
    password: '',
    package_id: '',
    ssh_public_key: '',
});

const phpCliForm = useForm({
    version: '8.3',
});

const packageOptions = computed(() => {
    return (props.packages || []).map(pkg => ({
        value: pkg.id,
        label: `${pkg.name} (${pkg.disk_quota_mb ? `${pkg.disk_quota_mb} MB` : 'Unlimited'})`,
    }));
});

const openEditModal = (user) => {
    editingUser.value = user;
    editForm.name = user.name || '';
    editForm.email = user.email || '';
    editForm.password = '';
    editForm.package_id = user.package_id || props.packages?.[0]?.id || '';
    editForm.ssh_public_key = user.ssh_public_key || '';
    editForm.clearErrors();
    phpCliForm.version = user.php_cli_version || '8.3';
    phpCliForm.clearErrors();
    showEditModal.value = true;
};

const submitEdit = () => {
    if (!editingUser.value) return;
    editForm.put(`/users/${editingUser.value.id}`, {
        onSuccess: () => {
            showEditModal.value = false;
            editingUser.value = null;
        },
    });
};

const phpCliError = computed(() => phpCliForm.errors.version || page.props.flash?.error || null);

const submitPhpCliVersion = () => {
    if (!editingUser.value) return;
    phpCliForm.patch(`/users/${editingUser.value.id}/php-cli-version`, {
        preserveScroll: true,
        onSuccess: () => {
            if (editingUser.value) {
                editingUser.value.php_cli_version = phpCliForm.version;
            }
        },
    });
};

const page = usePage();
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

const updatingCliUserId = shallowRef(null);
const updateUserPhpCli = (user, version) => {
    if (!version || version === user.php_cli_version) return;
    updatingCliUserId.value = user.id;
    router.patch(`/users/${user.id}/php-cli-version`, { version }, {
        preserveScroll: true,
        onFinish: () => {
            updatingCliUserId.value = null;
        },
    });
};

const tableHeaders = [
    { label: 'User Identity' },
    { label: 'Quota Plan' },
    { label: 'PHP CLI' },
    { label: 'Active Ingress' },
    { label: 'Relational DBs' },
    { label: 'Storage Footprint' },
    { label: 'Account State' },
    { label: 'Operations', class: 'text-right' },
];
</script>

<template>
    <Head :title="__('Identity & Users - LiteControl')" />

    <div class="space-y-6">
        <UiPageHeader
            :title="__('Users & Tenant Accounts')"
            subtitle="Access Security // AUTH_IDENTITY_REGISTRY"
        >
            <template #actions>
                <Link href="/users/create">
                    <UiButton variant="primary">
                        <UserPlus class="w-4 h-4 mr-1.5 inline" />
                        Create User
                    </UiButton>
                </Link>
            </template>
        </UiPageHeader>

        <!-- Filter / Search Bar -->
        <div class="flex items-center gap-3 bg-theme-bg-card border border-theme-border rounded-theme-lg p-2.5 shadow-xs">
            <div class="relative flex-1">
                <Search class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-theme-text-muted" />
                <input
                    v-model="search"
                    @keyup.enter="handleSearch"
                    type="text"
                    :placeholder="__('FILTER_USERS: USERNAME, EMAIL, IDENTITY_SPEC...')"
                    class="w-full pl-9 pr-4 py-1.5 bg-theme-bg-surface border border-theme-border rounded-theme-md text-xs text-theme-text-primary placeholder:text-theme-text-muted focus:outline-none focus:border-theme-accent transition-colors"
                />
            </div>
            <UiButton
                @click="handleSearch"
                variant="secondary"
                size="sm"
            >
                Apply Filter
            </UiButton>
        </div>

        <!-- Users Grid Matrix via Theme-Aware DataTable -->
        <UiDataTable
            :headers="tableHeaders"
            :items-count="users.data?.length || 0"
            empty-message="NO USERS MATCHING SPECIFICATION"
        >
            <tr
                v-for="user in users.data"
                :key="user.id"
                class="hover:bg-theme-bg-surface/50 transition-colors group"
            >
                <td class="py-3.5 px-4">
                    <div class="flex items-center gap-2.5">
                        <span class="w-1.5 h-1.5 rounded-theme-badge bg-theme-accent"></span>
                        <div>
                            <div class="font-bold text-theme-text-primary flex items-center gap-1.5">
                                <span>{{ user.username }}</span>
                            </div>
                            <div class="text-[11px] text-theme-text-muted">{{ user.email }}</div>
                        </div>
                    </div>
                </td>
                <td class="py-3.5 px-4">
                    <UiBadge variant="neutral">
                        <PackageIcon class="w-3.5 h-3.5 text-theme-accent" />
                        <span>{{ user.package?.name || 'DEFAULT' }}</span>
                    </UiBadge>
                </td>
                <td class="py-3.5 px-4">
                    <select
                        :value="user.php_cli_version || '8.3'"
                        :disabled="updatingCliUserId === user.id"
                        :aria-label="`PHP CLI version for ${user.username}`"
                        @change="updateUserPhpCli(user, ($event.target as HTMLSelectElement).value)"
                        class="px-2 py-1 bg-theme-bg-surface border border-theme-border rounded-theme-md text-xs font-mono text-theme-text-primary focus:outline-none focus:border-theme-accent disabled:opacity-50"
                    >
                        <option
                            v-for="ver in installedPhpVersions"
                            :key="ver"
                            :value="ver"
                        >
                            PHP {{ ver }}
                        </option>
                    </select>
                </td>
                <td class="py-3.5 px-4">
                    <span class="inline-flex items-center gap-1.5 text-theme-text-primary">
                        <Globe class="w-3.5 h-3.5 text-theme-accent" />
                        {{ user.domains_count || 0 }}
                    </span>
                </td>
                <td class="py-3.5 px-4">
                    <span class="inline-flex items-center gap-1.5 text-theme-text-primary">
                        <Database class="w-3.5 h-3.5 text-theme-success" />
                        {{ user.databases_count || 0 }}
                    </span>
                </td>
                <td class="py-3.5 px-4">
                    <div class="flex items-center gap-1.5 text-theme-text-primary">
                        <HardDrive class="w-3.5 h-3.5 text-theme-success" />
                        <span>{{ diskUsageMap[user.username]?.disk_usage_human || '—' }}</span>
                    </div>
                    <div class="text-[10px] text-theme-text-muted">
                        CAP: {{ user.package?.disk_quota_mb ? `${user.package.disk_quota_mb} MB` : 'UNLIMITED' }}
                    </div>
                </td>
                <td class="py-3.5 px-4">
                    <UiBadge
                        :variant="user.status === 'active' ? 'success' : 'danger'"
                        class="uppercase"
                    >
                        {{ user.status }}
                    </UiBadge>
                </td>
                <td class="py-3.5 px-4 text-right">
                    <div class="flex items-center justify-end gap-1.5">
                        <!-- Copy SSH Command -->
                        <button
                            @click="copySshCommand(user.username)"
                            :title="copiedUser === user.username ? 'Copied SSH command!' : `Copy SSH command (ssh ${user.username}@${$page.props.panel?.server_ip || 'ip'})`"
                            class="text-theme-text-muted hover:text-theme-accent transition-colors p-1"
                        >
                            <Check v-if="copiedUser === user.username" class="w-4 h-4 text-theme-success" />
                            <Terminal v-else class="w-4 h-4" />
                        </button>

                        <!-- Impersonate Button -->
                        <button
                            @click="impersonateUser(user.id, user.username)"
                            :title="`Impersonate as &quot;${user.username}&quot;`"
                            class="text-theme-text-muted hover:text-theme-accent transition-colors p-1"
                        >
                            <LogIn class="w-4 h-4" />
                        </button>

                        <!-- Edit User -->
                        <button
                            @click="openEditModal(user)"
                            :title="__('Edit User')"
                            class="text-theme-text-muted hover:text-theme-success transition-colors p-1"
                        >
                            <Pencil class="w-4 h-4" />
                        </button>

                        <!-- Suspend / Unsuspend -->
                        <button
                            v-if="user.status === 'active'"
                            @click="suspendUser(user.id, user.username)"
                            :title="__('Suspend Context')"
                            class="text-theme-text-muted hover:text-theme-warning transition-colors p-1"
                        >
                            <Lock class="w-4 h-4" />
                        </button>
                        <button
                            v-else
                            @click="unsuspendUser(user.id, user.username)"
                            :title="__('Restore Context')"
                            class="text-theme-text-muted hover:text-theme-success transition-colors p-1"
                        >
                            <Unlock class="w-4 h-4" />
                        </button>

                        <!-- Delete -->
                        <button
                            @click="deleteUser(user.id, user.username)"
                            :title="__('Terminate User')"
                            class="text-theme-text-muted hover:text-theme-danger transition-colors p-1"
                        >
                            <Trash2 class="w-4 h-4" />
                        </button>
                    </div>
                </td>
            </tr>
        </UiDataTable>

        <!-- Edit User Modal -->
        <UiModal
            :show="showEditModal"
            :title="`Edit User: ${editingUser?.username}`"
            max-width="md"
            @close="showEditModal = false"
        >
            <form @submit.prevent="submitEdit" class="space-y-4">
                <!-- Full Name -->
                <div>
                    <UiInput
                        id="name"
                        v-model="editForm.name"
                        type="text"
                        :label="__('Full Name')"
                        :error="editForm.errors.name"
                        required
                    />
                </div>

                <!-- Email -->
                <div>
                    <UiInput
                        id="email"
                        v-model="editForm.email"
                        type="email"
                        :label="__('Email Address')"
                        :error="editForm.errors.email"
                        required
                    />
                </div>

                <!-- Quota Plan -->
                <div>
                    <UiSelect
                        id="package_id"
                        v-model="editForm.package_id"
                        :label="__('Quota Plan')"
                        :options="packageOptions"
                        :error="editForm.errors.package_id"
                        required
                    />
                </div>

                <!-- Password (Optional) -->
                <div>
                    <UiPasswordInput
                        id="password"
                        v-model="editForm.password"
                        :label="__('Password Reset (Leave blank to keep unchanged)')"
                        :error="editForm.errors.password"
                    />
                </div>

                <!-- SSH Public Key -->
                <div>
                    <UiTextarea
                        id="ssh_public_key"
                        v-model="editForm.ssh_public_key"
                        rows="3"
                        :label="__('SSH Public Key')"
                        :placeholder="__('ssh-ed25519 AAAAC3... or leave empty to clear')"
                        :error="editForm.errors.ssh_public_key"
                    >
                        <template #label-right>
                            <span class="text-xs text-theme-text-muted font-normal lowercase">{{ __('Stored in ~/.ssh/authorized_keys') }}</span>
                        </template>
                    </UiTextarea>
                </div>

                <!-- Action Buttons -->
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
                        {{ editForm.processing ? 'Syncing...' : 'Save Changes' }}
                    </UiButton>
                </div>
            </form>

            <div v-if="editingUser" class="mt-6 pt-4 border-t border-theme-border space-y-3">
                <div>
                    <label
                        for="php-cli-version"
                        class="block text-xs font-semibold text-theme-text-secondary uppercase tracking-wider mb-1"
                    >
                        Default PHP CLI Version
                    </label>
                    <div class="flex items-center gap-3">
                        <select
                            id="php-cli-version"
                            v-model="phpCliForm.version"
                            :aria-invalid="Boolean(phpCliError)"
                            :aria-describedby="phpCliError ? 'php-cli-version-error' : 'php-cli-version-help'"
                            class="w-48 px-3 py-2 bg-theme-bg-surface border border-theme-border rounded-theme-md text-sm text-theme-text-primary focus:outline-none focus:border-theme-accent transition-colors"
                        >
                            <option
                                v-for="ver in installedPhpVersions"
                                :key="ver"
                                :value="ver"
                            >
                                PHP {{ ver }}
                            </option>
                        </select>

                        <UiButton
                            type="button"
                            variant="primary"
                            :loading="phpCliForm.processing"
                            :disabled="phpCliForm.processing || phpCliForm.version === (editingUser.php_cli_version ?? '8.3')"
                            @click="submitPhpCliVersion"
                        >
                            {{ phpCliForm.processing ? 'Saving...' : 'Save CLI' }}
                        </UiButton>
                    </div>
                    <p
                        v-if="phpCliError"
                        id="php-cli-version-error"
                        role="alert"
                        class="mt-1.5 text-xs text-theme-danger-text"
                    >
                        {{ phpCliError }}
                    </p>
                </div>

                <div id="php-cli-version-help" class="space-y-1.5 rounded-theme-md bg-theme-bg-surface p-3 text-xs text-theme-text-muted border border-theme-border">
                    <p>
                        <strong>Immediate Effect:</strong>
                        Applies immediately to SSH sessions and Composer (<code>/usr/local/bin/php</code>).
                    </p>
                    <p>
                        <strong>Per-Directory Override:</strong>
                        Place a <code>.php-version</code> file (e.g. <code>8.2</code>) in a project root to override this default.
                    </p>
                    <p>
                        <strong>Shared INI &amp; Workers:</strong>
                        Shares <code>php.ini</code> with the <code>lsphp</code> web handler; restart Supervisor/queue workers to use the new binary.
                    </p>
                </div>
            </div>
        </UiModal>
    </div>
</template>
