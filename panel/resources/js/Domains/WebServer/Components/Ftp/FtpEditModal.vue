<script setup lang="ts">
import { watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import UiModal from '@shared/Ui/UiModal.vue';
import UiInput from '@shared/Ui/UiInput.vue';
import UiButton from '@shared/Ui/UiButton.vue';

const props = defineProps({
    show: {
        type: Boolean,
        default: false,
    },
    account: {
        type: Object,
        default: null,
    },
});

const emit = defineEmits(['close']);

const form = useForm({
    home_directory: '',
    disk_quota: 0,
});

const extractRelativeDir = (fullDir: string, domainName?: string): string => {
    if (!fullDir) return '';
    const marker = domainName ? `/${domainName}/public_html` : '/public_html';
    const idx = fullDir.indexOf(marker);
    if (idx === -1) return '';
    return fullDir.slice(idx + marker.length).replace(/^\/+/, '');
};

watch(
    () => props.account,
    (account) => {
        if (!account) return;
        form.clearErrors();
        form.home_directory = extractRelativeDir(account.dir, account.domain?.domain);
        form.disk_quota = account.quota_mb ?? 0;
    },
    { immediate: true }
);

const submit = () => {
    if (!props.account) return;
    form.put(`/ftp/${props.account.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            emit('close');
        },
    });
};
</script>

<template>
    <UiModal :show="show" title="Edit FTP Account" max-width="sm" @close="emit('close')">
        <form @submit.prevent="submit" class="space-y-4">
            <p class="text-xs text-theme-text-secondary">
                Updating settings for <strong class="text-theme-text-primary">{{ account?.username }}</strong>
                <span v-if="account?.domain?.domain" class="text-theme-text-muted"> ({{ account.domain.domain }})</span>
            </p>

            <div>
                <UiInput
                    id="edit_ftp_home_dir"
                    v-model="form.home_directory"
                    type="text"
                    label="Home Directory (Relative to public_html)"
                    placeholder="Leave blank for domain root"
                    :error="form.errors.home_directory"
                />
            </div>

            <div>
                <UiInput
                    id="edit_ftp_quota"
                    v-model="form.disk_quota"
                    type="number"
                    min="0"
                    label="Disk Quota (MB — 0 = Unlimited)"
                    :error="form.errors.disk_quota"
                />
            </div>

            <div class="pt-4 flex items-center justify-end gap-3 border-t border-theme-border">
                <UiButton type="button" variant="secondary" @click="emit('close')">
                    Cancel
                </UiButton>
                <UiButton type="submit" variant="primary" :disabled="form.processing" :loading="form.processing">
                    {{ form.processing ? 'Saving...' : 'Save Changes' }}
                </UiButton>
            </div>
        </form>
    </UiModal>
</template>
