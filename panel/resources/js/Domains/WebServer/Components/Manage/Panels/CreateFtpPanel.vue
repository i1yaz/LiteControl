<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { UserPlus, Check, X } from '@lucide/vue';
import UiButton from '@shared/Ui/UiButton.vue';
import UiInput from '@shared/Ui/UiInput.vue';
import UiPasswordInput from '@shared/Ui/UiPasswordInput.vue';

const props = defineProps({
    domain: {
        type: Object,
        required: true,
    },
});

const emit = defineEmits(['close']);

const form = useForm({
    username: '',
    password: '',
    dir: '',
    quota_mb: 0,
});

const submitFtp = () => {
    form.post(`/domains/${props.domain.id}/ftp`, {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            emit('close');
        },
    });
};
</script>

<template>
    <div class="bg-theme-bg-card border border-theme-border rounded-theme-lg p-5 shadow-xs space-y-5">
        <div class="flex items-center justify-between border-b border-theme-border pb-3">
            <div class="flex items-center gap-2">
                <UserPlus class="w-4 h-4 text-theme-info-text" />
                <h3 class="text-xs font-bold uppercase tracking-wider text-theme-text-primary theme-ui-control">
                    Create Pure-FTPd Account for {{ domain.domain }}
                </h3>
            </div>

            <button @click="emit('close')" class="text-theme-text-muted hover:text-theme-text-primary p-1">
                <X class="w-4 h-4" />
            </button>
        </div>

        <form @submit.prevent="submitFtp" class="space-y-4 max-w-lg">
            <div>
                <label class="block text-xs font-medium text-theme-text-secondary theme-ui-control mb-1.5">
                    FTP Username <span class="text-theme-accent">*</span>
                </label>
                <div class="flex items-center">
                    <span class="px-3 py-2 bg-theme-bg-surface border border-r-0 border-theme-border rounded-l-theme-md text-xs font-mono text-theme-text-muted">
                        {{ domain.user?.username }}_
                    </span>
                    <input
                        v-model="form.username"
                        type="text"
                        required
                        :placeholder="__('webuser')"
                        class="w-full px-3 py-2 bg-theme-bg-input border border-theme-border rounded-r-theme-md text-xs text-theme-text-primary focus:outline-none focus:border-theme-accent"
                    />
                </div>
                <span v-if="form.errors.username" class="text-theme-danger-text text-[11px] mt-1 block">
                    {{ form.errors.username }}
                </span>
            </div>

            <div>
                <UiPasswordInput
                    id="ftp_password"
                    v-model="form.password"
                    label="FTP Password"
                    :error="form.errors.password"
                    required
                />
            </div>

            <div>
                <UiInput
                    id="ftp_dir"
                    v-model="form.dir"
                    type="text"
                    label="Home Directory (Relative to Website Root)"
                    :placeholder="__('public_html (or leave blank for default)')"
                />
            </div>

            <div>
                <UiInput
                    id="ftp_quota"
                    v-model="form.quota_mb"
                    type="number"
                    min="0"
                    label="Disk Quota (MB)"
                    :placeholder="__('0 for unlimited')"
                />
            </div>

            <div class="flex items-center gap-3 pt-2">
                <UiButton
                    type="submit"
                    variant="primary"
                    :disabled="form.processing"
                    :loading="form.processing"
                >
                    <Check v-if="!form.processing" class="w-3.5 h-3.5" />
                    <span>{{ form.processing ? 'Creating...' : 'Create Account' }}</span>
                </UiButton>

                <UiButton
                    type="button"
                    variant="secondary"
                    @click="emit('close')"
                >
                    Cancel
                </UiButton>
            </div>
        </form>
    </div>
</template>

