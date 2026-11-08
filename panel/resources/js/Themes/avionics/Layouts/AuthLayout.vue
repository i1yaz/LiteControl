<script setup>
import { Cpu } from 'lucide-vue-next';
</script>

<template>
    <div class="min-h-screen bg-[#080a0e] text-[#d6dce7] flex flex-col justify-center py-12 sm:px-6 lg:px-8 font-sans selection:bg-[#ff6b35] selection:text-black">
        <div class="sm:mx-auto sm:w-full sm:max-w-md text-center">
            <div class="inline-flex items-center justify-center h-12 w-12 rounded-sm bg-[#161a23] border border-[#262f3e] text-[#ff6b35] mb-4">
                <Cpu class="w-6 h-6" />
            </div>
            <h1 class="text-xl font-bold tracking-tight text-white font-mono uppercase">
                Lite<span class="text-[#ff6b35]">Control</span>
            </h1>
            <p class="text-[10px] font-mono text-[#586477] uppercase tracking-widest mt-1">
                OLS Telemetry & Infrastructure Core
            </p>
        </div>

        <div class="mt-8 sm:mx-auto sm:w-full sm:max-w-md">
            <div class="bg-[#0c1017] border border-[#1e2634] py-8 px-6 sm:px-10 rounded-sm shadow-2xl">
                <slot />
            </div>
        </div>
    </div>
</template>
