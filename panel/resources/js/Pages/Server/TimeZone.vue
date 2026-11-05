<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import PanelLayout from '@/Layouts/PanelLayout.vue';
import { Clock, Save, Globe } from 'lucide-vue-next';

const props = defineProps({
    currentTimezone: String,
    popularZones: Array,
});

const form = useForm({
    timezone: props.currentTimezone || 'UTC',
});

const submit = () => {
    form.post('/server/timezone');
};
</script>

<template>
    <PanelLayout>
        <Head title="Server Time Zone" />

        <div class="max-w-3xl mx-auto space-y-6">
            <!-- Header -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 class="text-xl sm:text-2xl font-bold text-white flex items-center gap-2.5">
                        <Clock class="w-6 h-6 text-blue-400" />
                        <span>System Time Zone</span>
                    </h1>
                    <p class="text-xs sm:text-sm text-slate-400 mt-1">
                        Configure Linux host timezone and synchronize cron scheduled tasks.
                    </p>
                </div>
            </div>

            <!-- Current Timezone Banner -->
            <div class="p-5 rounded-2xl bg-slate-900 border border-slate-800 flex items-center justify-between">
                <div>
                    <p class="text-xs text-slate-400">Current Server Timezone</p>
                    <p class="text-lg font-bold text-white mt-0.5">{{ currentTimezone }}</p>
                </div>
                <div class="p-3 rounded-xl bg-blue-500/10 text-blue-400 border border-blue-500/20">
                    <Globe class="w-6 h-6" />
                </div>
            </div>

            <!-- Form -->
            <form @submit.prevent="submit" class="bg-slate-900 border border-slate-800 rounded-2xl p-6 sm:p-8 space-y-6">
                <div>
                    <label class="block text-xs font-medium text-slate-300 mb-1.5">Select Timezone</label>
                    <select
                        v-model="form.timezone"
                        class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2.5 text-sm text-white focus:outline-none focus:border-blue-500"
                    >
                        <option v-for="z in popularZones" :key="z" :value="z">{{ z }}</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-300 mb-1.5">Or Enter Custom Timezone Identifier</label>
                    <input
                        v-model="form.timezone"
                        type="text"
                        placeholder="e.g. Europe/Berlin"
                        class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2.5 text-sm text-white focus:outline-none focus:border-blue-500"
                    />
                    <p v-if="form.errors.timezone" class="text-xs text-rose-400 mt-1">{{ form.errors.timezone }}</p>
                </div>

                <div class="pt-4 border-t border-slate-800 flex items-center justify-end">
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="px-5 py-2.5 rounded-xl bg-blue-600 hover:bg-blue-500 text-white font-medium text-sm flex items-center gap-2 shadow-lg shadow-blue-600/20 disabled:opacity-50 transition-colors"
                    >
                        <Save class="w-4 h-4" />
                        <span>{{ form.processing ? 'Updating...' : 'Update Timezone' }}</span>
                    </button>
                </div>
            </form>
        </div>
    </PanelLayout>
</template>

