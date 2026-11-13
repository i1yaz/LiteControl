<script setup lang="ts">
import { shallowRef } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Cpu, Check, X } from '@lucide/vue';

const props = defineProps({
    domain: {
        type: Object,
        required: true,
    },
    installedPhpVersions: {
        type: Array,
        default: () => [],
    },
});

const emit = defineEmits(['close']);

const form = useForm({
    php_version: props.domain.php_version || '83',
});

const defaultPhpList = [
    { version: '74', active: true },
    { version: '80', active: true },
    { version: '81', active: true },
    { version: '82', active: true },
    { version: '83', active: true },
    { version: '84', active: true },
    { version: '85', active: true },
];

const availableVersions = props.installedPhpVersions.length > 0 ? props.installedPhpVersions : defaultPhpList;

const submitChange = () => {
    form.post(`/domains/${props.domain.id}/php`, {
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
                <Cpu class="w-4 h-4 text-emerald-400" />
                <h3 class="text-xs font-bold uppercase tracking-wider text-theme-text-primary">
                    Switch PHP Runtime for {{ domain.domain }}
                </h3>
            </div>

            <button @click="emit('close')" class="text-theme-text-muted hover:text-theme-text-primary p-1">
                <X class="w-4 h-4" />
            </button>
        </div>

        <form @submit.prevent="submitChange" class="space-y-4 max-w-md">
            <div>
                <label class="block text-xs font-bold uppercase text-theme-text-secondary mb-1.5">
                    Select PHP Version
                </label>

                <select
                    v-model="form.php_version"
                    class="w-full px-3 py-2 bg-theme-bg-surface border border-theme-border rounded-lg text-xs text-theme-text-primary focus:outline-none focus:border-blue-500 transition-colors"
                >
                    <option
                        v-for="ver in availableVersions"
                        :key="ver.version"
                        :value="ver.version"
                    >
                        PHP {{ ver.version.charAt(0) }}.{{ ver.version.slice(1) }} (lsphp{{ ver.version }})
                    </option>
                </select>
                <span v-if="form.errors.php_version" class="text-rose-400 text-[11px] mt-1 block">
                    {{ form.errors.php_version }}
                </span>
            </div>

            <p class="text-xs text-theme-text-secondary">
                Updates OpenLiteSpeed External Application Handler for this VirtualHost with zero downtime via graceful worker reload.
            </p>

            <div class="flex items-center gap-3 pt-2">
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="inline-flex items-center gap-1.5 px-4 py-2 bg-blue-600 hover:bg-blue-500 text-white text-xs font-semibold rounded-lg shadow-sm transition-colors disabled:opacity-50"
                >
                    <Check class="w-3.5 h-3.5" />
                    <span>{{ form.processing ? 'Switching...' : 'Apply PHP Version' }}</span>
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

