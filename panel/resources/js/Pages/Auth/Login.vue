<script setup>
import { useForm, Head } from '@inertiajs/vue3';
import { Server, Lock, User as UserIcon, ShieldCheck } from 'lucide-vue-next';

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

    <div class="min-h-screen bg-slate-950 flex flex-col justify-center py-12 sm:px-6 lg:px-8 relative overflow-hidden">
        <!-- Ambient background glow -->
        <div class="absolute -top-40 -right-40 w-96 h-96 bg-indigo-600/20 rounded-full blur-3xl pointer-events-none"></div>
        <div class="absolute -bottom-40 -left-40 w-96 h-96 bg-blue-600/20 rounded-full blur-3xl pointer-events-none"></div>

        <div class="sm:mx-auto sm:w-full sm:max-w-md relative z-10">
            <div class="flex justify-center items-center gap-3">
                <div class="h-12 w-12 rounded-xl bg-gradient-to-tr from-indigo-600 to-violet-500 flex items-center justify-center shadow-lg shadow-indigo-500/30 text-white">
                    <Server class="w-6 h-6" />
                </div>
                <div class="flex flex-col">
                    <span class="text-2xl font-bold tracking-tight text-white flex items-center gap-1.5">
                        Lite<span class="text-indigo-400">Control</span>
                    </span>
                    <span class="text-xs uppercase tracking-widest text-slate-400 font-semibold">Web Hosting Panel</span>
                </div>
            </div>
            <h2 class="mt-6 text-center text-xl font-semibold tracking-tight text-slate-200">
                Sign in to your server
            </h2>
            <p class="mt-1 text-center text-xs text-slate-400">
                OpenLiteSpeed Powered Hosting Platform
            </p>
        </div>

        <div class="mt-8 sm:mx-auto sm:w-full sm:max-w-md relative z-10 px-4">
            <div class="bg-slate-900/90 backdrop-blur-xl py-8 px-6 shadow-2xl ring-1 ring-white/10 rounded-2xl sm:px-10">
                <form class="space-y-6" @submit.prevent="submit">
                    <div v-if="form.errors.username" class="p-3 rounded-lg bg-rose-500/10 border border-rose-500/20 text-rose-400 text-xs font-medium">
                        {{ form.errors.username }}
                    </div>

                    <div>
                        <label for="username" class="block text-xs font-semibold text-slate-300 uppercase tracking-wider">
                            Username or Email
                        </label>
                        <div class="mt-2 relative rounded-md shadow-sm">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-slate-400">
                                <UserIcon class="h-4 w-4" />
                            </div>
                            <input
                                id="username"
                                v-model="form.username"
                                type="text"
                                autocomplete="username"
                                required
                                placeholder="admin or client1"
                                class="block w-full pl-10 pr-3 py-2.5 bg-slate-950/60 border border-slate-700/80 rounded-lg text-slate-100 placeholder-slate-500 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition-all"
                            />
                        </div>
                    </div>

                    <div>
                        <label for="password" class="block text-xs font-semibold text-slate-300 uppercase tracking-wider">
                            Password
                        </label>
                        <div class="mt-2 relative rounded-md shadow-sm">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-slate-400">
                                <Lock class="h-4 w-4" />
                            </div>
                            <input
                                id="password"
                                v-model="form.password"
                                type="password"
                                autocomplete="current-password"
                                required
                                placeholder="••••••••••••"
                                class="block w-full pl-10 pr-3 py-2.5 bg-slate-950/60 border border-slate-700/80 rounded-lg text-slate-100 placeholder-slate-500 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition-all"
                            />
                        </div>
                    </div>

                    <div class="flex items-center justify-between">
                        <div class="flex items-center">
                            <input
                                id="remember"
                                v-model="form.remember"
                                type="checkbox"
                                class="h-4 w-4 rounded bg-slate-950 border-slate-700 text-indigo-600 focus:ring-indigo-500/30 focus:ring-offset-0"
                            />
                            <label for="remember" class="ml-2 block text-xs text-slate-400">
                                Remember this session
                            </label>
                        </div>
                        <div class="text-xs">
                            <span class="text-slate-500 cursor-not-allowed">Forgot password?</span>
                        </div>
                    </div>

                    <div>
                        <button
                            type="submit"
                            :disabled="form.processing"
                            class="w-full flex justify-center items-center gap-2 py-2.5 px-4 rounded-lg shadow-md shadow-indigo-600/30 text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-500 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 disabled:opacity-50 transition-all"
                        >
                            <ShieldCheck class="w-4 h-4" />
                            <span v-if="form.processing">Authenticating...</span>
                            <span v-else>Sign In</span>
                        </button>
                    </div>
                </form>

                <div class="mt-6 pt-6 border-t border-slate-800 text-center text-xs text-slate-500">
                    Default credentials: <span class="text-slate-300 font-mono">admin / admin123456</span>
                </div>
            </div>
        </div>
    </div>
</template>

