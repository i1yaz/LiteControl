<script setup lang="ts">
import { shallowRef, computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { UserPlus, ChevronDown, ChevronUp } from '@lucide/vue';
import UiButton from '@shared/Ui/UiButton.vue';
import UiInput from '@shared/Ui/UiInput.vue';
import UiPasswordInput from '@shared/Ui/UiPasswordInput.vue';
import UiSelect from '@shared/Ui/UiSelect.vue';

const props = defineProps({
    domains: {
        type: Array,
        default: () => [],
    },
});

const isOpen = shallowRef(true);

const form = useForm({
    domain_id: props.domains?.[0]?.id ?? '',
    username: '',
    password: '',
    home_directory: '',
    disk_quota: 0,
});

const domainOptions = computed(() =>
    (props.domains || []).map((d: any) => ({
        value: d.id,
        label: d.domain,
    }))
);

const submit = () => {
    form.post('/ftp', {
        preserveScroll: true,
        onSuccess: () => {
            form.reset('username', 'password', 'home_directory');
            form.disk_quota = 0;
        },
    });
};
</script>

<template>
    <div class="bg-theme-bg-card border border-theme-border rounded-theme-lg overflow-hidden">
        <button
            type="button"
            @click="isOpen = !isOpen"
            class="w-full px-5 py-3.5 flex items-center justify-between bg-theme-bg-surface/40 border-b border-theme-border text-left hover:bg-theme-bg-surface/70 transition-colors"
        >
            <div class="flex items-center gap-2">
                <UserPlus class="w-4 h-4 text-theme-accent" />
                <span class="text-xs font-semibold uppercase tracking-wider text-theme-text-primary">
                    Create FTP Account
                </span>
            </div>
            <component :is="isOpen ? ChevronUp : ChevronDown" class="w-4 h-4 text-theme-text-muted" />
        </button>

        <form v-if="isOpen" @submit.prevent="submit" class="p-5 space-y-4">
            <div v-if="domainOptions.length === 0" class="text-xs text-theme-text-muted">
                No domains available to attach an FTP account. Create a domain first.
            </div>

            <template v-else>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <div>
                        <UiSelect
                            id="create_ftp_domain"
                            v-model="form.domain_id"
                            label="Domain"
                            :options="domainOptions"
                            :error="form.errors.domain_id"
                            required
                        />
                    </div>

                    <div>
                        <UiInput
                            id="create_ftp_username"
                            v-model="form.username"
                            type="text"
                            label="Username"
                            placeholder="e.g. deploy"
                            :error="form.errors.username"
                            required
                        />
                    </div>

                    <UiPasswordInput
                        id="create_ftp_password"
                        v-model="form.password"
                        label="Password"
                        :error="form.errors.password"
                        required
                    />

                    <div class="md:col-span-2">
                        <UiInput
                            id="create_ftp_home_dir"
                            v-model="form.home_directory"
                            type="text"
                            label="Home Directory (Relative to domain public_html)"
                            placeholder="Leave blank for domain root, or e.g. uploads"
                            :error="form.errors.home_directory"
                        />
                    </div>

                    <div>
                        <UiInput
                            id="create_ftp_quota"
                            v-model="form.disk_quota"
                            type="number"
                            min="0"
                            label="Disk Quota (MB — 0 = Unlimited)"
                            placeholder="0"
                            :error="form.errors.disk_quota"
                        />
                    </div>
                </div>

                <div class="flex items-center justify-end pt-2 border-t border-theme-border">
                    <UiButton type="submit" variant="primary" :disabled="form.processing" :loading="form.processing">
                        <UserPlus v-if="!form.processing" class="w-4 h-4 mr-1.5 inline" />
                        {{ form.processing ? 'Creating...' : 'Create FTP Account' }}
                    </UiButton>
                </div>
            </template>
        </form>
    </div>
</template>
