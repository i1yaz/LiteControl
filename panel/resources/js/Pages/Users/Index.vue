<script setup>
import { shallowRef } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import Modal from '@/Components/Modal.vue';
import {
    Users,
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
    X,
    Key,
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

const showEditModal = shallowRef(false);
const editingUser = shallowRef(null);

const editForm = useForm({
    name: '',
    email: '',
    password: '',
    package_id: '',
    ssh_public_key: '',
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
</script>

<template>
    <Head title="Identity & Principals - LiteControl" />

    <div class="space-y-6">
            <!-- Asymmetrical Spec Header -->
            <div class="flex flex-col sm:flex-row sm:items-end justify-between gap-4 pb-4 border-b border-[#1b222d]">
                <div>
                    <div class="flex items-center gap-2 mb-1">
                        <span class="text-[10px] font-mono tracking-widest uppercase text-[#ff6b35]">Access Security</span>
                        <span class="text-[#3a4454] font-mono">/</span>
                        <span class="text-[10px] font-mono text-[#6c7d93]">AUTH_IDENTITY_REGISTRY</span>
                    </div>
                    <h1 class="text-2xl font-bold tracking-tight text-white font-mono uppercase flex items-center gap-3">
                        <Users class="w-6 h-6 text-[#ff6b35]" />
                        <span>Principals & Tenant Accounts</span>
                    </h1>
                </div>

                <Link
                    href="/users/create"
                    class="inline-flex items-center gap-2 px-4 py-2 bg-[#ff6b35] hover:bg-[#ff8252] text-black text-xs font-mono font-bold uppercase tracking-wider transition-colors shadow-[0_0_15px_rgba(255,107,53,0.25)] rounded-xs"
                >
                    <UserPlus class="w-4 h-4 stroke-[2.5]" />
                    <span>Provision Principal</span>
                </Link>
            </div>

            <!-- Filter / Search Bar -->
            <div class="flex items-center gap-3 bg-[#0b0e14] border border-[#1b2330] rounded-xs p-2.5">
                <div class="relative flex-1">
                    <Search class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-[#5a6b80]" />
                    <input
                        v-model="search"
                        @keyup.enter="handleSearch"
                        type="text"
                        placeholder="FILTER_PRINCIPALS: USERNAME, EMAIL, IDENTITY_SPEC..."
                        class="w-full pl-9 pr-4 py-1.5 bg-[#080b0f] border border-[#1e2634] rounded-xs text-xs font-mono text-[#cad3e0] placeholder-[#4e5c6e] focus:outline-none focus:border-[#ff6b35] transition-colors"
                    />
                </div>
                <button
                    @click="handleSearch"
                    class="px-4 py-1.5 rounded-xs bg-[#161c26] hover:bg-[#202837] text-xs font-mono uppercase tracking-wider text-[#8ea0b5] transition-colors border border-[#1e2634]"
                >
                    Apply Filter
                </button>
            </div>

            <!-- Users Grid Matrix -->
            <div class="bg-[#0b0e14] border border-[#1b2330] rounded-xs overflow-hidden">
                <div class="px-5 py-3 border-b border-[#1b2330] bg-[#0f131a] flex items-center justify-between">
                    <span class="text-[11px] font-mono uppercase text-[#7a8b9f] tracking-wider">
                        Registered Tenants ({{ users.total ?? users.data?.length ?? 0 }})
                    </span>
                    <span class="text-[10px] font-mono text-[#00f0a8]">SECURITY_ACL: ENFORCED</span>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs font-mono text-[#cad3e0]">
                        <thead class="bg-[#090c11] text-[10px] font-semibold text-[#5a6b80] uppercase tracking-widest border-b border-[#1b2330]">
                            <tr>
                                <th class="px-5 py-3.5">Principal Identity</th>
                                <th class="px-5 py-3.5">Quota Plan</th>
                                <th class="px-5 py-3.5">Active Ingress</th>
                                <th class="px-5 py-3.5">Relational DBs</th>
                                <th class="px-5 py-3.5">Storage Footprint</th>
                                <th class="px-5 py-3.5">Account State</th>
                                <th class="px-5 py-3.5 text-right">Operations</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[#151b24]">
                            <tr v-for="user in users.data" :key="user.id" class="hover:bg-[#12161f] transition-colors group">
                                <td class="px-5 py-3.5">
                                    <div class="flex items-center gap-2.5">
                                        <span class="w-1.5 h-1.5 rounded-full bg-[#ff6b35]/60 group-hover:bg-[#ff6b35]"></span>
                                        <div>
                                            <div class="font-bold text-white flex items-center gap-1.5">
                                                <span>{{ user.username }}</span>
                                            </div>
                                            <div class="text-[10px] text-[#5e6f85]">{{ user.email }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-5 py-3.5">
                                    <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-xs text-[10px] bg-[#161c26] text-[#8ea0b5] border border-[#222c3c]">
                                        <PackageIcon class="w-3 h-3 text-[#ff6b35]" />
                                        {{ user.package?.name || 'DEFAULT' }}
                                    </span>
                                </td>
                                <td class="px-5 py-3.5">
                                    <span class="inline-flex items-center gap-1.5 text-[#cad3e0]">
                                        <Globe class="w-3.5 h-3.5 text-[#ff6b35]" />
                                        {{ user.domains_count || 0 }}
                                    </span>
                                </td>
                                <td class="px-5 py-3.5">
                                    <span class="inline-flex items-center gap-1.5 text-[#cad3e0]">
                                        <Database class="w-3.5 h-3.5 text-[#00f0a8]" />
                                        {{ user.databases_count || 0 }}
                                    </span>
                                </td>
                                <td class="px-5 py-3.5">
                                    <div class="flex items-center gap-1.5 text-[#cad3e0]">
                                        <HardDrive class="w-3.5 h-3.5 text-[#00f0a8]" />
                                        <span>{{ diskUsageMap[user.username]?.disk_usage_human || '—' }}</span>
                                    </div>
                                    <div class="text-[10px] text-[#5e6f85]">
                                        CAP: {{ user.package?.disk_quota_mb ? `${user.package.disk_quota_mb} MB` : 'UNLIMITED' }}
                                    </div>
                                </td>
                                <td class="px-5 py-3.5">
                                    <span
                                        :class="[
                                            'inline-flex items-center px-2 py-0.5 rounded-xs text-[10px] uppercase tracking-wider',
                                            user.status === 'active'
                                                ? 'bg-[#00f0a8]/10 text-[#00f0a8] border border-[#00f0a8]/30'
                                                : 'bg-[#ff3366]/10 text-[#ff6685] border border-[#ff3366]/30'
                                        ]"
                                    >
                                        {{ user.status }}
                                    </span>
                                </td>
                                <td class="px-5 py-3.5 text-right">
                                    <div class="flex items-center justify-end gap-1.5">
                                        <!-- Impersonate Button -->
                                        <button
                                            @click="impersonateUser(user.id, user.username)"
                                            title="Assume Context"
                                            class="text-[#5e6f85] hover:text-[#ff6b35] transition-colors p-1"
                                        >
                                            <LogIn class="w-4 h-4" />
                                        </button>

                                        <!-- Edit Principal -->
                                        <button
                                            @click="openEditModal(user)"
                                            title="Edit Principal"
                                            class="text-[#5e6f85] hover:text-[#00f0a8] transition-colors p-1"
                                        >
                                            <Pencil class="w-4 h-4" />
                                        </button>

                                        <!-- Suspend / Unsuspend -->
                                        <button
                                            v-if="user.status === 'active'"
                                            @click="suspendUser(user.id, user.username)"
                                            title="Suspend Context"
                                            class="text-[#5e6f85] hover:text-[#ffd000] transition-colors p-1"
                                        >
                                            <Lock class="w-4 h-4" />
                                        </button>
                                        <button
                                            v-else
                                            @click="unsuspendUser(user.id, user.username)"
                                            title="Restore Context"
                                            class="text-[#5e6f85] hover:text-[#00f0a8] transition-colors p-1"
                                        >
                                            <Unlock class="w-4 h-4" />
                                        </button>

                                        <!-- Delete -->
                                        <button
                                            @click="deleteUser(user.id, user.username)"
                                            title="Terminate Principal"
                                            class="text-[#5e6f85] hover:text-[#ff3366] transition-colors p-1"
                                        >
                                            <Trash2 class="w-4 h-4" />
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="!users.data || users.data.length === 0">
                                <td colspan="7" class="px-5 py-8 text-center text-[#5a6b80] text-xs font-mono">
                                    NO PRINCIPALS MATCHING SPECIFICATION
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Edit User Modal -->
            <Modal :show="showEditModal" max-width="md" @close="showEditModal = false">
                <div class="p-6 space-y-5">
                    <div class="flex items-center justify-between border-b border-[#1e2634] pb-3">
                        <div class="flex items-center gap-2">
                            <span class="w-2 h-2 bg-[#ff6b35]"></span>
                            <h3 class="text-sm font-mono font-bold uppercase tracking-wider text-white">
                                Edit Principal: {{ editingUser?.username }}
                            </h3>
                        </div>
                        <button @click="showEditModal = false" class="text-[#64748b] hover:text-white transition-colors">
                            <X class="w-4 h-4" />
                        </button>
                    </div>

                    <form @submit.prevent="submitEdit" class="space-y-4 font-mono text-xs">
                        <!-- Full Name -->
                        <div>
                            <label class="block text-[10px] uppercase tracking-wider text-[#7a8b9f] mb-1.5">Full Name</label>
                            <input
                                v-model="editForm.name"
                                type="text"
                                required
                                class="w-full px-3 py-2 bg-[#080b0f] border border-[#1e2634] rounded-xs text-[#cad3e0] text-xs focus:outline-none focus:border-[#ff6b35]"
                            />
                            <p v-if="editForm.errors.name" class="text-[10px] text-[#ff3366] mt-1">{{ editForm.errors.name }}</p>
                        </div>

                        <!-- Email -->
                        <div>
                            <label class="block text-[10px] uppercase tracking-wider text-[#7a8b9f] mb-1.5">Email Address</label>
                            <input
                                v-model="editForm.email"
                                type="email"
                                required
                                class="w-full px-3 py-2 bg-[#080b0f] border border-[#1e2634] rounded-xs text-[#cad3e0] text-xs focus:outline-none focus:border-[#ff6b35]"
                            />
                            <p v-if="editForm.errors.email" class="text-[10px] text-[#ff3366] mt-1">{{ editForm.errors.email }}</p>
                        </div>

                        <!-- Quota Plan -->
                        <div>
                            <label class="block text-[10px] uppercase tracking-wider text-[#7a8b9f] mb-1.5">Quota Plan</label>
                            <select
                                v-model="editForm.package_id"
                                required
                                class="w-full px-3 py-2 bg-[#080b0f] border border-[#1e2634] rounded-xs text-[#cad3e0] text-xs focus:outline-none focus:border-[#ff6b35]"
                            >
                                <option v-for="pkg in packages" :key="pkg.id" :value="pkg.id">
                                    {{ pkg.name }} ({{ pkg.disk_quota_mb ? `${pkg.disk_quota_mb} MB` : 'Unlimited' }})
                                </option>
                            </select>
                            <p v-if="editForm.errors.package_id" class="text-[10px] text-[#ff3366] mt-1">{{ editForm.errors.package_id }}</p>
                        </div>

                        <!-- Password (Optional) -->
                        <div>
                            <label class="block text-[10px] uppercase tracking-wider text-[#7a8b9f] mb-1.5 flex items-center justify-between">
                                <span>Password Reset</span>
                                <span class="text-[#5e6f85] font-normal">Leave blank to keep unchanged</span>
                            </label>
                            <input
                                v-model="editForm.password"
                                type="password"
                                placeholder="••••••••••••"
                                class="w-full px-3 py-2 bg-[#080b0f] border border-[#1e2634] rounded-xs text-[#cad3e0] text-xs focus:outline-none focus:border-[#ff6b35]"
                            />
                            <p v-if="editForm.errors.password" class="text-[10px] text-[#ff3366] mt-1">{{ editForm.errors.password }}</p>
                        </div>

                        <!-- SSH Public Key -->
                        <div>
                            <label class="block text-[10px] uppercase tracking-wider text-[#7a8b9f] mb-1.5 flex items-center justify-between">
                                <span class="flex items-center gap-1.5">
                                    <Key class="w-3.5 h-3.5 text-[#ff6b35]" />
                                    <span>SSH Public Key</span>
                                </span>
                                <span class="text-[#5e6f85] font-normal">Stored in ~/.ssh/authorized_keys</span>
                            </label>
                            <textarea
                                v-model="editForm.ssh_public_key"
                                rows="3"
                                placeholder="ssh-ed25519 AAAAC3... or leave empty to clear"
                                class="w-full px-3 py-2 bg-[#080b0f] border border-[#1e2634] rounded-xs text-[11px] font-mono text-[#cad3e0] placeholder-[#4e5c6e] focus:outline-none focus:border-[#ff6b35]"
                            ></textarea>
                            <p v-if="editForm.errors.ssh_public_key" class="text-[10px] text-[#ff3366] mt-1">{{ editForm.errors.ssh_public_key }}</p>
                        </div>

                        <!-- Action Buttons -->
                        <div class="pt-4 flex items-center justify-end gap-3 border-t border-[#1e2634]">
                            <button
                                type="button"
                                @click="showEditModal = false"
                                class="px-4 py-2 rounded-xs bg-[#161c26] hover:bg-[#202837] text-xs font-mono uppercase tracking-wider text-[#8ea0b5] transition-colors"
                            >
                                Abort
                            </button>
                            <button
                                type="submit"
                                :disabled="editForm.processing"
                                class="px-5 py-2 rounded-xs bg-[#ff6b35] hover:bg-[#ff8252] disabled:opacity-50 text-xs font-mono font-bold uppercase tracking-wider text-black transition-colors shadow-[0_0_15px_rgba(255,107,53,0.25)]"
                            >
                                {{ editForm.processing ? 'Syncing...' : 'Save Changes' }}
                            </button>
                        </div>
                    </form>
                </div>
            </Modal>
        </div>
</template>
