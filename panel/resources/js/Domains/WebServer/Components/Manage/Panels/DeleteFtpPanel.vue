<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { UserMinus, Trash2, X, Folder } from '@lucide/vue';
import { confirmDialog } from '@shared/Composables/useDialog';
import UiButton from '@shared/Ui/UiButton.vue';
import UiBadge from '@shared/Ui/UiBadge.vue';

const props = defineProps({
    domain: {
        type: Object,
        required: true,
    },
});

const emit = defineEmits(['close']);

const deleteFtp = async (acc) => {
    if (await confirmDialog({
        title: 'Delete FTP Account',
        message: `Are you sure you want to delete FTP account '${acc.username}'?`,
        confirmText: 'Delete',
        variant: 'danger',
    })) {
        router.delete(`/domains/${props.domain.id}/ftp/${acc.id}`, {
            preserveScroll: true,
        });
    }
};
</script>

<template>
    <div class="bg-theme-bg-card border border-theme-border rounded-theme-lg p-5 shadow-xs space-y-5">
        <div class="flex items-center justify-between border-b border-theme-border pb-3">
            <div class="flex items-center gap-2">
                <UserMinus class="w-4 h-4 text-theme-danger-text" />
                <h3 class="text-xs font-bold uppercase tracking-wider text-theme-text-primary theme-ui-control">
                    Manage & Delete FTP Accounts ({{ domain.ftp_accounts?.length || 0 }})
                </h3>
            </div>

            <button @click="emit('close')" class="text-theme-text-muted hover:text-theme-text-primary p-1">
                <X class="w-4 h-4" />
            </button>
        </div>

        <div v-if="domain.ftp_accounts && domain.ftp_accounts.length > 0" class="divide-y divide-theme-border border border-theme-border rounded-theme-md overflow-hidden">
            <div
                v-for="acc in domain.ftp_accounts"
                :key="acc.id"
                class="p-4 bg-theme-bg-surface/40 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 text-xs hover:bg-theme-bg-surface/80 transition-colors"
            >
                <div class="space-y-1">
                    <div class="flex items-center gap-2">
                        <span class="w-1.5 h-1.5 rounded-full bg-theme-success"></span>
                        <strong class="font-mono text-theme-text-primary text-sm">{{ acc.username }}</strong>
                        <UiBadge variant="neutral">
                            {{ acc.quota_mb > 0 ? `${acc.quota_mb} MB Quota` : 'Unlimited Quota' }}
                        </UiBadge>
                    </div>

                    <div class="flex items-center gap-1.5 text-theme-text-secondary text-[11px] font-mono">
                        <Folder class="w-3.5 h-3.5 text-theme-text-muted" />
                        <span>{{ acc.dir }}</span>
                    </div>
                </div>

                <UiButton
                    size="sm"
                    variant="danger-soft"
                    @click="deleteFtp(acc)"
                    :title="__('Delete FTP Account')"
                >
                    <Trash2 class="w-3.5 h-3.5" />
                    <span>{{ __('Delete') }}</span>
                </UiButton>
            </div>
        </div>

        <div v-else class="text-xs text-theme-text-muted py-4 italic">
            No active FTP accounts configured for this domain.
        </div>
    </div>
</template>

