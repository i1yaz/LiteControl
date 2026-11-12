<script setup>
import { shallowRef } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import Modal from '@/Components/Modal.vue';
import { Package, Plus, Edit2, Trash2, Users, HardDrive, Activity, Globe, Database, Mail, X } from '@lucide/vue';

const props = defineProps({
    packages: Array,
});

const showModal = shallowRef(false);
const isEditing = shallowRef(false);
const editingId = shallowRef(null);

const form = useForm({
    name: '',
    disk_quota_mb: 2048,
    bandwidth_mb: 20480,
    max_domains: 5,
    max_subdomains: 10,
    max_databases: 5,
    max_ftp_accounts: 5,
    max_email_accounts: 10,
});

const openCreateModal = () => {
    isEditing.value = false;
    editingId.value = null;
    form.reset();
    showModal.value = true;
};

const openEditModal = (pkg) => {
    isEditing.value = true;
    editingId.value = pkg.id;
    form.name = pkg.name;
    form.disk_quota_mb = pkg.disk_quota_mb;
    form.bandwidth_mb = pkg.bandwidth_mb;
    form.max_domains = pkg.max_domains;
    form.max_subdomains = pkg.max_subdomains;
    form.max_databases = pkg.max_databases;
    form.max_ftp_accounts = pkg.max_ftp_accounts;
    form.max_email_accounts = pkg.max_email_accounts;
    showModal.value = true;
};

const submit = () => {
    if (isEditing.value) {
        form.put(`/packages/${editingId.value}`, {
            onSuccess: () => {
                showModal.value = false;
            },
        });
    } else {
        form.post('/packages', {
            onSuccess: () => {
                showModal.value = false;
            },
        });
    }
};

const deletePackage = (id, name, usersCount) => {
    if (usersCount > 0) {
        alert(`Cannot delete package '${name}' because it has ${usersCount} assigned users.`);
        return;
    }
    if (confirm(`Are you sure you want to delete package '${name}'?`)) {
        router.delete(`/packages/${id}`);
    }
};
</script>

<template>
    <Head title="Hosting Packages" />

    <div class="space-y-6">
            <!-- Header -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 class="text-xl sm:text-2xl font-bold text-white flex items-center gap-2.5">
                        <Package class="w-6 h-6 text-indigo-400" />
                        <span>Hosting Packages</span>
                    </h1>
                    <p class="text-xs sm:text-sm text-slate-400 mt-1">
                        Define disk quotas, bandwidth limits, and resource allocations for customer accounts.
                    </p>
                </div>

                <button
                    @click="openCreateModal"
                    class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium transition-colors shadow-sm shadow-indigo-600/30"
                >
                    <Plus class="w-4 h-4" />
                    <span>Create Package</span>
                </button>
            </div>

            <!-- Packages Grid -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <div
                    v-for="pkg in packages"
                    :key="pkg.id"
                    class="bg-slate-900 border border-slate-800 rounded-xl p-5 space-y-4 hover:border-slate-700 transition-colors shadow-sm"
                >
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-lg bg-indigo-500/10 border border-indigo-500/20 text-indigo-400 flex items-center justify-center">
                                <Package class="w-5 h-5" />
                            </div>
                            <div>
                                <h3 class="text-base font-bold text-white">{{ pkg.name }}</h3>
                                <span class="text-xs text-slate-400 flex items-center gap-1">
                                    <Users class="w-3 h-3 text-slate-500" />
                                    {{ pkg.users_count || 0 }} Users Assigned
                                </span>
                            </div>
                        </div>

                        <div class="flex items-center gap-1">
                            <button
                                @click="openEditModal(pkg)"
                                class="p-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 hover:text-white transition-colors"
                            >
                                <Edit2 class="w-3.5 h-3.5" />
                            </button>
                            <button
                                @click="deletePackage(pkg.id, pkg.name, pkg.users_count)"
                                class="p-1.5 rounded-lg bg-slate-800 hover:bg-rose-500/20 text-rose-400 transition-colors"
                            >
                                <Trash2 class="w-3.5 h-3.5" />
                            </button>
                        </div>
                    </div>

                    <!-- Limits List -->
                    <div class="grid grid-cols-2 gap-2 pt-2 border-t border-slate-800 text-xs">
                        <div class="flex items-center gap-2 p-2 rounded-lg bg-slate-950/60 border border-slate-800/60">
                            <HardDrive class="w-4 h-4 text-cyan-400" />
                            <div>
                                <span class="text-slate-400 text-[10px] uppercase block">Disk Quota</span>
                                <span class="font-semibold text-white">{{ pkg.disk_quota_mb }} MB</span>
                            </div>
                        </div>

                        <div class="flex items-center gap-2 p-2 rounded-lg bg-slate-950/60 border border-slate-800/60">
                            <Activity class="w-4 h-4 text-emerald-400" />
                            <div>
                                <span class="text-slate-400 text-[10px] uppercase block">Bandwidth</span>
                                <span class="font-semibold text-white">{{ pkg.bandwidth_mb }} MB</span>
                            </div>
                        </div>

                        <div class="flex items-center gap-2 p-2 rounded-lg bg-slate-950/60 border border-slate-800/60">
                            <Globe class="w-4 h-4 text-indigo-400" />
                            <div>
                                <span class="text-slate-400 text-[10px] uppercase block">Domains</span>
                                <span class="font-semibold text-white">{{ pkg.max_domains }}</span>
                            </div>
                        </div>

                        <div class="flex items-center gap-2 p-2 rounded-lg bg-slate-950/60 border border-slate-800/60">
                            <Database class="w-4 h-4 text-amber-400" />
                            <div>
                                <span class="text-slate-400 text-[10px] uppercase block">Databases</span>
                                <span class="font-semibold text-white">{{ pkg.max_databases }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Create / Edit Modal -->
            <Modal :show="showModal" max-width="lg" @close="showModal = false">
                <div class="p-6 space-y-4">
                    <div class="flex items-center justify-between border-b border-slate-800 pb-3">
                        <h3 class="text-base font-bold text-white">
                            {{ isEditing ? 'Edit Package' : 'Create Hosting Package' }}
                        </h3>
                        <button @click="showModal = false" class="text-slate-400 hover:text-white">
                            <X class="w-5 h-5" />
                        </button>
                    </div>

                    <form @submit.prevent="submit" class="space-y-4">
                        <div>
                            <label class="block text-xs font-semibold text-slate-300 uppercase mb-1">Package Name</label>
                            <input
                                v-model="form.name"
                                type="text"
                                placeholder="e.g. Standard 5GB"
                                required
                                class="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-lg text-sm text-slate-200 focus:outline-none focus:border-indigo-500"
                            />
                            <p v-if="form.errors.name" class="text-xs text-rose-400 mt-1">{{ form.errors.name }}</p>
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-semibold text-slate-300 uppercase mb-1">Disk Quota (MB)</label>
                                <input
                                    v-model="form.disk_quota_mb"
                                    type="number"
                                    min="100"
                                    required
                                    class="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-lg text-sm text-slate-200 focus:outline-none focus:border-indigo-500"
                                />
                            </div>

                            <div>
                                <label class="block text-xs font-semibold text-slate-300 uppercase mb-1">Bandwidth (MB)</label>
                                <input
                                    v-model="form.bandwidth_mb"
                                    type="number"
                                    min="100"
                                    required
                                    class="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-lg text-sm text-slate-200 focus:outline-none focus:border-indigo-500"
                                />
                            </div>
                        </div>

                        <div class="grid grid-cols-3 gap-3">
                            <div>
                                <label class="block text-xs font-semibold text-slate-300 uppercase mb-1">Max Domains</label>
                                <input
                                    v-model="form.max_domains"
                                    type="number"
                                    min="1"
                                    required
                                    class="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-lg text-sm text-slate-200 focus:outline-none focus:border-indigo-500"
                                />
                            </div>

                            <div>
                                <label class="block text-xs font-semibold text-slate-300 uppercase mb-1">Subdomains</label>
                                <input
                                    v-model="form.max_subdomains"
                                    type="number"
                                    min="0"
                                    required
                                    class="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-lg text-sm text-slate-200 focus:outline-none focus:border-indigo-500"
                                />
                            </div>

                            <div>
                                <label class="block text-xs font-semibold text-slate-300 uppercase mb-1">Databases</label>
                                <input
                                    v-model="form.max_databases"
                                    type="number"
                                    min="0"
                                    required
                                    class="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-lg text-sm text-slate-200 focus:outline-none focus:border-indigo-500"
                                />
                            </div>
                        </div>

                        <div class="pt-4 flex items-center justify-end gap-3 border-t border-slate-800">
                            <button
                                type="button"
                                @click="showModal = false"
                                class="px-4 py-2 rounded-lg bg-slate-800 hover:bg-slate-700 text-sm font-medium text-slate-300"
                            >
                                Cancel
                            </button>
                            <button
                                type="submit"
                                :disabled="form.processing"
                                class="px-5 py-2 rounded-lg bg-indigo-600 hover:bg-indigo-500 disabled:opacity-50 text-sm font-medium text-white shadow-sm"
                            >
                                {{ isEditing ? 'Save Changes' : 'Create Package' }}
                            </button>
                        </div>
                    </form>
                </div>
            </Modal>
        </div>
</template>

