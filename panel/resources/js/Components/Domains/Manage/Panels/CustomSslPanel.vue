<script setup>
import { useForm } from '@inertiajs/vue3';
import { KeyRound, ShieldCheck, X } from '@lucide/vue';

const props = defineProps({
    domain: {
        type: Object,
        required: true,
    },
});

const emit = defineEmits(['close']);

const form = useForm({
    domain_id: props.domain.id,
    certificate: '',
    private_key: '',
});

const submitCustomSsl = () => {
    form.post('/security/ssl/custom', {
        preserveScroll: true,
        onSuccess: () => {
            emit('close');
        },
    });
};
</script>

<template>
    <div class="bg-theme-bg-card border border-theme-border rounded-xl p-5 shadow-sm space-y-5">
        <div class="flex items-center justify-between border-b border-theme-border pb-3">
            <div class="flex items-center gap-2">
                <KeyRound class="w-4 h-4 text-blue-500" />
                <h3 class="text-xs font-bold uppercase tracking-wider text-theme-text-primary">
                    Install Custom SSL Certificate
                </h3>
            </div>

            <button @click="emit('close')" class="text-theme-text-muted hover:text-theme-text-primary p-1">
                <X class="w-4 h-4" />
            </button>
        </div>

        <form @submit.prevent="submitCustomSsl" class="space-y-4">
            <div>
                <label class="block text-xs font-bold uppercase text-theme-text-secondary mb-1">
                    Certificate (CRT / PEM)
                </label>
                <textarea
                    v-model="form.certificate"
                    rows="6"
                    required
                    placeholder="-----BEGIN CERTIFICATE-----&#10;...&#10;-----END CERTIFICATE-----"
                    class="w-full p-3 bg-slate-950 border border-theme-border rounded-lg font-mono text-xs text-slate-200 focus:outline-none focus:border-blue-500"
                    spellcheck="false"
                ></textarea>
                <span v-if="form.errors.certificate" class="text-rose-400 text-[11px] mt-1 block">
                    {{ form.errors.certificate }}
                </span>
            </div>

            <div>
                <label class="block text-xs font-bold uppercase text-theme-text-secondary mb-1">
                    Private Key (KEY)
                </label>
                <textarea
                    v-model="form.private_key"
                    rows="6"
                    required
                    placeholder="-----BEGIN RSA/EC PRIVATE KEY-----&#10;...&#10;-----END PRIVATE KEY-----"
                    class="w-full p-3 bg-slate-950 border border-theme-border rounded-lg font-mono text-xs text-slate-200 focus:outline-none focus:border-blue-500"
                    spellcheck="false"
                ></textarea>
                <span v-if="form.errors.private_key" class="text-rose-400 text-[11px] mt-1 block">
                    {{ form.errors.private_key }}
                </span>
            </div>

            <div class="flex items-center gap-3 pt-2">
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="inline-flex items-center gap-1.5 px-5 py-2.5 bg-blue-600 hover:bg-blue-500 text-white text-xs font-bold rounded-lg shadow-sm shadow-blue-600/30 transition-all disabled:opacity-50"
                >
                    <ShieldCheck class="w-3.5 h-3.5" />
                    <span>{{ form.processing ? 'Validating & Installing...' : 'Install Certificate' }}</span>
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

