<script setup lang="ts">
import { Globe } from '@lucide/vue';
</script>

<template>
    <div class="min-h-screen bg-slate-950 text-slate-100 font-sans flex flex-col justify-center py-6 sm:px-6 lg:px-8 relative selection:bg-sky-500 selection:text-white">
        <!-- Subtle modern background accent -->
        <div class="absolute inset-0 bg-[radial-gradient(ellipse_at_top,_var(--tw-gradient-stops))] from-sky-900/20 via-slate-950 to-slate-950 pointer-events-none"></div>

        <div class="sm:mx-auto sm:w-full sm:max-w-md relative z-10 text-center">
            <div class="inline-flex items-center justify-center h-12 w-12 rounded-2xl bg-gradient-to-tr from-sky-600 to-indigo-600 text-white shadow-lg shadow-sky-500/25 mb-3">
                <Globe class="w-6 h-6" />
            </div>
            <h1 class="text-2xl font-bold tracking-tight text-white">
                Lite<span class="text-sky-400">Control</span>
            </h1>
            <p class="text-xs text-slate-400 mt-1">
                Enter your credentials to access the management portal
            </p>
        </div>

        <div class="mt-6 sm:mx-auto sm:w-full sm:max-w-md relative z-10 px-4">
            <div class="bg-slate-900/90 border border-slate-800 shadow-2xl rounded-2xl p-6 sm:p-7 backdrop-blur">
                <slot />
            </div>

            <!-- Footer Meta -->
            <div class="text-center mt-5 text-xs text-slate-500">
                LiteControl Platform v1.0.0
            </div>
        </div>
    </div>
</template>
