<script setup lang="ts">
import { shallowRef } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { confirmDialog, alertDialog } from '@shared/Composables/useDialog';
import UiPageHeader from '@shared/Ui/UiPageHeader.vue';
import UiButton from '@shared/Ui/UiButton.vue';
import UiCard from '@shared/Ui/UiCard.vue';
import UiInput from '@shared/Ui/UiInput.vue';
import UiModal from '@shared/Ui/UiModal.vue';
import { Package, Plus, Edit2, Trash2, Users, HardDrive, Activity, Globe, Database, Mail } from '@lucide/vue';

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

const deletePackage = async (id, name, usersCount) => {
    if (usersCount > 0) {
        await alertDialog({
            title: 'Cannot Delete Package',
            message: `Cannot delete package '${name}' because it has ${usersCount} assigned users.`,
            variant: 'warning',
        });
        return;
    }
    if (await confirmDialog({
        title: 'Delete Package',
        message: `Are you sure you want to delete package '${name}'?`,
        confirmText: 'Delete',
        variant: 'danger',
    })) {
        router.delete(`/packages/${id}`);
    }
};
</script>

<template>
    <Head :title="__('Hosting Packages')" />

    <div class="space-y-6">
        <UiPageHeader
            :title="__('Hosting Packages')"
            subtitle="Define disk quotas, bandwidth limits, and resource allocations for customer accounts."
        >
            <template #icon>
                <Package class="w-6 h-6 text-theme-accent" />
            </template>
            <template #actions>
                <UiButton
                    variant="primary"
                    @click="openCreateModal"
                >
                    <Plus class="w-4 h-4" />
                    <span>{{ __('Create Package') }}</span>
                </UiButton>
            </template>
        </UiPageHeader>

        <!-- Packages Grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <UiCard
                v-for="pkg in packages"
                :key="pkg.id"
                class="space-y-4 hover:border-theme-border-hover transition-colors"
            >
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-theme-md bg-theme-accent-subtle border border-theme-accent-border text-theme-accent flex items-center justify-center">
                            <Package class="w-5 h-5" />
                        </div>
                        <div>
                            <h3 class="text-base font-bold text-theme-text-primary">{{ pkg.name }}</h3>
                            <span class="text-xs text-theme-text-secondary flex items-center gap-1">
                                <Users class="w-3 h-3 text-theme-text-muted" />
                                {{ pkg.users_count || 0 }} Users Assigned
                            </span>
                        </div>
                    </div>

                    <div class="flex items-center gap-1">
                        <button
                            @click="openEditModal(pkg)"
                            class="p-1.5 rounded-theme-md bg-theme-bg-surface hover:bg-theme-border text-theme-text-secondary hover:text-theme-text-primary border border-theme-border transition-colors"
                        >
                            <Edit2 class="w-3.5 h-3.5" />
                        </button>
                        <button
                            @click="deletePackage(pkg.id, pkg.name, pkg.users_count)"
                            class="p-1.5 rounded-theme-md bg-theme-bg-surface hover:bg-theme-danger-bg text-theme-danger border border-theme-border hover:border-theme-danger-border transition-colors"
                        >
                            <Trash2 class="w-3.5 h-3.5" />
                        </button>
                    </div>
                </div>

                <!-- Limits List -->
                <div class="grid grid-cols-2 gap-2 pt-2 border-t border-theme-border-subtle text-xs">
                    <div class="flex items-center gap-2 p-2 rounded-theme-md bg-theme-bg-surface border border-theme-border-subtle">
                        <HardDrive class="w-4 h-4 text-theme-accent" />
                        <div>
                            <span class="text-theme-text-muted text-[10px] uppercase block">{{ __('Disk Quota') }}</span>
                            <span class="font-semibold text-theme-text-primary">{{ pkg.disk_quota_mb }} MB</span>
                        </div>
                    </div>

                    <div class="flex items-center gap-2 p-2 rounded-theme-md bg-theme-bg-surface border border-theme-border-subtle">
                        <Activity class="w-4 h-4 text-theme-success" />
                        <div>
                            <span class="text-theme-text-muted text-[10px] uppercase block">{{ __('Bandwidth') }}</span>
                            <span class="font-semibold text-theme-text-primary">{{ pkg.bandwidth_mb }} MB</span>
                        </div>
                    </div>

                    <div class="flex items-center gap-2 p-2 rounded-theme-md bg-theme-bg-surface border border-theme-border-subtle">
                        <Globe class="w-4 h-4 text-theme-info" />
                        <div>
                            <span class="text-theme-text-muted text-[10px] uppercase block">{{ __('Domains') }}</span>
                            <span class="font-semibold text-theme-text-primary">{{ pkg.max_domains }}</span>
                        </div>
                    </div>

                    <div class="flex items-center gap-2 p-2 rounded-theme-md bg-theme-bg-surface border border-theme-border-subtle">
                        <Database class="w-4 h-4 text-theme-warning" />
                        <div>
                            <span class="text-theme-text-muted text-[10px] uppercase block">{{ __('Databases') }}</span>
                            <span class="font-semibold text-theme-text-primary">{{ pkg.max_databases }}</span>
                        </div>
                    </div>
                </div>
            </UiCard>
        </div>

        <!-- Create / Edit Modal -->
        <UiModal :show="showModal" :title="isEditing ? 'Edit Package' : 'Create Hosting Package'" max-width="lg" @close="showModal = false">
            <form @submit.prevent="submit" class="space-y-4">
                <div>
                    <UiInput
                        id="pkg_name"
                        v-model="form.name"
                        type="text"
                        :label="__('Package Name')"
                        :placeholder="__('e.g. Standard 5GB')"
                        :error="form.errors.name"
                        required
                    />
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <UiInput
                            id="pkg_disk_quota"
                            v-model="form.disk_quota_mb"
                            type="number"
                            min="100"
                            required
                            :label="__('Disk Quota (MB)')"
                        />
                    </div>

                    <div>
                        <UiInput
                            id="pkg_bandwidth"
                            v-model="form.bandwidth_mb"
                            type="number"
                            min="100"
                            required
                            :label="__('Bandwidth (MB)')"
                        />
                    </div>
                </div>

                <div class="grid grid-cols-3 gap-3">
                    <div>
                        <UiInput
                            id="pkg_max_domains"
                            v-model="form.max_domains"
                            type="number"
                            min="1"
                            required
                            :label="__('Max Domains')"
                        />
                    </div>

                    <div>
                        <UiInput
                            id="pkg_max_subdomains"
                            v-model="form.max_subdomains"
                            type="number"
                            min="0"
                            required
                            :label="__('Subdomains')"
                        />
                    </div>

                    <div>
                        <UiInput
                            id="pkg_max_databases"
                            v-model="form.max_databases"
                            type="number"
                            min="0"
                            required
                            :label="__('Databases')"
                        />
                    </div>
                </div>

                <div class="pt-4 flex items-center justify-end gap-3 border-t border-theme-border">
                    <UiButton
                        type="button"
                        variant="secondary"
                        @click="showModal = false"
                    >
                        Cancel
                    </UiButton>
                    <UiButton
                        type="submit"
                        variant="primary"
                        :disabled="form.processing"
                        :loading="form.processing"
                    >
                        {{ isEditing ? 'Save Changes' : 'Create Package' }}
                    </UiButton>
                </div>
            </form>
        </UiModal>
    </div>
</template>

