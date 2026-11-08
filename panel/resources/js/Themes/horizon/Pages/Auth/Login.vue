<script setup>
import { useForm, Head } from '@inertiajs/vue3';
import { Globe, Lock, User as UserIcon, ShieldCheck } from 'lucide-vue-next';

defineProps({
    errors: Object,
});

const form = useForm({
    username: '',
    password: '',
    remember: false,
});

const submit = () => {
    form.post('/login', {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <Head title="Sign In - LiteControl" />

    <div class="min-h-screen bg-slate-950 text-slate-100 font-sans flex flex-col justify-center py-12 sm:px-6 lg:px-8 relative selection:bg-sky-500 selection:text-white">
        <!-- Subtle modern background accent -->
        <div class="absolute inset-0 bg-[radial-gradient(ellipse_at_top,_var(--tw-gradient-stops))] from-sky-900/20 via-slate-950 to-slate-950 pointer-events-none"></div>

        <div class="sm:mx-auto sm:w-full sm:max-w-md relative z-10 text-center">
            <div class="inline-flex items-center justify-center h-12 w-12 rounded-2xl bg-gradient-to-tr from-sky-600 to-indigo-600 text-white shadow-lg shadow-sky-500/25 mb-4">
                <Globe class="w-6 h-6" />
            </div>
            <h1 class="text-2xl font-bold tracking-tight text-white">
                Lite<span class="text-sky-400">Control</span>
            </h1>
            <p class="text-xs text-slate-400 mt-1">
                Enter your credentials to access the management portal
            </p>
        </div>

        <div class="mt-8 sm:mx-auto sm:w-full sm:max-w-md relative z-10 px-4">
            <div class="bg-slate-900/90 border border-slate-800 shadow-2xl rounded-2xl p-6 sm:p-8 backdrop-blur">
                <form class="space-y-5 text-sm" @submit.prevent="submit">
                    <div v-if="form.errors.username" class="p-3.5 rounded-xl bg-rose-500/10 border border-rose-500/20 text-rose-300 text-xs">
                        {{ form.errors.username }}
                    </div>

                    <div class="space-y-1.5">
                        <label for="username" class="block text-xs font-medium text-slate-300">
                            Username or Email
                        </label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-500">
                                <UserIcon class="h-4 w-4" />
                            </div>
                            <input
                                id="username"
                                v-model="form.username"
                                type="text"
                                autocomplete="username"
                                required
                                placeholder="admin"
                                class="w-full pl-10 pr-3.5 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-slate-100 placeholder-slate-500 text-sm focus:outline-none focus:border-sky-500 focus:ring-2 focus:ring-sky-500/20 transition-all"
                            />
                        </div>
                    </div>

                    <div class="space-y-1.5">
                        <label for="password" class="block text-xs font-medium text-slate-300">
                            Password
                        </label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-500">
                                <Lock class="h-4 w-4" />
                            </div>
                            <input
                                id="password"
                                v-model="form.password"
                                type="password"
                                autocomplete="current-password"
                                required
                                placeholder="••••••••••••"
                                class="w-full pl-10 pr-3.5 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-slate-100 placeholder-slate-500 text-sm focus:outline-none focus:border-sky-500 focus:ring-2 focus:ring-sky-500/20 transition-all"
                            />
                        </div>
                    </div>

                    <div class="flex items-center justify-between text-xs">
                        <label class="flex items-center gap-2 cursor-pointer select-none text-slate-400">
                            <input
                                v-model="form.remember"
                                type="checkbox"
                                class="rounded bg-slate-950 border-slate-800 text-sky-500 focus:ring-0 focus:ring-offset-0 h-4 w-4"
                            />
                            <span>Remember this device</span>
                        </label>
                    </div>

                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="w-full py-2.5 px-4 rounded-xl bg-sky-600 hover:bg-sky-500 disabled:opacity-50 text-white text-sm font-semibold tracking-wide transition-all shadow-md shadow-sky-500/20 flex items-center justify-center gap-2 cursor-pointer"
                    >
                        <ShieldCheck class="w-4 h-4" />
                        <span>Sign In</span>
                    </button>
                </form>
            </div>

            <!-- Footer Meta -->
            <div class="text-center mt-6 text-xs text-slate-500">
                LiteControl Platform v1.0.0
            </div>
        </div>
    </div>
</template>
