<script setup>
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import PanelLayout from '@/Layouts/PanelLayout.vue';
import {
    Users,
    UserPlus,
    Search,
    Shield,
    Globe,
    Database,
    HardDrive,
    Lock,
    Unlock,
    Trash2,
    LogIn,
    Package as PackageIcon,
    AlertCircle
} from 'lucide-vue-next';

const props = defineProps({
    users: Object,
    diskUsageMap: Object,
    filters: Object,
    packages: Array,
});

const search = ref(props.filters?.search || '');

const handleSearch = () => {
    router.get('/users', { search: search.value }, { preserveState: true, replace: true });
};

const suspendUser = (id, username) => {
    if (confirm(`Are you sure you want to suspend user '${username}'? Their sites will be blocked.`)) {
        router.post(`/users/${id}/suspend`);
    }
};

const unsuspendUser = (id, username) => {
    router.post(`/users/${id}/unsuspend`);
};

const deleteUser = (id, username) => {
    if (confirm(`Are you sure you want to delete user '${username}'? ALL sites, databases, and files will be permanently deleted!`)) {
        router.delete(`/users/${id}`);
    }
};

const impersonateUser = (id, username) => {
    if (confirm(`Switch into customer session as '${username}'?`)) {
        router.post(`/users/${id}/impersonate`);
    }
};
</script>

<template>
    <PanelLayout>
        <Head title="Users Management" />

        <div class="space-y-6">
            <!-- Header -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 class="text-xl sm:text-2xl font-bold text-white flex items-center gap-2.5">
                        <Users class="w-6 h-6 text-indigo-400" />
                        <span>Users Management</span>
                    </h1>
                    <p class="text-xs sm:text-sm text-slate-400 mt-1">
                        Manage Linux hosting accounts, assign packages, and control customer access.
                    </p>
                </div>

                <Link
                    href="/users/create"
                    class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium transition-colors shadow-sm shadow-indigo-600/30"
                >
                    <UserPlus class="w-4 h-4" />
                    <span>Add User</span>
                </Link>
            </div>

            <!-- Filter / Search Bar -->
            <div class="flex items-center gap-3 bg-slate-900 border border-slate-800 rounded-xl p-3">
                <div class="relative flex-1">
                    <Search class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
                    <input
                        v-model="search"
                        @keyup.enter="handleSearch"
                        type="text"
                        placeholder="Search by username, name, or email..."
                        class="w-full pl-9 pr-4 py-1.5 bg-slate-950/70 border border-slate-800 rounded-lg text-sm text-slate-200 placeholder-slate-500 focus:outline-none focus:border-indigo-500 transition-colors"
                    />
                </div>
                <button
                    @click="handleSearch"
                    class="px-4 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-xs font-semibold text-slate-200 transition-colors"
                >
                    Filter
                </button>
            </div>

            <!-- Users Table -->
            <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden shadow-sm">
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm text-slate-300">
                        <thead class="bg-slate-950/50 text-xs font-semibold text-slate-400 uppercase tracking-wider border-b border-slate-800">
                            <tr>
                                <th class="px-5 py-3">User</th>
                                <th class="px-5 py-3">Package</th>
                                <th class="px-5 py-3">Domains</th>
                                <th class="px-5 py-3">Databases</th>
                                <th class="px-5 py-3">Disk Usage</th>
                                <th class="px-5 py-3">Status</th>
                                <th class="px-5 py-3 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800/60">
                            <tr v-for="user in users.data" :key="user.id" class="hover:bg-slate-800/40 transition-colors">
                                <td class="px-5 py-3.5">
                                    <div class="flex items-center gap-3">
                                        <div class="w-9 h-9 rounded-lg bg-indigo-500/10 border border-indigo-500/20 text-indigo-400 flex items-center justify-center font-bold text-xs uppercase">
                                            {{ user.username.substring(0, 2) }}
                                        </div>
                                        <div>
                                            <div class="font-semibold text-white flex items-center gap-2">
                                                <span>{{ user.username }}</span>
                                            </div>
                                            <div class="text-xs text-slate-400">{{ user.email }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-5 py-3.5">
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md text-xs font-medium bg-slate-800 text-slate-300 border border-slate-700/60">
                                        <PackageIcon class="w-3.5 h-3.5 text-indigo-400" />
                                        {{ user.package?.name || 'Default' }}
                                    </span>
                                </td>
                                <td class="px-5 py-3.5 text-slate-300">
                                    <span class="inline-flex items-center gap-1 text-xs">
                                        <Globe class="w-3.5 h-3.5 text-emerald-400" />
                                        {{ user.domains_count || 0 }}
                                    </span>
                                </td>
                                <td class="px-5 py-3.5 text-slate-300">
                                    <span class="inline-flex items-center gap-1 text-xs">
                                        <Database class="w-3.5 h-3.5 text-amber-400" />
                                        {{ user.databases_count || 0 }}
                                    </span>
                                </td>
                                <td class="px-5 py-3.5 text-xs">
                                    <div class="flex items-center gap-1.5 text-slate-300">
                                        <HardDrive class="w-3.5 h-3.5 text-cyan-400" />
                                        <span>{{ diskUsageMap[user.username]?.disk_usage_human || '—' }}</span>
                                    </div>
                                    <div class="text-[11px] text-slate-500">
                                        Quota: {{ user.package?.disk_quota_mb ? `${user.package.disk_quota_mb} MB` : 'Unlimited' }}
                                    </div>
                                </td>
                                <td class="px-5 py-3.5">
                                    <span
                                        :class="[
                                            'inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold',
                                            user.status === 'active'
                                                ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20'
                                                : 'bg-rose-500/10 text-rose-400 border border-rose-500/20'
                                        ]"
                                    >
                                        {{ user.status }}
                                    </span>
                                </td>
                                <td class="px-5 py-3.5 text-right">
                                    <div class="flex items-center justify-end gap-2">
                                        <!-- Impersonate Button -->
                                        <button
                                            @click="impersonateUser(user.id, user.username)"
                                            title="Impersonate User"
                                            class="p-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-indigo-400 hover:text-indigo-300 transition-colors"
                                        >
                                            <LogIn class="w-4 h-4" />
                                        </button>

                                        <!-- Suspend / Unsuspend -->
                                        <button
                                            v-if="user.status === 'active'"
                                            @click="suspendUser(user.id, user.username)"
                                            title="Suspend User"
                                            class="p-1.5 rounded-lg bg-slate-800 hover:bg-amber-500/20 text-amber-400 transition-colors"
                                        >
                                            <Lock class="w-4 h-4" />
                                        </button>
                                        <button
                                            v-else
                                            @click="unsuspendUser(user.id, user.username)"
                                            title="Unsuspend User"
                                            class="p-1.5 rounded-lg bg-slate-800 hover:bg-emerald-500/20 text-emerald-400 transition-colors"
                                        >
                                            <Unlock class="w-4 h-4" />
                                        </button>

                                        <!-- Delete -->
                                        <button
                                            @click="deleteUser(user.id, user.username)"
                                            title="Delete User"
                                            class="p-1.5 rounded-lg bg-slate-800 hover:bg-rose-500/20 text-rose-400 transition-colors"
                                        >
                                            <Trash2 class="w-4 h-4" />
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="!users.data || users.data.length === 0">
                                <td colspan="7" class="px-5 py-8 text-center text-slate-500 text-sm">
                                    No users found matching query.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </PanelLayout>
</template>

