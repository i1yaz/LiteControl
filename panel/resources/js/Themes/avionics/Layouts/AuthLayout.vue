<script setup lang="ts">
import { Cpu } from '@lucide/vue';
</script>

<template>
    <div class="min-h-screen bg-theme-bg-body text-theme-text-primary font-sans flex flex-col justify-center py-6 sm:px-6 lg:px-8 relative overflow-hidden selection:bg-theme-accent selection:text-theme-text-on-accent">
        <div class="sm:mx-auto sm:w-full sm:max-w-md relative z-10 text-center">
            <!-- Brand Architecture -->
            <div class="inline-flex items-center justify-center gap-3 mb-2">
                <div class="h-10 w-10 rounded-theme-sm bg-theme-bg-surface border border-theme-border-strong flex items-center justify-center text-theme-accent shadow-md">
                    <Cpu class="w-5 h-5" />
                </div>
                <div class="flex flex-col text-left">
                    <span class="text-base font-black tracking-tight text-theme-text-primary font-mono uppercase">
                        Lite<span class="text-theme-accent">{{ __('Control') }}</span>
                    </span>
                    <span class="text-[9px] font-mono tracking-widest text-theme-text-muted uppercase">
                        Avionics & Telemetry Hub
                    </span>
                </div>
            </div>

            <div class="flex items-center justify-center gap-2 mt-1">
                <span class="text-[10px] font-mono tracking-widest uppercase text-theme-accent">{{ __('Access Security') }}</span>
                <span class="text-theme-text-muted font-mono">/</span>
                <span class="text-[10px] font-mono text-theme-text-secondary">{{ __('AUTH_TERMINAL_GATEWAY') }}</span>
            </div>
        </div>

        <div class="mt-6 sm:mx-auto sm:w-full sm:max-w-md relative z-10 px-4">
            <div class="bg-theme-bg-card border border-theme-border shadow-2xl rounded-theme-sm overflow-hidden p-6 sm:p-7 relative">
                <slot />
            </div>

            <!-- Footer Meta -->
            <div class="text-center mt-5 text-[10px] font-mono text-theme-text-muted">
                LiteControl v1.0.0 // Avionics Engine
            </div>
        </div>
    </div>
</template>
