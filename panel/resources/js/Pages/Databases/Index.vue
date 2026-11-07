<script setup>
import { shallowRef } from 'vue';
import { Head, useForm, router, usePage } from '@inertiajs/vue3';
import PanelLayout from '@/Layouts/PanelLayout.vue';
import Modal from '@/Components/Modal.vue';
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
} from 'lucide-vue-next';

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

const deleteDb = (id, name) => {
    if (confirm(`Are you sure you want to terminate database '${name}'? All tables and data will be permanently dropped.`)) {
        router.delete(`/databases/${id}`);
    }
};

const deleteDbUser = (id, name) => {
    if (confirm(`Are you sure you want to delete database principal '${name}'?`)) {
        router.delete(`/databases/users/${id}`);
    }
};
</script>

<template>
    <PanelLayout>
        <Head title="MySQL Relational Telemetry - LiteControl" />

        <div class="space-y-8">
            <!-- Asymmetrical Spec Header -->
            <div class="flex flex-col sm:flex-row sm:items-end justify-between gap-4 pb-4 border-b border-[#1b222d]">
                <div>
                    <div class="flex items-center gap-2 mb-1">
                        <span class="text-[10px] font-mono tracking-widest uppercase text-[#ff6b35]">Storage Engine</span>
                        <span class="text-[#3a4454] font-mono">/</span>
                        <span class="text-[10px] font-mono text-[#6c7d93]">MARIADB_RELATIONAL_SUBSYSTEM</span>
                    </div>
                    <h1 class="text-2xl font-bold tracking-tight text-white font-mono uppercase flex items-center gap-3">
                        <Database class="w-6 h-6 text-[#ff6b35]" />
                        <span>Relational Schema Registry</span>
                    </h1>
                </div>

                <div class="flex items-center gap-3">
                    <a
                        href="/phpmyadmin/"
                        target="_blank"
                        class="inline-flex items-center gap-2 px-3 py-2 rounded-xs bg-[#12161f] hover:bg-[#1a202c] text-[#8ea0b5] hover:text-white text-xs font-mono uppercase tracking-wider transition-colors border border-[#1e2634]"
                    >
                        <span>phpMyAdmin GUI</span>
                        <ExternalLink class="w-3.5 h-3.5 text-[#5e6f85]" />
                    </a>

                    <button
                        @click="showCreateDbModal = true"
                        class="inline-flex items-center gap-2 px-4 py-2 bg-[#ff6b35] hover:bg-[#ff8252] text-black text-xs font-mono font-bold uppercase tracking-wider transition-colors shadow-[0_0_15px_rgba(255,107,53,0.25)] rounded-xs"
                    >
                        <Plus class="w-4 h-4 stroke-[2.5]" />
                        <span>Provision Database</span>
                    </button>
                </div>
            </div>

            <!-- Databases Section -->
            <div class="space-y-3">
                <div class="bg-[#0b0e14] border border-[#1b2330] rounded-xs overflow-hidden">
                    <div class="px-5 py-3 border-b border-[#1b2330] bg-[#0f131a] flex items-center justify-between">
                        <span class="text-[11px] font-mono uppercase text-[#7a8b9f] tracking-wider">
                            Active Relational Instances ({{ databases.length }})
                        </span>
                        <span class="text-[10px] font-mono text-[#00f0a8]">DAEMON: MARIADB_LIVE</span>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs font-mono text-[#cad3e0]">
                            <thead class="bg-[#090c11] text-[10px] font-semibold text-[#5a6b80] uppercase tracking-widest border-b border-[#1b2330]">
                                <tr>
                                    <th class="px-5 py-3.5">Schema Identifier</th>
                                    <th v-if="isAdmin" class="px-5 py-3.5">Assigned Principal</th>
                                    <th class="px-5 py-3.5">Authorized Principals</th>
                                    <th class="px-5 py-3.5">Allocated Footprint</th>
                                    <th class="px-5 py-3.5 text-right">Operations</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[#151b24]">
                                <tr v-for="db in databases" :key="db.id" class="hover:bg-[#12161f] transition-colors group">
                                    <td class="px-5 py-3.5 font-bold text-white flex items-center gap-2">
                                        <span class="w-1.5 h-1.5 rounded-full bg-[#ff6b35]/60 group-hover:bg-[#ff6b35]"></span>
                                        <span>{{ db.db_name }}</span>
                                    </td>
                                    <td v-if="isAdmin" class="px-5 py-3.5 text-[#8ea0b5]">
                                        {{ db.user?.username || '—' }}
                                    </td>
                                    <td class="px-5 py-3.5 text-[#cad3e0]">
                                        <div class="flex flex-wrap gap-1.5">
                                            <span
                                                v-for="u in db.users"
                                                :key="u.id"
                                                class="px-2 py-0.5 rounded-xs bg-[#161c26] text-[#8ea0b5] border border-[#222c3c] text-[10px]"
                                            >
                                                {{ u.db_username }}
                                            </span>
                                            <span v-if="!db.users || db.users.length === 0" class="text-[#5a6b80]">
                                                NONE
                                            </span>
                                        </div>
                                    </td>
                                    <td class="px-5 py-3.5 text-[#8ea0b5]">
                                        <span class="flex items-center gap-1.5">
                                            <HardDrive class="w-3.5 h-3.5 text-[#00f0a8]" />
                                            {{ liveDbMap[db.db_name]?.size_human || '—' }}
                                        </span>
                                    </td>
                                    <td class="px-5 py-3.5 text-right">
                                        <button
                                            @click="deleteDb(db.id, db.db_name)"
                                            title="Terminate Schema"
                                            class="text-[#5e6f85] hover:text-[#ff3366] transition-colors p-1"
                                        >
                                            <Trash2 class="w-4 h-4" />
                                        </button>
                                    </td>
                                </tr>
                                <tr v-if="!databases || databases.length === 0">
                                    <td :colspan="isAdmin ? 5 : 4" class="px-5 py-8 text-center text-[#5a6b80] text-xs font-mono">
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
                        <span class="text-[10px] font-mono tracking-widest uppercase text-[#ff6b35]">Access Matrix</span>
                        <span class="text-[#3a4454] font-mono">/</span>
                        <span class="text-[10px] font-mono text-[#6c7d93]">DATABASE_USER_ACCOUNTS</span>
                    </div>

                    <button
                        @click="showCreateUserModal = true"
                        class="inline-flex items-center gap-2 px-3 py-1.5 rounded-xs bg-[#161c26] hover:bg-[#202837] text-[#8ea0b5] hover:text-white text-xs font-mono uppercase tracking-wider transition-colors border border-[#1e2634]"
                    >
                        <UserPlus class="w-3.5 h-3.5 text-[#ff6b35]" />
                        <span>Provision DB User</span>
                    </button>
                </div>

                <div class="bg-[#0b0e14] border border-[#1b2330] rounded-xs overflow-hidden">
                    <div class="px-5 py-3 border-b border-[#1b2330] bg-[#0f131a] flex items-center justify-between">
                        <span class="text-[11px] font-mono uppercase text-[#7a8b9f] tracking-wider">
                            Registered DB Principals ({{ dbUsers.length }})
                        </span>
                        <span class="text-[10px] font-mono text-[#6c7d93]">ACL_AUTH_LAYER</span>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs font-mono text-[#cad3e0]">
                            <thead class="bg-[#090c11] text-[10px] font-semibold text-[#5a6b80] uppercase tracking-widest border-b border-[#1b2330]">
                                <tr>
                                    <th class="px-5 py-3.5">Principal Identity</th>
                                    <th v-if="isAdmin" class="px-5 py-3.5">Owner Context</th>
                                    <th class="px-5 py-3.5">Linked Schema</th>
                                    <th class="px-5 py-3.5">Host Bind</th>
                                    <th class="px-5 py-3.5 text-right">Operations</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[#151b24]">
                                <tr v-for="u in dbUsers" :key="u.id" class="hover:bg-[#12161f] transition-colors group">
                                    <td class="px-5 py-3.5 font-bold text-white flex items-center gap-2">
                                        <span class="w-1.5 h-1.5 rounded-full bg-[#00f0a8]/60 group-hover:bg-[#00f0a8]"></span>
                                        {{ u.db_username }}
                                    </td>
                                    <td v-if="isAdmin" class="px-5 py-3.5 text-[#8ea0b5]">
                                        {{ u.user?.username || '—' }}
                                    </td>
                                    <td class="px-5 py-3.5 text-[#cad3e0]">
                                        <span v-if="u.database?.db_name" class="px-2 py-0.5 bg-[#171d27] border border-[#252f3f] text-[#8ea0b5] rounded-xs text-[10px]">
                                            {{ u.database.db_name }}
                                        </span>
                                        <span v-else class="text-[#5a6b80]">NONE</span>
                                    </td>
                                    <td class="px-5 py-3.5 text-[#5e6f85]">
                                        {{ u.host || 'localhost' }}
                                    </td>
                                    <td class="px-5 py-3.5 text-right">
                                        <div class="flex items-center justify-end gap-1.5">
                                            <button
                                                @click="openGrantModal(u)"
                                                title="Assign Schema Privileges"
                                                class="text-[#5e6f85] hover:text-[#00f0a8] transition-colors p-1"
                                            >
                                                <Shield class="w-4 h-4" />
                                            </button>
                                            <button
                                                @click="openPasswordModal(u)"
                                                title="Rotate Credentials"
                                                class="text-[#5e6f85] hover:text-[#ff6b35] transition-colors p-1"
                                            >
                                                <Key class="w-4 h-4" />
                                            </button>
                                            <button
                                                @click="deleteDbUser(u.id, u.db_username)"
                                                title="Terminate Principal"
                                                class="text-[#5e6f85] hover:text-[#ff3366] transition-colors p-1"
                                            >
                                                <Trash2 class="w-4 h-4" />
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <tr v-if="!dbUsers || dbUsers.length === 0">
                                    <td :colspan="isAdmin ? 5 : 4" class="px-5 py-8 text-center text-[#5a6b80] text-xs font-mono">
                                        NO DATABASE PRINCIPALS DEFINED
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Create DB Modal -->
            <Modal :show="showCreateDbModal" max-width="md" @close="showCreateDbModal = false">
                <div class="p-6 space-y-5">
                    <div class="flex items-center justify-between border-b border-[#1e2634] pb-3">
                        <div class="flex items-center gap-2">
                            <span class="w-2 h-2 bg-[#ff6b35]"></span>
                            <h3 class="text-sm font-mono font-bold uppercase tracking-wider text-white">Provision Schema</h3>
                        </div>
                        <button @click="showCreateDbModal = false" class="text-[#64748b] hover:text-white transition-colors">
                            <X class="w-4 h-4" />
                        </button>
                    </div>

                    <form @submit.prevent="submitDb" class="space-y-4 font-mono text-xs">
                        <div v-if="isAdmin">
                            <label class="block text-[10px] uppercase tracking-wider text-[#7a8b9f] mb-1.5">Target Principal</label>
                            <select
                                v-model="dbForm.user_id"
                                required
                                class="w-full px-3 py-2 bg-[#080b0f] border border-[#1e2634] rounded-xs text-[#cad3e0] text-xs focus:outline-none focus:border-[#ff6b35]"
                            >
                                <option v-for="u in users" :key="u.id" :value="u.id">
                                    {{ u.username }}
                                </option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-[10px] uppercase tracking-wider text-[#7a8b9f] mb-1.5">Schema Namespace Suffix</label>
                            <input
                                v-model="dbForm.db_name"
                                type="text"
                                placeholder="app_production"
                                required
                                class="w-full px-3 py-2 bg-[#080b0f] border border-[#1e2634] rounded-xs text-[#cad3e0] text-xs focus:outline-none focus:border-[#ff6b35]"
                            />
                            <p class="text-[10px] text-[#5a6b80] mt-1.5">Auto-prefixed with principal tag (e.g. {{ authUser.username }}_name).</p>
                            <p v-if="dbForm.errors.db_name" class="text-[10px] text-[#ff3366] mt-1">{{ dbForm.errors.db_name }}</p>
                        </div>

                        <div class="pt-4 flex items-center justify-end gap-3 border-t border-[#1e2634]">
                            <button
                                type="button"
                                @click="showCreateDbModal = false"
                                class="px-4 py-2 rounded-xs bg-[#161c26] hover:bg-[#202837] text-xs font-mono uppercase tracking-wider text-[#8ea0b5] transition-colors"
                            >
                                Abort
                            </button>
                            <button
                                type="submit"
                                :disabled="dbForm.processing"
                                class="px-5 py-2 rounded-xs bg-[#ff6b35] hover:bg-[#ff8252] disabled:opacity-50 text-xs font-mono font-bold uppercase tracking-wider text-black transition-colors shadow-[0_0_15px_rgba(255,107,53,0.25)]"
                            >
                                {{ dbForm.processing ? 'Provisioning...' : 'Provision Schema' }}
                            </button>
                        </div>
                    </form>
                </div>
            </Modal>

            <!-- Create DB User Modal -->
            <Modal :show="showCreateUserModal" max-width="md" @close="showCreateUserModal = false">
                <div class="p-6 space-y-5">
                    <div class="flex items-center justify-between border-b border-[#1e2634] pb-3">
                        <div class="flex items-center gap-2">
                            <span class="w-2 h-2 bg-[#00f0a8]"></span>
                            <h3 class="text-sm font-mono font-bold uppercase tracking-wider text-white">Create Database Principal</h3>
                        </div>
                        <button @click="showCreateUserModal = false" class="text-[#64748b] hover:text-white transition-colors">
                            <X class="w-4 h-4" />
                        </button>
                    </div>

                    <form @submit.prevent="submitUser" class="space-y-4 font-mono text-xs">
                        <div v-if="isAdmin">
                            <label class="block text-[10px] uppercase tracking-wider text-[#7a8b9f] mb-1.5">Target Principal</label>
                            <select
                                v-model="userForm.user_id"
                                required
                                class="w-full px-3 py-2 bg-[#080b0f] border border-[#1e2634] rounded-xs text-[#cad3e0] text-xs focus:outline-none focus:border-[#00f0a8]"
                            >
                                <option v-for="u in users" :key="u.id" :value="u.id">
                                    {{ u.username }}
                                </option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-[10px] uppercase tracking-wider text-[#7a8b9f] mb-1.5">Principal Identity</label>
                            <input
                                v-model="userForm.username"
                                type="text"
                                placeholder="service_worker"
                                required
                                class="w-full px-3 py-2 bg-[#080b0f] border border-[#1e2634] rounded-xs text-[#cad3e0] text-xs focus:outline-none focus:border-[#00f0a8]"
                            />
                            <p class="text-[10px] text-[#5a6b80] mt-1.5">Auto-prefixed with account username.</p>
                            <p v-if="userForm.errors.username" class="text-[10px] text-[#ff3366] mt-1">{{ userForm.errors.username }}</p>
                        </div>

                        <div>
                            <label class="block text-[10px] uppercase tracking-wider text-[#7a8b9f] mb-1.5">Passphrase Hash Key</label>
                            <input
                                v-model="userForm.password"
                                type="password"
                                placeholder="••••••••••••"
                                required
                                class="w-full px-3 py-2 bg-[#080b0f] border border-[#1e2634] rounded-xs text-[#cad3e0] text-xs focus:outline-none focus:border-[#00f0a8]"
                            />
                            <p v-if="userForm.errors.password" class="text-[10px] text-[#ff3366] mt-1">{{ userForm.errors.password }}</p>
                        </div>

                        <div>
                            <label class="block text-[10px] uppercase tracking-wider text-[#7a8b9f] mb-1.5">Bind Target Schema (Optional)</label>
                            <select
                                v-model="userForm.database_id"
                                class="w-full px-3 py-2 bg-[#080b0f] border border-[#1e2634] rounded-xs text-[#cad3e0] text-xs focus:outline-none focus:border-[#00f0a8]"
                            >
                                <option value="">None</option>
                                <option v-for="d in databases" :key="d.id" :value="d.id">
                                    {{ d.db_name }}
                                </option>
                            </select>
                        </div>

                        <div class="pt-4 flex items-center justify-end gap-3 border-t border-[#1e2634]">
                            <button
                                type="button"
                                @click="showCreateUserModal = false"
                                class="px-4 py-2 rounded-xs bg-[#161c26] hover:bg-[#202837] text-xs font-mono uppercase tracking-wider text-[#8ea0b5] transition-colors"
                            >
                                Abort
                            </button>
                            <button
                                type="submit"
                                :disabled="userForm.processing"
                                class="px-5 py-2 rounded-xs bg-[#00f0a8] hover:bg-[#34f3b7] disabled:opacity-50 text-xs font-mono font-bold uppercase tracking-wider text-black transition-colors shadow-[0_0_15px_rgba(0,240,168,0.25)]"
                            >
                                {{ userForm.processing ? 'Deploying...' : 'Provision User' }}
                            </button>
                        </div>
                    </form>
                </div>
            </Modal>

            <!-- Change Password Modal -->
            <Modal :show="showPasswordModal" max-width="sm" @close="showPasswordModal = false">
                <div class="p-6 space-y-5">
                    <div class="flex items-center justify-between border-b border-[#1e2634] pb-3">
                        <div class="flex items-center gap-2">
                            <span class="w-2 h-2 bg-[#ff6b35]"></span>
                            <h3 class="text-sm font-mono font-bold uppercase tracking-wider text-white">Rotate Passphrase</h3>
                        </div>
                        <button @click="showPasswordModal = false" class="text-[#64748b] hover:text-white transition-colors">
                            <X class="w-4 h-4" />
                        </button>
                    </div>

                    <form @submit.prevent="submitPassword" class="space-y-4 font-mono text-xs">
                        <div>
                            <label class="block text-[10px] uppercase tracking-wider text-[#7a8b9f] mb-1.5">
                                New Secret for {{ selectedDbUser?.db_username }}
                            </label>
                            <input
                                v-model="passForm.password"
                                type="password"
                                required
                                class="w-full px-3 py-2 bg-[#080b0f] border border-[#1e2634] rounded-xs text-[#cad3e0] text-xs focus:outline-none focus:border-[#ff6b35]"
                            />
                        </div>

                        <div class="pt-4 flex items-center justify-end gap-3 border-t border-[#1e2634]">
                            <button
                                type="button"
                                @click="showPasswordModal = false"
                                class="px-4 py-2 rounded-xs bg-[#161c26] hover:bg-[#202837] text-xs font-mono uppercase tracking-wider text-[#8ea0b5] transition-colors"
                            >
                                Abort
                            </button>
                            <button
                                type="submit"
                                :disabled="passForm.processing"
                                class="px-5 py-2 rounded-xs bg-[#ff6b35] hover:bg-[#ff8252] disabled:opacity-50 text-xs font-mono font-bold uppercase tracking-wider text-black transition-colors shadow-[0_0_15px_rgba(255,107,53,0.25)]"
                            >
                                Update Secret
                            </button>
                        </div>
                    </form>
                </div>
            </Modal>

            <!-- Grant Privileges Modal -->
            <Modal :show="showGrantModal" max-width="sm" @close="showGrantModal = false">
                <div class="p-6 space-y-5">
                    <div class="flex items-center justify-between border-b border-[#1e2634] pb-3">
                        <div class="flex items-center gap-2">
                            <span class="w-2 h-2 bg-[#00f0a8]"></span>
                            <h3 class="text-sm font-mono font-bold uppercase tracking-wider text-white">Grant ACL Permissions</h3>
                        </div>
                        <button @click="showGrantModal = false" class="text-[#64748b] hover:text-white transition-colors">
                            <X class="w-4 h-4" />
                        </button>
                    </div>

                    <form @submit.prevent="submitGrant" class="space-y-4 font-mono text-xs">
                        <div>
                            <label class="block text-[10px] uppercase tracking-wider text-[#7a8b9f] mb-1.5">Principal Identity</label>
                            <input
                                :value="grantForm.db_username"
                                disabled
                                class="w-full px-3 py-2 bg-[#080b0f] border border-[#1e2634] rounded-xs text-[#6c7d93] opacity-80"
                            />
                        </div>

                        <div>
                            <label class="block text-[10px] uppercase tracking-wider text-[#7a8b9f] mb-1.5">Target Schema</label>
                            <select
                                v-model="grantForm.database_id"
                                required
                                class="w-full px-3 py-2 bg-[#080b0f] border border-[#1e2634] rounded-xs text-[#cad3e0] text-xs focus:outline-none focus:border-[#00f0a8]"
                            >
                                <option v-for="d in databases" :key="d.id" :value="d.id">
                                    {{ d.db_name }}
                                </option>
                            </select>
                        </div>

                        <div class="pt-4 flex items-center justify-end gap-3 border-t border-[#1e2634]">
                            <button
                                type="button"
                                @click="showGrantModal = false"
                                class="px-4 py-2 rounded-xs bg-[#161c26] hover:bg-[#202837] text-xs font-mono uppercase tracking-wider text-[#8ea0b5] transition-colors"
                            >
                                Abort
                            </button>
                            <button
                                type="submit"
                                :disabled="grantForm.processing"
                                class="px-5 py-2 rounded-xs bg-[#00f0a8] hover:bg-[#34f3b7] disabled:opacity-50 text-xs font-mono font-bold uppercase tracking-wider text-black transition-colors shadow-[0_0_15px_rgba(0,240,168,0.25)]"
                            >
                                Authorize Privileges
                            </button>
                        </div>
                    </form>
                </div>
            </Modal>
        </div>
    </PanelLayout>
</template>
