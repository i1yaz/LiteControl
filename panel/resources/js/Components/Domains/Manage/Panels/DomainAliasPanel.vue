<script setup>
import { shallowRef } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import { Globe, Plus, Trash2, X } from 'lucide-vue-next';

const props = defineProps({
    domain: {
        type: Object,
        required: true,
    },
});

const emit = defineEmits(['close']);

const form = useForm({
    alias: '',
});

const submitAlias = () => {
    form.post(`/domains/${props.domain.id}/aliases`, {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
        },
    });
};

const deleteAlias = (alias) => {
    if (confirm(`Remove domain alias '${alias.domain}'?`)) {
        router.delete(`/domains/${props.domain.id}/aliases/${alias.id}`, {
            preserveScroll: true,
        });
    }
};
</script>

<template>
    <div class="bg-theme-bg-card border border-theme-border rounded-xl p-5 shadow-sm space-y-5">
        <div class="flex items-center justify-between border-b border-theme-border pb-3">
            <div class="flex items-center gap-2">
                <Globe class="w-4 h-4 text-blue-500" />
                <h3 class="text-xs font-bold uppercase tracking-wider text-theme-text-primary">
                    Domain Aliases for {{ domain.domain }}
                </h3>
            </div>
            <button @click="emit('close')" class="text-theme-text-muted hover:text-theme-text-primary p-1">
                <X class="w-4 h-4" />
            </button>
        </div>

        <!-- Add Alias Form -->
        <form @submit.prevent="submitAlias" class="flex flex-col sm:flex-row gap-3">
            <div class="flex-1">
                <input
                    v-model="form.alias"
                    type="text"
                    placeholder="alias.example.com"
                    required
                    class="w-full px-3.5 py-2 bg-theme-bg-surface border border-theme-border rounded-lg text-xs text-theme-text-primary placeholder:text-theme-text-muted focus:outline-none focus:border-blue-500 transition-colors"
                />
                <span v-if="form.errors.alias" class="text-rose-400 text-[11px] mt-1 block">
                    {{ form.errors.alias }}
                </span>
            </div>

            <button
                type="submit"
                :disabled="form.processing"
                class="inline-flex items-center justify-center gap-1.5 px-4 py-2 bg-blue-600 hover:bg-blue-500 text-white text-xs font-semibold rounded-lg shadow-sm transition-colors disabled:opacity-50"
            >
                <Plus class="w-3.5 h-3.5" />
                <span>{{ form.processing ? 'Adding...' : 'Add Alias' }}</span>
            </button>
        </form>

        <!-- Existing Aliases List -->
        <div class="space-y-2 pt-2">
            <h4 class="text-[11px] font-bold uppercase text-theme-text-secondary tracking-wider">
                Configured Aliases ({{ domain.aliases?.length || 0 }})
            </h4>

            <div v-if="domain.aliases && domain.aliases.length > 0" class="divide-y divide-theme-border border border-theme-border rounded-lg overflow-hidden">
                <div
                    v-for="al in domain.aliases"
                    :key="al.id"
                    class="p-3 bg-theme-bg-surface/40 flex items-center justify-between text-xs hover:bg-theme-bg-surface/80 transition-colors"
                >
                    <div class="flex items-center gap-2">
                        <span class="w-1.5 h-1.5 rounded-full bg-blue-500"></span>
                        <span class="font-mono text-theme-text-primary font-medium">{{ al.domain }}</span>
                    </div>

                    <button
                        @click="deleteAlias(al)"
                        class="text-theme-text-muted hover:text-rose-400 p-1 transition-colors"
                        title="Remove Alias"
                    >
                        <Trash2 class="w-4 h-4" />
                    </button>
                </div>
            </div>
            <div v-else class="text-xs text-theme-text-muted py-3 italic">
                No domain aliases configured.
            </div>
        </div>
    </div>
</template>

