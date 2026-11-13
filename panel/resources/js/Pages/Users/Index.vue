<script setup lang="ts">
import { shallowRef, computed } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { confirmDialog } from '@/Composables/useDialog';
import UiPageHeader from '@/Components/ui/UiPageHeader.vue';
import UiButton from '@/Components/ui/UiButton.vue';
import UiModal from '@/Components/ui/UiModal.vue';
import UiInput from '@/Components/ui/UiInput.vue';
import UiSelect from '@/Components/ui/UiSelect.vue';
import UiDataTable from '@/Components/ui/UiDataTable.vue';
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
});

const search = shallowRef(props.filters?.search || '');

const handleSearch = () => {
    router.get('/users', { search: search.value }, { preserveState: true, replace: true });
};

const suspendUser = async (id, username) => {
    if (await confirmDialog({
        title: 'Suspend User',
        message: `Are you sure you want to suspend principal '${username}'? Their virtual hosts will be deactivated.`,
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
        message: `Terminate user principal '${username}'? ALL vhosts, schemas, and storage volumes will be permanently purged!`,
        confirmText: 'Terminate',
        variant: 'danger',
    })) {
        router.delete(`/users/${id}`);
    }
};

const impersonateUser = async (id, username) => {
    if (await confirmDialog({
        title: 'Impersonate User',
        message: `Assume security context as principal '${username}'?`,
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

const tableHeaders = [
    { label: 'Principal Identity' },
    { label: 'Quota Plan' },
    { label: 'Active Ingress' },
    { label: 'Relational DBs' },
    { label: 'Storage Footprint' },
    { label: 'Account State' },
    { label: 'Operations', class: 'text-right' },
];
</script>

<template>
    <Head title="Identity & Principals - LiteControl" />

    <div class="space-y-6">
        <UiPageHeader
            title="Principals & Tenant Accounts"
            subtitle="Access Security // AUTH_IDENTITY_REGISTRY"
        >
            <template #actions>
                <Link href="/users/create">
                    <UiButton variant="primary">
                        <UserPlus class="w-4 h-4 mr-1.5 inline" />
                        Provision Principal
                    </UiButton>
                </Link>
            </template>
        </UiPageHeader>

        <!-- Filter / Search Bar -->
        <div class="flex items-center gap-3 bg-theme-bg-card border border-theme-border rounded p-2.5 shadow-sm">
            <div class="relative flex-1">
                <Search class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-theme-text-muted" />
                <input
                    v-model="search"
                    @keyup.enter="handleSearch"
                    type="text"
                    placeholder="FILTER_PRINCIPALS: USERNAME, EMAIL, IDENTITY_SPEC..."
                    class="w-full pl-9 pr-4 py-1.5 bg-theme-bg-surface border border-theme-border rounded text-xs text-theme-text-primary placeholder:text-theme-text-muted focus:outline-none focus:border-theme-accent transition-colors"
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
            empty-message="NO PRINCIPALS MATCHING SPECIFICATION"
        >
            <tr
                v-for="user in users.data"
                :key="user.id"
                class="hover:bg-theme-bg-surface/50 transition-colors group"
            >
                <td class="py-3.5 px-4">
                    <div class="flex items-center gap-2.5">
                        <span class="w-1.5 h-1.5 rounded-full bg-theme-accent"></span>
                        <div>
                            <div class="font-bold text-theme-text-primary flex items-center gap-1.5">
                                <span>{{ user.username }}</span>
                            </div>
                            <div class="text-[11px] text-theme-text-muted">{{ user.email }}</div>
                        </div>
                    </div>
                </td>
                <td class="py-3.5 px-4">
                    <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded text-xs bg-theme-bg-surface text-theme-text-secondary border border-theme-border">
                        <PackageIcon class="w-3.5 h-3.5 text-theme-accent" />
                        {{ user.package?.name || 'DEFAULT' }}
                    </span>
                </td>
                <td class="py-3.5 px-4">
                    <span class="inline-flex items-center gap-1.5 text-theme-text-primary">
                        <Globe class="w-3.5 h-3.5 text-theme-accent" />
                        {{ user.domains_count || 0 }}
                    </span>
                </td>
                <td class="py-3.5 px-4">
                    <span class="inline-flex items-center gap-1.5 text-theme-text-primary">
                        <Database class="w-3.5 h-3.5 text-emerald-400" />
                        {{ user.databases_count || 0 }}
                    </span>
                </td>
                <td class="py-3.5 px-4">
                    <div class="flex items-center gap-1.5 text-theme-text-primary">
                        <HardDrive class="w-3.5 h-3.5 text-emerald-400" />
                        <span>{{ diskUsageMap[user.username]?.disk_usage_human || '—' }}</span>
                    </div>
                    <div class="text-[10px] text-theme-text-muted">
                        CAP: {{ user.package?.disk_quota_mb ? `${user.package.disk_quota_mb} MB` : 'UNLIMITED' }}
                    </div>
                </td>
                <td class="py-3.5 px-4">
                    <span
                        :class="[
                            'inline-flex items-center px-2 py-0.5 rounded text-[10px] uppercase tracking-wider',
                            user.status === 'active'
                                ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/30'
                                : 'bg-rose-500/10 text-rose-400 border border-rose-500/30'
                        ]"
                    >
                        {{ user.status }}
                    </span>
                </td>
                <td class="py-3.5 px-4 text-right">
                    <div class="flex items-center justify-end gap-1.5">
                        <!-- Copy SSH Command -->
                        <button
                            @click="copySshCommand(user.username)"
                            :title="copiedUser === user.username ? 'Copied SSH command!' : `Copy SSH command (ssh ${user.username}@${$page.props.panel?.server_ip || 'ip'})`"
                            class="text-theme-text-muted hover:text-theme-accent transition-colors p-1"
                        >
                            <Check v-if="copiedUser === user.username" class="w-4 h-4 text-emerald-400" />
                            <Terminal v-else class="w-4 h-4" />
                        </button>

                        <!-- Impersonate Button -->
                        <button
                            @click="impersonateUser(user.id, user.username)"
                            title="Assume Context"
                            class="text-theme-text-muted hover:text-theme-accent transition-colors p-1"
                        >
                            <LogIn class="w-4 h-4" />
                        </button>

                        <!-- Edit Principal -->
                        <button
                            @click="openEditModal(user)"
                            title="Edit Principal"
                            class="text-theme-text-muted hover:text-emerald-400 transition-colors p-1"
                        >
                            <Pencil class="w-4 h-4" />
                        </button>

                        <!-- Suspend / Unsuspend -->
                        <button
                            v-if="user.status === 'active'"
                            @click="suspendUser(user.id, user.username)"
                            title="Suspend Context"
                            class="text-theme-text-muted hover:text-amber-400 transition-colors p-1"
                        >
                            <Lock class="w-4 h-4" />
                        </button>
                        <button
                            v-else
                            @click="unsuspendUser(user.id, user.username)"
                            title="Restore Context"
                            class="text-theme-text-muted hover:text-emerald-400 transition-colors p-1"
                        >
                            <Unlock class="w-4 h-4" />
                        </button>

                        <!-- Delete -->
                        <button
                            @click="deleteUser(user.id, user.username)"
                            title="Terminate Principal"
                            class="text-theme-text-muted hover:text-rose-400 transition-colors p-1"
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
            :title="`Edit Principal: ${editingUser?.username}`"
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
                        label="Full Name"
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
                        label="Email Address"
                        :error="editForm.errors.email"
                        required
                    />
                </div>

                <!-- Quota Plan -->
                <div>
                    <UiSelect
                        id="package_id"
                        v-model="editForm.package_id"
                        label="Quota Plan"
                        :options="packageOptions"
                        :error="editForm.errors.package_id"
                        required
                    />
                </div>

                <!-- Password (Optional) -->
                <div>
                    <UiInput
                        id="password"
                        v-model="editForm.password"
                        type="password"
                        label="Password Reset (Leave blank to keep unchanged)"
                        placeholder="••••••••••••"
                        :error="editForm.errors.password"
                    />
                </div>

                <!-- SSH Public Key -->
                <div>
                    <label class="block text-xs font-semibold text-theme-text-secondary uppercase tracking-wider mb-1 flex items-center justify-between">
                        <span class="flex items-center gap-1.5">
                            <Key class="w-3.5 h-3.5 text-theme-accent" />
                            <span>SSH Public Key</span>
                        </span>
                        <span class="text-theme-text-muted font-normal lowercase">Stored in ~/.ssh/authorized_keys</span>
                    </label>
                    <textarea
                        v-model="editForm.ssh_public_key"
                        rows="3"
                        placeholder="ssh-ed25519 AAAAC3... or leave empty to clear"
                        class="w-full px-3 py-2 bg-theme-bg-surface border border-theme-border rounded text-xs font-mono text-theme-text-primary placeholder:text-theme-text-muted focus:outline-none focus:border-theme-accent transition-colors"
                    ></textarea>
                    <p v-if="editForm.errors.ssh_public_key" class="text-xs text-rose-400 mt-1">{{ editForm.errors.ssh_public_key }}</p>
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
        </UiModal>
    </div>
</template>
