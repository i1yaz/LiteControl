<script setup lang="ts">
import { useForm, Head } from '@inertiajs/vue3';
import { Lock, User as UserIcon, ShieldCheck } from '@lucide/vue';

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

    <form class="space-y-4 font-mono text-xs" @submit.prevent="submit">
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
</template>
