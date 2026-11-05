<script setup>
import { ref } from 'vue';
import { Head, useForm, router, usePage } from '@inertiajs/vue3';
import PanelLayout from '@/Layouts/PanelLayout.vue';
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

const showCreateDbModal = ref(false);
const showCreateUserModal = ref(false);
const showPasswordModal = ref(false);
const showGrantModal = ref(false);
const selectedDbUser = ref(null);

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
            dbForm.reset('db_name');
        },
    });
};

const submitUser = () => {
    userForm.post('/databases/users', {
        onSuccess: () => {
            showCreateUserModal.value = false;
            userForm.reset('username', 'password');
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
    if (confirm(`Are you sure you want to delete database '${name}'? All tables and data will be permanently dropped.`)) {
        router.delete(`/databases/${id}`);
    }
};

const deleteDbUser = (id, name) => {
    if (confirm(`Are you sure you want to delete database user '${name}'?`)) {
        router.delete(`/databases/users/${id}`);
    }
};
</script>

<template>
    <PanelLayout>
        <Head title="MySQL Databases" />

        <div class="space-y-8">
            <!-- Header -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 class="text-xl sm:text-2xl font-bold text-white flex items-center gap-2.5">
                        <Database class="w-6 h-6 text-amber-400" />
                        <span>MySQL Databases</span>
                    </h1>
                    <p class="text-xs sm:text-sm text-slate-400 mt-1">
                        Manage MariaDB/MySQL databases, database user credentials, and permissions.
                    </p>
                </div>

                <div class="flex items-center gap-3">
                    <a
                        href="/phpmyadmin/"
                        target="_blank"
                        class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-200 text-sm font-semibold transition-colors border border-slate-700"
                    >
                        <span>Launch phpMyAdmin</span>
                        <ExternalLink class="w-3.5 h-3.5 text-slate-400" />
                    </a>

                    <button
                        @click="showCreateDbModal = true"
                        class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-amber-600 hover:bg-amber-500 text-white text-sm font-semibold transition-colors shadow-sm shadow-amber-600/30"
                    >
                        <Plus class="w-4 h-4" />
                        <span>Create Database</span>
                    </button>
                </div>
            </div>

            <!-- Databases Section -->
            <div class="space-y-4">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-slate-300 uppercase tracking-wider flex items-center gap-2">
                        <Database class="w-4 h-4 text-amber-400" />
                        <span>Databases ({{ databases.length }})</span>
                    </h2>
                </div>

                <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden shadow-sm">
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm text-slate-300">
                            <thead class="bg-slate-950/50 text-xs font-semibold text-slate-400 uppercase tracking-wider border-b border-slate-800">
                                <tr>
                                    <th class="px-5 py-3">Database Name</th>
                                    <th v-if="isAdmin" class="px-5 py-3">Owner</th>
                                    <th class="px-5 py-3">Assigned Users</th>
                                    <th class="px-5 py-3">Size</th>
                                    <th class="px-5 py-3 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-800/60">
                                <tr v-for="db in databases" :key="db.id" class="hover:bg-slate-800/40 transition-colors">
                                    <td class="px-5 py-3.5 font-semibold text-white flex items-center gap-2.5">
                                        <div class="w-8 h-8 rounded-lg bg-amber-500/10 border border-amber-500/20 text-amber-400 flex items-center justify-center">
                                            <Database class="w-4 h-4" />
                                        </div>
                                        <span>{{ db.db_name }}</span>
                                    </td>
                                    <td v-if="isAdmin" class="px-5 py-3.5 text-xs text-slate-400">
                                        {{ db.user?.username || '—' }}
                                    </td>
                                    <td class="px-5 py-3.5 text-xs text-slate-300">
                                        <div class="flex flex-wrap gap-1">
                                            <span
                                                v-for="u in db.users"
                                                :key="u.id"
                                                class="px-2 py-0.5 rounded bg-slate-800 text-slate-300 border border-slate-700 font-mono text-[11px]"
                                            >
                                                {{ u.db_username }}
                                            </span>
                                            <span v-if="!db.users || db.users.length === 0" class="text-slate-500">
                                                None
                                            </span>
                                        </div>
                                    </td>
                                    <td class="px-5 py-3.5 text-xs text-slate-400">
                                        <span class="flex items-center gap-1.5">
                                            <HardDrive class="w-3.5 h-3.5 text-cyan-400" />
                                            {{ liveDbMap[db.db_name]?.size_human || '—' }}
                                        </span>
                                    </td>
                                    <td class="px-5 py-3.5 text-right">
                                        <button
                                            @click="deleteDb(db.id, db.db_name)"
                                            title="Delete Database"
                                            class="p-1.5 rounded-lg bg-slate-800 hover:bg-rose-500/20 text-rose-400 transition-colors"
                                        >
                                            <Trash2 class="w-4 h-4" />
                                        </button>
                                    </td>
                                </tr>
                                <tr v-if="!databases || databases.length === 0">
                                    <td :colspan="isAdmin ? 5 : 4" class="px-5 py-8 text-center text-slate-500 text-sm">
                                        No databases created yet.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Database Users Section -->
            <div class="space-y-4 pt-4 border-t border-slate-800">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-slate-300 uppercase tracking-wider flex items-center gap-2">
                        <UserIcon class="w-4 h-4 text-indigo-400" />
                        <span>Database Users ({{ dbUsers.length }})</span>
                    </h2>

                    <button
                        @click="showCreateUserModal = true"
                        class="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-semibold transition-colors border border-slate-700"
                    >
                        <UserPlus class="w-3.5 h-3.5 text-indigo-400" />
                        <span>Add DB User</span>
                    </button>
                </div>

                <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden shadow-sm">
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm text-slate-300">
                            <thead class="bg-slate-950/50 text-xs font-semibold text-slate-400 uppercase tracking-wider border-b border-slate-800">
                                <tr>
                                    <th class="px-5 py-3">DB Username</th>
                                    <th v-if="isAdmin" class="px-5 py-3">Owner</th>
                                    <th class="px-5 py-3">Assigned Database</th>
                                    <th class="px-5 py-3">Host</th>
                                    <th class="px-5 py-3 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-800/60">
                                <tr v-for="u in dbUsers" :key="u.id" class="hover:bg-slate-800/40 transition-colors">
                                    <td class="px-5 py-3.5 font-semibold text-white font-mono text-xs">
                                        {{ u.db_username }}
                                    </td>
                                    <td v-if="isAdmin" class="px-5 py-3.5 text-xs text-slate-400">
                                        {{ u.user?.username || '—' }}
                                    </td>
                                    <td class="px-5 py-3.5 text-xs text-slate-300">
                                        {{ u.database?.db_name || 'None' }}
                                    </td>
                                    <td class="px-5 py-3.5 text-xs font-mono text-slate-400">
                                        {{ u.host || 'localhost' }}
                                    </td>
                                    <td class="px-5 py-3.5 text-right">
                                        <div class="flex items-center justify-end gap-2">
                                            <button
                                                @click="openGrantModal(u)"
                                                title="Grant Privileges"
                                                class="p-1.5 rounded-lg bg-slate-800 hover:bg-indigo-500/20 text-indigo-400 transition-colors"
                                            >
                                                <Shield class="w-3.5 h-3.5" />
                                            </button>
                                            <button
                                                @click="openPasswordModal(u)"
                                                title="Change Password"
                                                class="p-1.5 rounded-lg bg-slate-800 hover:bg-amber-500/20 text-amber-400 transition-colors"
                                            >
                                                <Key class="w-3.5 h-3.5" />
                                            </button>
                                            <button
                                                @click="deleteDbUser(u.id, u.db_username)"
                                                title="Delete User"
                                                class="p-1.5 rounded-lg bg-slate-800 hover:bg-rose-500/20 text-rose-400 transition-colors"
                                            >
                                                <Trash2 class="w-3.5 h-3.5" />
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <tr v-if="!dbUsers || dbUsers.length === 0">
                                    <td :colspan="isAdmin ? 5 : 4" class="px-5 py-8 text-center text-slate-500 text-sm">
                                        No database users created yet.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Create DB Modal -->
            <div v-if="showCreateDbModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm">
                <div class="bg-slate-900 border border-slate-800 rounded-xl max-w-md w-full p-6 shadow-2xl space-y-4">
                    <div class="flex items-center justify-between border-b border-slate-800 pb-3">
                        <h3 class="text-base font-bold text-white">Create Database</h3>
                        <button @click="showCreateDbModal = false" class="text-slate-400 hover:text-white">
                            <X class="w-5 h-5" />
                        </button>
                    </div>

                    <form @submit.prevent="submitDb" class="space-y-4">
                        <div v-if="isAdmin">
                            <label class="block text-xs font-semibold text-slate-300 uppercase mb-1">Assign User</label>
                            <select
                                v-model="dbForm.user_id"
                                required
                                class="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-lg text-sm text-slate-200 focus:outline-none focus:border-amber-500"
                            >
                                <option v-for="u in users" :key="u.id" :value="u.id">
                                    {{ u.username }}
                                </option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-300 uppercase mb-1">Database Name</label>
                            <input
                                v-model="dbForm.db_name"
                                type="text"
                                placeholder="e.g. app or wp"
                                required
                                class="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-lg text-sm text-slate-200 focus:outline-none focus:border-amber-500"
                            />
                            <p class="text-[11px] text-slate-500 mt-1">Note: Will automatically be prefixed with your account username (e.g. {{ authUser.username }}_name).</p>
                            <p v-if="dbForm.errors.db_name" class="text-xs text-rose-400 mt-1">{{ dbForm.errors.db_name }}</p>
                        </div>

                        <div class="pt-4 flex items-center justify-end gap-3 border-t border-slate-800">
                            <button
                                type="button"
                                @click="showCreateDbModal = false"
                                class="px-4 py-2 rounded-lg bg-slate-800 hover:bg-slate-700 text-sm font-medium text-slate-300"
                            >
                                Cancel
                            </button>
                            <button
                                type="submit"
                                :disabled="dbForm.processing"
                                class="px-5 py-2 rounded-lg bg-amber-600 hover:bg-amber-500 disabled:opacity-50 text-sm font-medium text-white shadow-sm"
                            >
                                {{ dbForm.processing ? 'Creating...' : 'Create Database' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Create DB User Modal -->
            <div v-if="showCreateUserModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm">
                <div class="bg-slate-900 border border-slate-800 rounded-xl max-w-md w-full p-6 shadow-2xl space-y-4">
                    <div class="flex items-center justify-between border-b border-slate-800 pb-3">
                        <h3 class="text-base font-bold text-white">Create Database User</h3>
                        <button @click="showCreateUserModal = false" class="text-slate-400 hover:text-white">
                            <X class="w-5 h-5" />
                        </button>
                    </div>

                    <form @submit.prevent="submitUser" class="space-y-4">
                        <div v-if="isAdmin">
                            <label class="block text-xs font-semibold text-slate-300 uppercase mb-1">Assign User</label>
                            <select
                                v-model="userForm.user_id"
                                required
                                class="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-lg text-sm text-slate-200 focus:outline-none focus:border-indigo-500"
                            >
                                <option v-for="u in users" :key="u.id" :value="u.id">
                                    {{ u.username }}
                                </option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-300 uppercase mb-1">DB Username</label>
                            <input
                                v-model="userForm.username"
                                type="text"
                                placeholder="e.g. dbuser"
                                required
                                class="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-lg text-sm text-slate-200 focus:outline-none focus:border-indigo-500"
                            />
                            <p class="text-[11px] text-slate-500 mt-1">Automatically prefixed with username.</p>
                            <p v-if="userForm.errors.username" class="text-xs text-rose-400 mt-1">{{ userForm.errors.username }}</p>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-300 uppercase mb-1">Password</label>
                            <input
                                v-model="userForm.password"
                                type="password"
                                placeholder="••••••••••••"
                                required
                                class="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-lg text-sm text-slate-200 focus:outline-none focus:border-indigo-500"
                            />
                            <p v-if="userForm.errors.password" class="text-xs text-rose-400 mt-1">{{ userForm.errors.password }}</p>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-300 uppercase mb-1">Assign Database (Optional)</label>
                            <select
                                v-model="userForm.database_id"
                                class="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-lg text-sm text-slate-200 focus:outline-none focus:border-indigo-500"
                            >
                                <option value="">None</option>
                                <option v-for="d in databases" :key="d.id" :value="d.id">
                                    {{ d.db_name }}
                                </option>
                            </select>
                        </div>

                        <div class="pt-4 flex items-center justify-end gap-3 border-t border-slate-800">
                            <button
                                type="button"
                                @click="showCreateUserModal = false"
                                class="px-4 py-2 rounded-lg bg-slate-800 hover:bg-slate-700 text-sm font-medium text-slate-300"
                            >
                                Cancel
                            </button>
                            <button
                                type="submit"
                                :disabled="userForm.processing"
                                class="px-5 py-2 rounded-lg bg-indigo-600 hover:bg-indigo-500 disabled:opacity-50 text-sm font-medium text-white shadow-sm"
                            >
                                {{ userForm.processing ? 'Creating...' : 'Create DB User' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Change Password Modal -->
            <div v-if="showPasswordModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm">
                <div class="bg-slate-900 border border-slate-800 rounded-xl max-w-sm w-full p-6 shadow-2xl space-y-4">
                    <div class="flex items-center justify-between border-b border-slate-800 pb-3">
                        <h3 class="text-base font-bold text-white">Change Password</h3>
                        <button @click="showPasswordModal = false" class="text-slate-400 hover:text-white">
                            <X class="w-5 h-5" />
                        </button>
                    </div>

                    <form @submit.prevent="submitPassword" class="space-y-4">
                        <div>
                            <label class="block text-xs font-semibold text-slate-300 uppercase mb-1">
                                New Password for {{ selectedDbUser?.db_username }}
                            </label>
                            <input
                                v-model="passForm.password"
                                type="password"
                                required
                                class="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-lg text-sm text-slate-200 focus:outline-none focus:border-amber-500"
                            />
                        </div>

                        <div class="pt-4 flex items-center justify-end gap-3 border-t border-slate-800">
                            <button
                                type="button"
                                @click="showPasswordModal = false"
                                class="px-4 py-2 rounded-lg bg-slate-800 hover:bg-slate-700 text-sm font-medium text-slate-300"
                            >
                                Cancel
                            </button>
                            <button
                                type="submit"
                                :disabled="passForm.processing"
                                class="px-5 py-2 rounded-lg bg-amber-600 hover:bg-amber-500 disabled:opacity-50 text-sm font-medium text-white shadow-sm"
                            >
                                Update Password
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Grant Privileges Modal -->
            <div v-if="showGrantModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm">
                <div class="bg-slate-900 border border-slate-800 rounded-xl max-w-sm w-full p-6 shadow-2xl space-y-4">
                    <div class="flex items-center justify-between border-b border-slate-800 pb-3">
                        <h3 class="text-base font-bold text-white">Grant Privileges</h3>
                        <button @click="showGrantModal = false" class="text-slate-400 hover:text-white">
                            <X class="w-5 h-5" />
                        </button>
                    </div>

                    <form @submit.prevent="submitGrant" class="space-y-4">
                        <div>
                            <label class="block text-xs font-semibold text-slate-300 uppercase mb-1">User</label>
                            <input
                                :value="grantForm.db_username"
                                disabled
                                class="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-lg text-sm text-slate-400 opacity-70"
                            />
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-300 uppercase mb-1">Target Database</label>
                            <select
                                v-model="grantForm.database_id"
                                required
                                class="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-lg text-sm text-slate-200 focus:outline-none focus:border-indigo-500"
                            >
                                <option v-for="d in databases" :key="d.id" :value="d.id">
                                    {{ d.db_name }}
                                </option>
                            </select>
                        </div>

                        <div class="pt-4 flex items-center justify-end gap-3 border-t border-slate-800">
                            <button
                                type="button"
                                @click="showGrantModal = false"
                                class="px-4 py-2 rounded-lg bg-slate-800 hover:bg-slate-700 text-sm font-medium text-slate-300"
                            >
                                Cancel
                            </button>
                            <button
                                type="submit"
                                :disabled="grantForm.processing"
                                class="px-5 py-2 rounded-lg bg-indigo-600 hover:bg-indigo-500 disabled:opacity-50 text-sm font-medium text-white shadow-sm"
                            >
                                Grant Full Privileges
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </PanelLayout>
</template>

