<script setup lang="ts">
import { shallowRef } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { Shield, Plus, Trash2 } from '@lucide/vue';
import { confirmDialog } from '@shared/Composables/useDialog';
import UiPageHeader from '@shared/Ui/UiPageHeader.vue';
import UiBadge from '@shared/Ui/UiBadge.vue';
import UiButton from '@shared/Ui/UiButton.vue';
import UiDataTable from '@shared/Ui/UiDataTable.vue';
import UiModal from '@shared/Ui/UiModal.vue';
import UiInput from '@shared/Ui/UiInput.vue';
import UiSelect from '@shared/Ui/UiSelect.vue';

const props = defineProps({
    active: Boolean,
    systemRules: Array,
    rules: Array,
});

const modalOpen = shallowRef(false);

const form = useForm({
    name: '',
    port: '',
    protocol: 'tcp',
    action: 'allow',
    source_ip: '',
});

const submitRule = () => {
    form.post('/security/ufw/rules', {
        onSuccess: () => {
            modalOpen.value = false;
            form.reset();
        },
    });
};

const deleteRule = async (ruleId) => {
    if (await confirmDialog({
        title: 'Delete Firewall Rule',
        message: `Are you sure you want to delete firewall rule ${ruleId}?`,
        confirmText: 'Delete',
        variant: 'danger',
    })) {
        router.delete(`/security/ufw/rules/${ruleId}`);
    }
};
</script>

<template>
    <Head :title="__('UFW Firewall')" />

    <div class="space-y-6">
        <UiPageHeader
            :title="__('UFW Firewall Manager')"
            subtitle="Control inbound server network traffic and ports with Uncomplicated Firewall."
        >
            <template #icon>
                <Shield class="w-6 h-6 text-theme-accent" />
            </template>
            <template #actions>
                <UiBadge :variant="active ? 'success' : 'danger'">
                    <span class="w-2 h-2 rounded-full" :class="active ? 'bg-theme-success' : 'bg-theme-danger'"></span>
                    <span>UFW {{ active ? 'Active' : 'Inactive' }}</span>
                </UiBadge>

                <UiButton
                    variant="primary"
                    size="sm"
                    @click="modalOpen = true"
                >
                    <Plus class="w-4 h-4" />
                    <span>{{ __('Add Rule') }}</span>
                </UiButton>
            </template>
        </UiPageHeader>

        <!-- Rules Table -->
        <UiDataTable
            :headers="[
                '# / Port',
                __('Protocol'),
                __('Action'),
                __('Source IP'),
                { label: __('Actions'), class: 'text-right' }
            ]"
            :items-count="systemRules?.length || 0"
        >
            <tr v-for="(r, idx) in systemRules" :key="r.id || idx" class="hover:bg-theme-bg-surface/40 transition-colors font-mono text-xs">
                <td class="px-4 py-3.5 font-semibold text-theme-text-primary font-sans">
                    {{ r.to || r.port }}
                </td>
                <td class="px-4 py-3.5 text-theme-text-secondary uppercase">
                    {{ (r.to && r.to.includes('/')) ? r.to.split('/')[1] : 'tcp' }}
                </td>
                <td class="px-4 py-3.5 font-sans">
                    <UiBadge
                        :variant="(r.action && r.action.toLowerCase().includes('allow')) ? 'success' : 'danger'"
                        class="uppercase"
                    >
                        {{ r.action }}
                    </UiBadge>
                </td>
                <td class="px-4 py-3.5 text-theme-text-secondary">
                    {{ r.from || 'Anywhere' }}
                </td>
                <td class="px-4 py-3.5 text-right font-sans">
                    <UiButton
                        size="xs"
                        variant="danger-soft"
                        @click="deleteRule(r.id || r.number || r.to)"
                        :title="__('Delete Rule')"
                    >
                        <Trash2 class="w-3.5 h-3.5" />
                    </UiButton>
                </td>
            </tr>
        </UiDataTable>

        <!-- Add Rule Modal -->
        <UiModal
            :show="modalOpen"
            :title="__('Add Firewall Rule')"
            max-width="md"
            @close="modalOpen = false"
        >
            <form @submit.prevent="submitRule" class="space-y-4">
                <UiInput
                    v-model="form.name"
                    type="text"
                    required
                    :label="__('Rule Name / Description')"
                    :placeholder="__('Custom App Port')"
                />

                <div class="grid grid-cols-2 gap-4">
                    <UiInput
                        v-model="form.port"
                        type="text"
                        required
                        :label="__('Port (or Range)')"
                        placeholder="8080"
                    />

                    <UiSelect
                        v-model="form.protocol"
                        :label="__('Protocol')"
                        :options="[
                            { value: 'tcp', label: __('TCP') },
                            { value: 'udp', label: __('UDP') },
                            { value: 'any', label: __('Any') }
                        ]"
                    />
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <UiSelect
                        v-model="form.action"
                        :label="__('Action')"
                        :options="[
                            { value: 'allow', label: __('ALLOW') },
                            { value: 'deny', label: __('DENY') }
                        ]"
                    />

                    <UiInput
                        v-model="form.source_ip"
                        type="text"
                        :label="__('Source IP (Optional)')"
                        :placeholder="__('Anywhere')"
                    />
                </div>

                <div class="pt-4 border-t border-theme-border-subtle flex items-center justify-end gap-3">
                    <UiButton
                        type="button"
                        variant="secondary"
                        @click="modalOpen = false"
                    >
                        Cancel
                    </UiButton>
                    <UiButton
                        type="submit"
                        variant="primary"
                        :loading="form.processing"
                    >
                        {{ form.processing ? 'Adding...' : 'Add Rule' }}
                    </UiButton>
                </div>
            </form>
        </UiModal>
    </div>
</template>
