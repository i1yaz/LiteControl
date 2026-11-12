<script setup>
import { router } from '@inertiajs/vue3';
import { UserMinus, Trash2, X, Folder } from '@lucide/vue';
import { confirmDialog } from '@/Composables/useDialog';

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
    <div class="bg-theme-bg-card border border-theme-border rounded-xl p-5 shadow-sm space-y-5">
        <div class="flex items-center justify-between border-b border-theme-border pb-3">
            <div class="flex items-center gap-2">
                <UserMinus class="w-4 h-4 text-rose-400" />
                <h3 class="text-xs font-bold uppercase tracking-wider text-theme-text-primary">
                    Manage & Delete FTP Accounts ({{ domain.ftp_accounts?.length || 0 }})
                </h3>
            </div>

            <button @click="emit('close')" class="text-theme-text-muted hover:text-theme-text-primary p-1">
                <X class="w-4 h-4" />
            </button>
        </div>

        <div v-if="domain.ftp_accounts && domain.ftp_accounts.length > 0" class="divide-y divide-theme-border border border-theme-border rounded-lg overflow-hidden">
            <div
                v-for="acc in domain.ftp_accounts"
                :key="acc.id"
                class="p-4 bg-theme-bg-surface/40 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 text-xs hover:bg-theme-bg-surface/80 transition-colors"
            >
                <div class="space-y-1">
                    <div class="flex items-center gap-2">
                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span>
                        <strong class="font-mono text-theme-text-primary text-sm">{{ acc.username }}</strong>
                        <span class="px-1.5 py-0.5 rounded text-[10px] bg-theme-bg-surface border border-theme-border text-theme-text-muted">
                            {{ acc.quota_mb > 0 ? `${acc.quota_mb} MB Quota` : 'Unlimited Quota' }}
                        </span>
                    </div>

                    <div class="flex items-center gap-1.5 text-theme-text-secondary text-[11px] font-mono">
                        <Folder class="w-3.5 h-3.5 text-theme-text-muted" />
                        <span>{{ acc.dir }}</span>
                    </div>
                </div>

                <button
                    @click="deleteFtp(acc)"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-rose-500/10 hover:bg-rose-500/20 text-rose-400 text-xs font-semibold rounded-lg border border-rose-500/30 transition-colors"
                    title="Delete FTP Account"
                >
                    <Trash2 class="w-3.5 h-3.5" />
                    <span>Delete</span>
                </button>
            </div>
        </div>

        <div v-else class="text-xs text-theme-text-muted py-4 italic">
            No active FTP accounts configured for this domain.
        </div>
    </div>
</template>

