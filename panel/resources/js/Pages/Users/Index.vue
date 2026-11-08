<script setup>
import { shallowRef, computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import UiPageHeader from '@/Components/ui/UiPageHeader.vue';
import UiButton from '@/Components/ui/UiButton.vue';
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
    Package as PackageIcon
} from 'lucide-vue-next';

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

const suspendUser = (id, username) => {
    if (confirm(`Are you sure you want to suspend principal '${username}'? Their virtual hosts will be deactivated.`)) {
        router.post(`/users/${id}/suspend`);
    }
};

const unsuspendUser = (id, username) => {
    router.post(`/users/${id}/unsuspend`);
};

const deleteUser = (id, username) => {
    if (confirm(`Terminate user principal '${username}'? ALL vhosts, schemas, and storage volumes will be permanently purged!`)) {
        router.delete(`/users/${id}`);
    }
};

const impersonateUser = (id, username) => {
    if (confirm(`Assume security context as principal '${username}'?`)) {
        router.post(`/users/${id}/impersonate`);
    }
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
                        <!-- Impersonate Button -->
                        <button
                            @click="impersonateUser(user.id, user.username)"
                            title="Assume Context"
                            class="text-theme-text-muted hover:text-theme-accent transition-colors p-1"
                        >
                            <LogIn class="w-4 h-4" />
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
    </div>
</template>
