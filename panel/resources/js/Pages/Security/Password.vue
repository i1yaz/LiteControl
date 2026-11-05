<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import PanelLayout from '@/Layouts/PanelLayout.vue';
import { Lock, Save } from 'lucide-vue-next';

const form = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

const submit = () => {
    form.post('/security/password', {
        onSuccess: () => form.reset(),
    });
};
</script>

<template>
    <PanelLayout>
        <Head title="Change Password" />

        <div class="max-w-xl mx-auto space-y-6">
            <!-- Header -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 class="text-xl sm:text-2xl font-bold text-white flex items-center gap-2.5">
                        <Lock class="w-6 h-6 text-rose-400" />
                        <span>Change Account Password</span>
                    </h1>
                    <p class="text-xs sm:text-sm text-slate-400 mt-1">
                        Update your login credentials. Use a strong password containing at least 8 characters.
                    </p>
                </div>
            </div>

            <!-- Form -->
            <form @submit.prevent="submit" class="bg-slate-900 border border-slate-800 rounded-2xl p-6 sm:p-8 space-y-5">
                <div>
                    <label class="block text-xs font-medium text-slate-300 mb-1.5">Current Password</label>
                    <input
                        v-model="form.current_password"
                        type="password"
                        required
                        class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2.5 text-sm text-white focus:outline-none focus:border-rose-500"
                    />
                    <p v-if="form.errors.current_password" class="text-xs text-rose-400 mt-1">{{ form.errors.current_password }}</p>
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-300 mb-1.5">New Password</label>
                    <input
                        v-model="form.password"
                        type="password"
                        required
                        class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2.5 text-sm text-white focus:outline-none focus:border-rose-500"
                    />
                    <p v-if="form.errors.password" class="text-xs text-rose-400 mt-1">{{ form.errors.password }}</p>
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-300 mb-1.5">Confirm New Password</label>
                    <input
                        v-model="form.password_confirmation"
                        type="password"
                        required
                        class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2.5 text-sm text-white focus:outline-none focus:border-rose-500"
                    />
                </div>

                <div class="pt-4 border-t border-slate-800 flex items-center justify-end">
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="px-5 py-2.5 rounded-xl bg-rose-600 hover:bg-rose-500 text-white font-medium text-sm flex items-center gap-2 shadow-lg shadow-rose-600/20 disabled:opacity-50 transition-colors"
                    >
                        <Save class="w-4 h-4" />
                        <span>{{ form.processing ? 'Updating...' : 'Update Password' }}</span>
                    </button>
                </div>
            </form>
        </div>
    </PanelLayout>
</template>

