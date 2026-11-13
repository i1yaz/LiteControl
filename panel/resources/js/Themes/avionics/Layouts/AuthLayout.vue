<script setup lang="ts">
import { Cpu } from '@lucide/vue';
</script>

<template>
    <div class="min-h-screen bg-[#080a0e] text-[#cad3e0] font-sans flex flex-col justify-center py-6 sm:px-6 lg:px-8 relative overflow-hidden selection:bg-[#ff6b35] selection:text-black">
        <!-- Subtle tactical grid and avionics glow -->
        <div class="absolute inset-0 bg-[linear-gradient(to_right,#161c26_1px,transparent_1px),linear-gradient(to_bottom,#161c26_1px,transparent_1px)] bg-[size:4rem_4rem] [mask-image:radial-gradient(ellipse_60%_50%_at_50%_40%,#000_70%,transparent_100%)] opacity-25 pointer-events-none"></div>
        <div class="absolute top-1/4 left-1/2 -translate-x-1/2 -translate-y-1/2 w-96 h-96 bg-[#ff6b35]/10 rounded-full blur-3xl pointer-events-none"></div>

        <div class="sm:mx-auto sm:w-full sm:max-w-md relative z-10 text-center">
            <!-- Brand Architecture -->
            <div class="inline-flex items-center justify-center gap-3 mb-2">
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

            <div class="flex items-center justify-center gap-2 mt-1">
                <span class="text-[10px] font-mono tracking-widest uppercase text-[#ff6b35]">Access Security</span>
                <span class="text-[#3a4454] font-mono">/</span>
                <span class="text-[10px] font-mono text-[#6c7d93]">AUTH_TERMINAL_GATEWAY</span>
            </div>
        </div>

        <div class="mt-6 sm:mx-auto sm:w-full sm:max-w-md relative z-10 px-4">
            <div class="bg-[#0c1017] border border-[#1e2634] shadow-[0_20px_50px_rgba(0,0,0,0.8)] rounded-sm overflow-hidden p-6 sm:p-7 relative before:absolute before:top-0 before:left-0 before:w-16 before:h-[2px] before:bg-[#ff6b35]">
                <slot />
            </div>

            <!-- Footer Meta -->
            <div class="text-center mt-5 text-[10px] font-mono text-[#445063]">
                LiteControl v1.0.0 // Avionics Engine
            </div>
        </div>
    </div>
</template>
