<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import PanelLayout from '@/Layouts/PanelLayout.vue';
import { Sliders, Save, Server, Globe, Mail, Clock, ShieldCheck, CheckCircle2 } from 'lucide-vue-next';

const props = defineProps({
    settings: Object,
    stats: Object,
});

const form = useForm({
    panel_name: props.settings.panel_name || 'LiteControl',
    admin_email: props.settings.admin_email || '',
    timezone: props.settings.timezone || 'UTC',
    default_php: props.settings.default_php || '83',
});

const submit = () => {
    form.post('/configuration/settings');
};
</script>

<template>
    <PanelLayout>
        <Head title="Panel Settings" />

        <div class="max-w-4xl mx-auto space-y-6">
            <!-- Header -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 class="text-xl sm:text-2xl font-bold text-white flex items-center gap-2.5">
                        <Sliders class="w-6 h-6 text-sky-400" />
                        <span>System & Panel Settings</span>
                    </h1>
                    <p class="text-xs sm:text-sm text-slate-400 mt-1">
                        Global configuration parameters for LiteControl and OpenLiteSpeed web server.
                    </p>
                </div>
            </div>

            <!-- Server Quick Info Bar -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div class="p-4 rounded-xl bg-slate-900/90 border border-slate-800 flex items-center gap-3">
                    <div class="p-2.5 rounded-lg bg-sky-500/10 text-sky-400 border border-sky-500/20">
                        <Server class="w-5 h-5" />
                    </div>
                    <div>
                        <p class="text-[11px] font-medium text-slate-400 uppercase tracking-wider">Server IP</p>
                        <p class="text-sm font-semibold text-white">{{ settings.server_ip }}</p>
                    </div>
                </div>

                <div class="p-4 rounded-xl bg-slate-900/90 border border-slate-800 flex items-center gap-3">
                    <div class="p-2.5 rounded-lg bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                        <Globe class="w-5 h-5" />
                    </div>
                    <div>
                        <p class="text-[11px] font-medium text-slate-400 uppercase tracking-wider">Hostname</p>
                        <p class="text-sm font-semibold text-white">{{ settings.hostname }}</p>
                    </div>
                </div>

                <div class="p-4 rounded-xl bg-slate-900/90 border border-slate-800 flex items-center gap-3">
                    <div class="p-2.5 rounded-lg bg-purple-500/10 text-purple-400 border border-purple-500/20">
                        <ShieldCheck class="w-5 h-5" />
                    </div>
                    <div>
                        <p class="text-[11px] font-medium text-slate-400 uppercase tracking-wider">OpenLiteSpeed</p>
                        <p class="text-sm font-semibold text-white">{{ settings.ols_version }}</p>
                    </div>
                </div>
            </div>

            <!-- Settings Form Card -->
            <form @submit.prevent="submit" class="bg-slate-900 border border-slate-800 rounded-2xl p-6 sm:p-8 space-y-6">
                <div class="border-b border-slate-800 pb-4">
                    <h2 class="text-base font-semibold text-white">General Information</h2>
                    <p class="text-xs text-slate-400 mt-0.5">Control panel branding and administrator contact details.</p>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-xs font-medium text-slate-300 mb-1.5">Control Panel Name</label>
                        <input
                            v-model="form.panel_name"
                            type="text"
                            required
                            class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2.5 text-sm text-white focus:outline-none focus:border-sky-500 focus:ring-1 focus:ring-sky-500 transition-colors"
                        />
                        <p v-if="form.errors.panel_name" class="text-xs text-rose-400 mt-1">{{ form.errors.panel_name }}</p>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-300 mb-1.5">Administrator Email</label>
                        <input
                            v-model="form.admin_email"
                            type="email"
                            required
                            class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2.5 text-sm text-white focus:outline-none focus:border-sky-500 focus:ring-1 focus:ring-sky-500 transition-colors"
                        />
                        <p v-if="form.errors.admin_email" class="text-xs text-rose-400 mt-1">{{ form.errors.admin_email }}</p>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-300 mb-1.5">System Timezone</label>
                        <input
                            v-model="form.timezone"
                            type="text"
                            required
                            class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2.5 text-sm text-white focus:outline-none focus:border-sky-500 focus:ring-1 focus:ring-sky-500 transition-colors"
                        />
                        <p v-if="form.errors.timezone" class="text-xs text-rose-400 mt-1">{{ form.errors.timezone }}</p>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-300 mb-1.5">Default PHP Version</label>
                        <select
                            v-model="form.default_php"
                            class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2.5 text-sm text-white focus:outline-none focus:border-sky-500 focus:ring-1 focus:ring-sky-500 transition-colors"
                        >
                            <option value="74">PHP 7.4 (Legacy)</option>
                            <option value="80">PHP 8.0</option>
                            <option value="81">PHP 8.1</option>
                            <option value="82">PHP 8.2</option>
                            <option value="83">PHP 8.3 (Recommended Core)</option>
                            <option value="84">PHP 8.4</option>
                            <option value="85">PHP 8.5 (Latest)</option>
                        </select>
                        <p v-if="form.errors.default_php" class="text-xs text-rose-400 mt-1">{{ form.errors.default_php }}</p>
                    </div>
                </div>

                <div class="pt-4 border-t border-slate-800 flex items-center justify-end">
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="px-5 py-2.5 rounded-xl bg-sky-600 hover:bg-sky-500 text-white font-medium text-sm flex items-center gap-2 shadow-lg shadow-sky-600/20 disabled:opacity-50 transition-colors"
                    >
                        <Save class="w-4 h-4" />
                        <span>{{ form.processing ? 'Saving...' : 'Save Settings' }}</span>
                    </button>
                </div>
            </form>
        </div>
    </PanelLayout>
</template>

