<script setup>
import { useForm, Head } from '@inertiajs/vue3';
import { Cpu, Lock, User as UserIcon, ShieldCheck } from 'lucide-vue-next';

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

    <div class="min-h-screen bg-[#080a0e] text-[#cad3e0] font-sans flex flex-col justify-center py-12 sm:px-6 lg:px-8 relative overflow-hidden selection:bg-[#ff6b35] selection:text-black">
        <!-- Subtle tactical grid and avionics glow -->
        <div class="absolute inset-0 bg-[linear-gradient(to_right,#161c26_1px,transparent_1px),linear-gradient(to_bottom,#161c26_1px,transparent_1px)] bg-[size:4rem_4rem] [mask-image:radial-gradient(ellipse_60%_50%_at_50%_40%,#000_70%,transparent_100%)] opacity-25 pointer-events-none"></div>
        <div class="absolute top-1/4 left-1/2 -translate-x-1/2 -translate-y-1/2 w-96 h-96 bg-[#ff6b35]/10 rounded-full blur-3xl pointer-events-none"></div>

        <div class="sm:mx-auto sm:w-full sm:max-w-md relative z-10 text-center">
            <!-- Brand Architecture -->
            <div class="inline-flex items-center justify-center gap-3 mb-3">
                <div class="h-10 w-10 rounded-xs bg-[#161a23] border border-[#262f3e] flex items-center justify-center text-[#ff6b35] shadow-[0_0_20px_rgba(255,107,53,0.15)]">
                    <Cpu class="w-5 h-5" />
                </div>
                <div class="flex flex-col text-left">
                    <span class="text-base font-black tracking-tight text-white font-mono uppercase">
                        Lite<span class="text-[#ff6b35]">Control</span>
                    </span>
                    <span class="text-[9px] font-mono tracking-widest text-[#6c7d93] uppercase">
                        Avionics & Telemetry Hub
                    </span>
                </div>
            </div>

            <div class="flex items-center justify-center gap-2 mt-2">
                <span class="text-[10px] font-mono tracking-widest uppercase text-[#ff6b35]">Access Security</span>
                <span class="text-[#3a4454] font-mono">/</span>
                <span class="text-[10px] font-mono text-[#6c7d93]">AUTH_TERMINAL_GATEWAY</span>
            </div>
        </div>

        <div class="mt-6 sm:mx-auto sm:w-full sm:max-w-md relative z-10 px-4">
            <div class="bg-[#0c1017] border border-[#1e2634] shadow-[0_20px_50px_rgba(0,0,0,0.8)] rounded-sm overflow-hidden p-6 sm:p-8 relative before:absolute before:top-0 before:left-0 before:w-16 before:h-[2px] before:bg-[#ff6b35]">
                <form class="space-y-5 font-mono text-xs" @submit.prevent="submit">
                    <div v-if="form.errors.username" class="p-3 rounded-xs bg-[#ff3366]/10 border border-[#ff3366]/30 text-[#ff6685] text-[11px]">
                        {{ form.errors.username }}
                    </div>

                    <div>
                        <label for="username" class="block text-[10px] uppercase tracking-wider text-[#7a8b9f] mb-1.5">
                            Principal Identity (Username or Email)
                        </label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-[#5a6b80]">
                                <UserIcon class="h-4 w-4" />
                            </div>
                            <input
                                id="username"
                                v-model="form.username"
                                type="text"
                                autocomplete="username"
                                required
                                placeholder="admin"
                                class="w-full pl-9 pr-3 py-2 bg-[#080b0f] border border-[#1e2634] rounded-xs text-[#cad3e0] placeholder-[#4e5c6e] text-xs focus:outline-none focus:border-[#ff6b35] transition-colors"
                            />
                        </div>
                    </div>

                    <div>
                        <label for="password" class="block text-[10px] uppercase tracking-wider text-[#7a8b9f] mb-1.5">
                            Passphrase Hash Key
                        </label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-[#5a6b80]">
                                <Lock class="h-4 w-4" />
                            </div>
                            <input
                                id="password"
                                v-model="form.password"
                                type="password"
                                autocomplete="current-password"
                                required
                                placeholder="••••••••••••"
                                class="w-full pl-9 pr-3 py-2 bg-[#080b0f] border border-[#1e2634] rounded-xs text-[#cad3e0] placeholder-[#4e5c6e] text-xs focus:outline-none focus:border-[#ff6b35] transition-colors"
                            />
                        </div>
                    </div>

                    <div class="flex items-center justify-between text-[11px]">
                        <label class="flex items-center gap-2 cursor-pointer select-none text-[#8d9cb0]">
                            <input
                                v-model="form.remember"
                                type="checkbox"
                                class="rounded-xs bg-[#080b0f] border-[#222c3d] text-[#ff6b35] focus:ring-0 focus:ring-offset-0 h-3.5 w-3.5"
                            />
                            <span>Persist Session Token</span>
                        </label>
                    </div>

                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="w-full py-2.5 px-4 rounded-xs bg-[#ff6b35] hover:bg-[#ff8252] disabled:opacity-50 text-black text-xs font-mono font-bold uppercase tracking-wider transition-all duration-150 flex items-center justify-center gap-2 shadow-[0_0_20px_rgba(255,107,53,0.3)] hover:shadow-[0_0_25px_rgba(255,107,53,0.5)] cursor-pointer"
                    >
                        <ShieldCheck class="w-4 h-4" />
                        <span>Authenticate Session</span>
                    </button>
                </form>
            </div>

            <!-- Footer Meta -->
            <div class="text-center mt-6 text-[10px] font-mono text-[#445063]">
                LiteControl v1.0.0 // Avionics Engine
            </div>
        </div>
    </div>
</template>
