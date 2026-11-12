<script setup>
import { shallowRef } from 'vue';
import { Head, useForm, router, usePage } from '@inertiajs/vue3';
import { confirmDialog } from '@/Composables/useDialog';
import UiPageHeader from '@/Components/ui/UiPageHeader.vue';
import UiButton from '@/Components/ui/UiButton.vue';
import UiModal from '@/Components/ui/UiModal.vue';
import UiInput from '@/Components/ui/UiInput.vue';
import UiSelect from '@/Components/ui/UiSelect.vue';
import {
    Database,
    Plus,
    UserPlus,
    Key,
    Shield,
    Trash2,
    ExternalLink,
    HardDrive,
    X,
    User as UserIcon,
    CheckCircle2
} from '@lucide/vue';

const props = defineProps({
    databases: Array,
    dbUsers: Array,
    liveDbMap: Object,
    users: Array,
});

const page = usePage();
const authUser = page.props.auth?.user;
const isAdmin = authUser?.role === 'admin';

const showCreateDbModal = shallowRef(false);
const showCreateUserModal = shallowRef(false);
const showPasswordModal = shallowRef(false);
const showGrantModal = shallowRef(false);
const selectedDbUser = shallowRef(null);

const dbForm = useForm({
    db_name: '',
    user_id: props.users?.[0]?.id || '',
});

const userForm = useForm({
    username: '',
    password: '',
    database_id: '',
    user_id: props.users?.[0]?.id || '',
});

const passForm = useForm({
    password: '',
});

const grantForm = useForm({
    db_username: '',
    database_id: '',
});

const submitDb = () => {
    dbForm.post('/databases', {
        onSuccess: () => {
            showCreateDbModal.value = false;
            dbForm.reset();
        },
    });
};

const submitUser = () => {
    userForm.post('/databases/users', {
        onSuccess: () => {
            showCreateUserModal.value = false;
            userForm.reset();
        },
    });
};

const openPasswordModal = (userItem) => {
    selectedDbUser.value = userItem;
    passForm.reset();
    showPasswordModal.value = true;
};

const submitPassword = () => {
    if (!selectedDbUser.value) return;
    passForm.post(`/databases/users/${selectedDbUser.value.id}/password`, {
        onSuccess: () => {
            showPasswordModal.value = false;
        },
    });
};

const openGrantModal = (userItem) => {
    grantForm.db_username = userItem.db_username;
    grantForm.database_id = props.databases?.[0]?.id || '';
    showGrantModal.value = true;
};

const submitGrant = () => {
    grantForm.post('/databases/grant', {
        onSuccess: () => {
            showGrantModal.value = false;
        },
    });
};

const deleteDb = async (id, name) => {
    if (await confirmDialog({
        title: 'Terminate Database',
        message: `Are you sure you want to terminate database '${name}'? All tables and data will be permanently dropped.`,
        confirmText: 'Terminate',
        variant: 'danger',
    })) {
        router.delete(`/databases/${id}`);
    }
};

const deleteDbUser = async (id, name) => {
    if (await confirmDialog({
        title: 'Delete Database Principal',
        message: `Are you sure you want to delete database principal '${name}'?`,
        confirmText: 'Delete',
        variant: 'danger',
    })) {
        router.delete(`/databases/users/${id}`);
    }
};
</script>

<template>
    <Head title="MySQL Relational Telemetry - LiteControl" />

    <div class="space-y-8">
        <UiPageHeader
            title="Relational Schema Registry"
            subtitle="Storage Engine // MARIADB_RELATIONAL_SUBSYSTEM"
        >
            <template #actions>
                <a
                    href="/phpmyadmin/"
                    target="_blank"
                    class="inline-flex items-center gap-2 px-3 py-1.5 rounded bg-theme-bg-surface hover:bg-theme-border text-theme-text-secondary hover:text-theme-text-primary text-xs transition-colors border border-theme-border"
                >
                    <span>phpMyAdmin GUI</span>
                    <ExternalLink class="w-3.5 h-3.5 text-theme-text-muted" />
                </a>

                <UiButton @click="showCreateDbModal = true" variant="primary">
                    <Plus class="w-4 h-4 mr-1.5 inline" />
                    Provision Database
                </UiButton>
            </template>
        </UiPageHeader>

        <!-- Databases Section -->
        <div class="space-y-3">
            <div class="bg-theme-bg-card border border-theme-border rounded overflow-hidden">
                <div class="px-5 py-3 border-b border-theme-border bg-theme-bg-surface/50 flex items-center justify-between">
                    <span class="text-xs font-semibold uppercase text-theme-text-secondary tracking-wider">
                        Active Relational Instances ({{ databases.length }})
                    </span>
                    <span class="text-xs text-emerald-400">DAEMON: MARIADB_LIVE</span>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs text-theme-text-primary">
                        <thead class="bg-theme-bg-surface/30 text-[10px] font-semibold text-theme-text-muted uppercase tracking-widest border-b border-theme-border">
                            <tr>
                                <th class="px-5 py-3.5">Schema Identifier</th>
                                <th v-if="isAdmin" class="px-5 py-3.5">Assigned Principal</th>
                                <th class="px-5 py-3.5">Authorized Principals</th>
                                <th class="px-5 py-3.5">Allocated Footprint</th>
                                <th class="px-5 py-3.5 text-right">Operations</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-theme-border">
                            <tr v-for="db in databases" :key="db.id" class="hover:bg-theme-bg-surface/50 transition-colors group">
                                <td class="px-5 py-3.5 font-bold text-theme-text-primary flex items-center gap-2">
                                    <span class="w-1.5 h-1.5 rounded-full bg-theme-accent"></span>
                                    <span>{{ db.db_name }}</span>
                                </td>
                                <td v-if="isAdmin" class="px-5 py-3.5 text-theme-text-secondary">
                                    {{ db.user?.username || '—' }}
                                </td>
                                <td class="px-5 py-3.5 text-theme-text-secondary">
                                    <div class="flex flex-wrap gap-1.5">
                                        <span
                                            v-for="u in db.users"
                                            :key="u.id"
                                            class="px-2 py-0.5 rounded bg-theme-bg-surface text-theme-text-secondary border border-theme-border text-[10px]"
                                        >
                                            {{ u.db_username }}
                                        </span>
                                        <span v-if="!db.users || db.users.length === 0" class="text-theme-text-muted">
                                            NONE
                                        </span>
                                    </div>
                                </td>
                                <td class="px-5 py-3.5 text-theme-text-secondary">
                                    <span class="flex items-center gap-1.5">
                                        <HardDrive class="w-3.5 h-3.5 text-emerald-400" />
                                        {{ liveDbMap[db.db_name]?.size_human || '—' }}
                                    </span>
                                </td>
                                <td class="px-5 py-3.5 text-right">
                                    <button
                                        @click="deleteDb(db.id, db.db_name)"
                                        title="Terminate Schema"
                                        class="text-theme-text-muted hover:text-rose-400 transition-colors p-1"
                                    >
                                        <Trash2 class="w-4 h-4" />
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="!databases || databases.length === 0">
                                <td :colspan="isAdmin ? 5 : 4" class="px-5 py-8 text-center text-theme-text-muted text-xs">
                                    NO RELATIONAL SCHEMAS PROVISIONED
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Database Users Section -->
        <div class="space-y-3 pt-2">
            <div class="flex items-center justify-between pb-2">
                <div class="flex items-center gap-2">
                    <span class="text-xs font-semibold uppercase text-theme-text-secondary tracking-wider">DATABASE USER ACCOUNTS</span>
                </div>

                <UiButton
                    @click="showCreateUserModal = true"
                    variant="secondary"
                    size="sm"
                >
                    <UserPlus class="w-3.5 h-3.5 mr-1.5 inline text-theme-accent" />
                    <span>Provision DB User</span>
                </UiButton>
            </div>

            <div class="bg-theme-bg-card border border-theme-border rounded overflow-hidden">
                <div class="px-5 py-3 border-b border-theme-border bg-theme-bg-surface/50 flex items-center justify-between">
                    <span class="text-xs font-semibold uppercase text-theme-text-secondary tracking-wider">
                        Registered DB Principals ({{ dbUsers.length }})
                    </span>
                    <span class="text-xs text-theme-text-muted">ACL_AUTH_LAYER</span>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs text-theme-text-primary">
                        <thead class="bg-theme-bg-surface/30 text-[10px] font-semibold text-theme-text-muted uppercase tracking-widest border-b border-theme-border">
                            <tr>
                                <th class="px-5 py-3.5">Principal Identity</th>
                                <th v-if="isAdmin" class="px-5 py-3.5">Owner Context</th>
                                <th class="px-5 py-3.5">Linked Schema</th>
                                <th class="px-5 py-3.5">Host Bind</th>
                                <th class="px-5 py-3.5 text-right">Operations</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-theme-border">
                            <tr v-for="u in dbUsers" :key="u.id" class="hover:bg-theme-bg-surface/50 transition-colors group">
                                <td class="px-5 py-3.5 font-bold text-theme-text-primary flex items-center gap-2">
                                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span>
                                    {{ u.db_username }}
                                </td>
                                <td v-if="isAdmin" class="px-5 py-3.5 text-theme-text-secondary">
                                    {{ u.user?.username || '—' }}
                                </td>
                                <td class="px-5 py-3.5 text-theme-text-primary">
                                    <span v-if="u.database?.db_name" class="px-2 py-0.5 bg-theme-bg-surface border border-theme-border text-theme-text-secondary rounded text-[10px]">
                                        {{ u.database.db_name }}
                                    </span>
                                    <span v-else class="text-theme-text-muted">NONE</span>
                                </td>
                                <td class="px-5 py-3.5 text-theme-text-muted">
                                    {{ u.host || 'localhost' }}
                                </td>
                                <td class="px-5 py-3.5 text-right">
                                    <div class="flex items-center justify-end gap-1.5">
                                        <button
                                            @click="openGrantModal(u)"
                                            title="Assign Schema Privileges"
                                            class="text-theme-text-muted hover:text-emerald-400 transition-colors p-1"
                                        >
                                            <Shield class="w-4 h-4" />
                                        </button>
                                        <button
                                            @click="openPasswordModal(u)"
                                            title="Rotate Credentials"
                                            class="text-theme-text-muted hover:text-theme-accent transition-colors p-1"
                                        >
                                            <Key class="w-4 h-4" />
                                        </button>
                                        <button
                                            @click="deleteDbUser(u.id, u.db_username)"
                                            title="Terminate Principal"
                                            class="text-theme-text-muted hover:text-rose-400 transition-colors p-1"
                                        >
                                            <Trash2 class="w-4 h-4" />
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="!dbUsers || dbUsers.length === 0">
                                <td :colspan="isAdmin ? 5 : 4" class="px-5 py-8 text-center text-theme-text-muted text-xs">
                                    NO DATABASE USERS REGISTERED
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Create DB Modal -->
        <UiModal :show="showCreateDbModal" title="Provision Relational Database" max-width="md" @close="showCreateDbModal = false">
            <form @submit.prevent="submitDb" class="space-y-4">
                <div v-if="isAdmin">
                    <UiSelect
                        id="db_owner"
                        v-model="dbForm.user_id"
                        label="Database Owner / Principal"
                        :options="users.map(u => ({ value: u.id, label: `${u.username} (${u.name})` }))"
                        required
                    />
                </div>

                <div>
                    <UiInput
                        id="db_name"
                        v-model="dbForm.db_name"
                        type="text"
                        label="Database Name"
                        placeholder="e.g. blog_db"
                        :error="dbForm.errors.db_name"
                        required
                    />
                </div>

                <div class="pt-4 flex items-center justify-end gap-3 border-t border-theme-border">
                    <UiButton type="button" variant="secondary" @click="showCreateDbModal = false">
                        Abort
                    </UiButton>
                    <UiButton type="submit" variant="primary" :disabled="dbForm.processing">
                        {{ dbForm.processing ? 'Provisioning...' : 'Provision Database' }}
                    </UiButton>
                </div>
            </form>
        </UiModal>

        <!-- Create User Modal -->
        <UiModal :show="showCreateUserModal" title="Provision Database User" max-width="md" @close="showCreateUserModal = false">
            <form @submit.prevent="submitUser" class="space-y-4">
                <div v-if="isAdmin">
                    <UiSelect
                        id="user_owner"
                        v-model="userForm.user_id"
                        label="Principal Owner"
                        :options="users.map(u => ({ value: u.id, label: `${u.username} (${u.name})` }))"
                        required
                    />
                </div>

                <div>
                    <UiInput
                        id="db_username"
                        v-model="userForm.username"
                        type="text"
                        label="Database Username"
                        placeholder="e.g. appuser"
                        :error="userForm.errors.username"
                        required
                    />
                </div>

                <div>
                    <UiInput
                        id="db_user_password"
                        v-model="userForm.password"
                        type="password"
                        label="User Password"
                        placeholder="••••••••••••"
                        :error="userForm.errors.password"
                        required
                    />
                </div>

                <div>
                    <UiSelect
                        id="assign_database"
                        v-model="userForm.database_id"
                        label="Assign Primary Database (Optional)"
                        :options="[
                            { value: '', label: '-- None (Assign Later) --' },
                            ...databases.map(d => ({ value: d.id, label: d.db_name }))
                        ]"
                    />
                </div>

                <div class="pt-4 flex items-center justify-end gap-3 border-t border-theme-border">
                    <UiButton type="button" variant="secondary" @click="showCreateUserModal = false">
                        Abort
                    </UiButton>
                    <UiButton type="submit" variant="primary" :disabled="userForm.processing">
                        {{ userForm.processing ? 'Provisioning...' : 'Provision DB User' }}
                    </UiButton>
                </div>
            </form>
        </UiModal>

        <!-- Rotate Password Modal -->
        <UiModal :show="showPasswordModal" title="Rotate Database User Password" max-width="sm" @close="showPasswordModal = false">
            <form @submit.prevent="submitPassword" class="space-y-4">
                <div>
                    <p class="text-xs text-theme-text-secondary mb-3">
                        Updating credentials for <strong class="text-theme-text-primary">{{ selectedDbUser?.db_username }}</strong>
                    </p>
                    <UiInput
                        id="rotate_pass"
                        v-model="passForm.password"
                        type="password"
                        label="New Password"
                        placeholder="••••••••••••"
                        :error="passForm.errors.password"
                        required
                    />
                </div>

                <div class="pt-4 flex items-center justify-end gap-3 border-t border-theme-border">
                    <UiButton type="button" variant="secondary" @click="showPasswordModal = false">
                        Cancel
                    </UiButton>
                    <UiButton type="submit" variant="primary" :disabled="passForm.processing">
                        {{ passForm.processing ? 'Updating...' : 'Update Password' }}
                    </UiButton>
                </div>
            </form>
        </UiModal>

        <!-- Grant Access Modal -->
        <UiModal :show="showGrantModal" title="Grant Database Privileges" max-width="sm" @close="showGrantModal = false">
            <form @submit.prevent="submitGrant" class="space-y-4">
                <div>
                    <p class="text-xs text-theme-text-secondary mb-3">
                        Assign privileges on schema for <strong class="text-theme-text-primary">{{ grantForm.db_username }}</strong>
                    </p>
                    <UiSelect
                        id="grant_db"
                        v-model="grantForm.database_id"
                        label="Target Database Schema"
                        :options="databases.map(d => ({ value: d.id, label: d.db_name }))"
                        required
                    />
                </div>

                <div class="pt-4 flex items-center justify-end gap-3 border-t border-theme-border">
                    <UiButton type="button" variant="secondary" @click="showGrantModal = false">
                        Cancel
                    </UiButton>
                    <UiButton type="submit" variant="primary" :disabled="grantForm.processing">
                        {{ grantForm.processing ? 'Granting...' : 'Grant All Privileges' }}
                    </UiButton>
                </div>
            </form>
        </UiModal>
    </div>
</template>
