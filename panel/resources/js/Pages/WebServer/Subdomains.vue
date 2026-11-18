<script setup lang="ts">
import { shallowRef, computed } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { confirmDialog } from '@shared/Composables/useDialog';
import UiPageHeader from '@shared/Ui/UiPageHeader.vue';
import UiButton from '@shared/Ui/UiButton.vue';
import UiDataTable from '@shared/Ui/UiDataTable.vue';
import UiInput from '@shared/Ui/UiInput.vue';
import UiSelect from '@shared/Ui/UiSelect.vue';
import UiToggle from '@shared/Ui/UiToggle.vue';
import UiModal from '@shared/Ui/UiModal.vue';
import { Globe, Plus, Folder, Trash2 } from '@lucide/vue';
import { usePhpVersions } from '@shared/Composables/usePhpVersions';

const props = defineProps({
    subdomains: Object,
    baseDomains: Array,
    phpVersions: {
        type: Array,
        default: () => [],
    },
});

const showModal = shallowRef(false);

const form = useForm({
    subdomain_prefix: '',
    parent_domain_id: props.baseDomains?.[0]?.id || '',
    public_path: '',
    php_version: props.baseDomains?.[0]?.php_version || '83',
    ssl_enabled: true,
});

const selectedParentDomain = computed(() => {
    return props.baseDomains?.find((d) => d.id === form.parent_domain_id) || null;
});

const { phpOptions } = usePhpVersions(() =>
    Array.isArray(props.phpVersions) ? props.phpVersions.filter((v) => v?.installed) : []
);

const docRootPreview = computed(() => {
    const username = selectedParentDomain.value?.user?.username || 'user';
    const parent = selectedParentDomain.value?.domain || 'example.com';
    const prefix = form.subdomain_prefix.trim().toLowerCase() || 'sub';
    const sub = form.public_path.trim().replace(/^\/+|\/+$/g, '');
    return `/home/${username}/${prefix}.${parent}/public_html${sub ? '/' + sub : ''}`;
});

const submit = () => {
    form.post('/subdomains', {
        onSuccess: () => {
            showModal.value = false;
            form.reset('subdomain_prefix', 'public_path');
            form.ssl_enabled = true;
        },
    });
};

const deleteSubdomain = async (id, domain) => {
    if (await confirmDialog({
        title: 'Delete Subdomain',
        message: `Are you sure you want to delete subdomain '${domain}'?`,
        confirmText: 'Delete',
        variant: 'danger',
    })) {
        router.delete(`/domains/${id}`);
    }
};
</script>

<template>
    <Head :title="__('Subdomains Management')" />

    <div class="space-y-6">
        <!-- Header -->
        <UiPageHeader
            :title="__('Subdomains')"
            subtitle="Create and manage subdomains for your hosted domains."
        >
            <template #icon>
                <Globe class="w-5 h-5" />
            </template>
            <template #actions>
                <UiButton @click="showModal = true" variant="primary">
                    <Plus class="w-4 h-4" />
                    <span>{{ __('Create Subdomain') }}</span>
                </UiButton>
            </template>
        </UiPageHeader>

        <!-- Table -->
        <UiDataTable
            :headers="[
                { label: __('Subdomain') },
                { label: __('Base Domain') },
                { label: __('Document Root') },
                { label: __('PHP Version') },
                { label: __('Actions'), class: 'text-right' },
            ]"
            :items-count="subdomains.data?.length || 0"
            empty-message="No subdomains created yet."
        >
            <tr v-for="sub in subdomains.data" :key="sub.id" class="hover:bg-theme-bg-surface/50 transition-colors">
                <td class="px-4 py-3.5 font-semibold text-theme-text-primary">
                    {{ sub.domain }}
                </td>
                <td class="px-4 py-3.5 text-xs text-theme-text-secondary">
                    {{ sub.parent_domain?.domain || '—' }}
                </td>
                <td class="px-4 py-3.5 text-xs font-mono text-theme-text-secondary">
                    <span class="flex items-center gap-1.5 truncate max-w-xs">
                        <Folder class="w-3.5 h-3.5 text-theme-text-muted shrink-0" />
                        {{ sub.document_root }}
                    </span>
                </td>
                <td class="px-4 py-3.5 text-xs text-theme-text-secondary">
                    PHP {{ sub.php_version }}
                </td>
                <td class="px-4 py-3.5 text-right">
                    <UiButton
                        size="xs"
                        variant="danger-soft"
                        @click="deleteSubdomain(sub.id, sub.domain)"
                    >
                        <Trash2 class="w-4 h-4" />
                    </UiButton>
                </td>
            </tr>
        </UiDataTable>

        <!-- Modal -->
        <UiModal :show="showModal" :title="__('Create Subdomain')" max-width="md" @close="showModal = false">
            <form @submit.prevent="submit" class="space-y-4">
                <div>
                    <UiInput
                        id="subdomain_prefix"
                        v-model="form.subdomain_prefix"
                        type="text"
                        :label="__('Subdomain Prefix')"
                        :placeholder="__('blog, app, api')"
                        :error="form.errors.subdomain_prefix"
                        required
                    />
                </div>

                <div>
                    <UiSelect
                        id="parent_domain_id"
                        v-model="form.parent_domain_id"
                        :label="__('Parent Domain')"
                        required
                    >
                        <option v-for="d in baseDomains" :key="d.id" :value="d.id">
                            {{ d.domain }}
                        </option>
                    </UiSelect>
                </div>

                <!-- Web Root Subpath (Pointing Target) -->
                <div>
                    <UiInput
                        id="public_path"
                        v-model="form.public_path"
                        type="text"
                        :label="__('Web Root Subpath (Pointing Target)')"
                        :placeholder="__('public (leave empty for standard public_html)')"
                        :error="form.errors.public_path"
                    >
                        <template #label-right>
                            <span class="text-xs text-theme-text-muted font-normal lowercase">{{ __('e.g. "public" for Laravel') }}</span>
                        </template>
                    </UiInput>
                    <p class="text-[11px] text-theme-text-muted mt-1">
                        Target DocRoot: <span class="text-theme-accent font-mono">{{ docRootPreview }}</span>
                    </p>
                </div>

                <!-- PHP Runtime -->
                <div>
                    <UiSelect
                        id="php_version"
                        v-model="form.php_version"
                        :label="__('PHP Runtime')"
                        :options="phpOptions"
                        :error="form.errors.php_version"
                    />
                </div>

                <!-- Generate Let's Encrypt SSL -->
                <UiToggle
                    v-model="form.ssl_enabled"
                    :label="__('Generate Let\'s Encrypt SSL')"
                    :description="__('Default: ON (Requires DNS A-record pointed)')"
                    class="pt-2 border-t border-theme-border"
                />

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
                        {{ form.processing ? 'Creating...' : 'Create Subdomain' }}
                    </UiButton>
                </div>
            </form>
        </UiModal>
    </div>
</template>

