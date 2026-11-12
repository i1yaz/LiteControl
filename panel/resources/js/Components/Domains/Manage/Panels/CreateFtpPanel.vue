<script setup>
import { useForm } from '@inertiajs/vue3';
import { UserPlus, Check, X } from '@lucide/vue';

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
    <div class="bg-theme-bg-card border border-theme-border rounded-xl p-5 shadow-sm space-y-5">
        <div class="flex items-center justify-between border-b border-theme-border pb-3">
            <div class="flex items-center gap-2">
                <UserPlus class="w-4 h-4 text-cyan-400" />
                <h3 class="text-xs font-bold uppercase tracking-wider text-theme-text-primary">
                    Create Pure-FTPd Account for {{ domain.domain }}
                </h3>
            </div>

            <button @click="emit('close')" class="text-theme-text-muted hover:text-theme-text-primary p-1">
                <X class="w-4 h-4" />
            </button>
        </div>

        <form @submit.prevent="submitFtp" class="space-y-4 max-w-lg">
            <div>
                <label class="block text-xs font-bold uppercase text-theme-text-secondary mb-1">
                    FTP Username
                </label>
                <div class="flex items-center">
                    <span class="px-3 py-2 bg-theme-bg-surface border border-r-0 border-theme-border rounded-l-lg text-xs font-mono text-theme-text-muted">
                        {{ domain.user?.username }}_
                    </span>
                    <input
                        v-model="form.username"
                        type="text"
                        required
                        placeholder="webuser"
                        class="w-full px-3 py-2 bg-theme-bg-surface border border-theme-border rounded-r-lg text-xs text-theme-text-primary focus:outline-none focus:border-blue-500"
                    />
                </div>
                <span v-if="form.errors.username" class="text-rose-400 text-[11px] mt-1 block">
                    {{ form.errors.username }}
                </span>
            </div>

            <div>
                <label class="block text-xs font-bold uppercase text-theme-text-secondary mb-1">
                    FTP Password
                </label>
                <input
                    v-model="form.password"
                    type="password"
                    required
                    placeholder="••••••••••••"
                    class="w-full px-3 py-2 bg-theme-bg-surface border border-theme-border rounded-lg text-xs text-theme-text-primary focus:outline-none focus:border-blue-500"
                />
                <span v-if="form.errors.password" class="text-rose-400 text-[11px] mt-1 block">
                    {{ form.errors.password }}
                </span>
            </div>

            <div>
                <label class="block text-xs font-bold uppercase text-theme-text-secondary mb-1">
                    Home Directory (Relative to Website Root)
                </label>
                <input
                    v-model="form.dir"
                    type="text"
                    placeholder="public_html (or leave blank for default)"
                    class="w-full px-3 py-2 bg-theme-bg-surface border border-theme-border rounded-lg text-xs text-theme-text-primary focus:outline-none focus:border-blue-500"
                />
            </div>

            <div>
                <label class="block text-xs font-bold uppercase text-theme-text-secondary mb-1">
                    Disk Quota (MB)
                </label>
                <input
                    v-model="form.quota_mb"
                    type="number"
                    min="0"
                    placeholder="0 for unlimited"
                    class="w-full px-3 py-2 bg-theme-bg-surface border border-theme-border rounded-lg text-xs text-theme-text-primary focus:outline-none focus:border-blue-500"
                />
            </div>

            <div class="flex items-center gap-3 pt-2">
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="inline-flex items-center gap-1.5 px-5 py-2.5 bg-blue-600 hover:bg-blue-500 text-white text-xs font-bold rounded-lg shadow-sm shadow-blue-600/30 transition-all disabled:opacity-50"
                >
                    <Check class="w-3.5 h-3.5" />
                    <span>{{ form.processing ? 'Creating...' : 'Create Account' }}</span>
                </button>

                <button
                    type="button"
                    @click="emit('close')"
                    class="px-4 py-2 rounded-lg bg-theme-bg-surface hover:bg-theme-border text-xs font-semibold text-theme-text-secondary hover:text-theme-text-primary border border-theme-border transition-colors"
                >
                    Cancel
                </button>
            </div>
        </form>
    </div>
</template>

