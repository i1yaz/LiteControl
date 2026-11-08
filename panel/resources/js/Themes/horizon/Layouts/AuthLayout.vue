<script setup>
import { Globe } from 'lucide-vue-next';
</script>

<template>
    <div class="min-h-screen bg-slate-950 text-slate-100 flex flex-col justify-center py-12 sm:px-6 lg:px-8 font-sans selection:bg-sky-500 selection:text-white">
        <div class="sm:mx-auto sm:w-full sm:max-w-md text-center">
            <div class="inline-flex items-center justify-center h-12 w-12 rounded-xl bg-sky-600/10 border border-sky-500/20 text-sky-400 mb-4 shadow-inner">
                <Globe class="w-6 h-6" />
            </div>
            <h1 class="text-2xl font-bold tracking-tight text-white">
                Lite<span class="text-sky-400">Control</span>
            </h1>
            <p class="text-xs text-slate-400 mt-1">
                Cloud Host & Server Management Platform
            </p>
        </div>

        <div class="mt-8 sm:mx-auto sm:w-full sm:max-w-md">
            <div class="bg-slate-900 border border-slate-800 py-8 px-6 sm:px-10 rounded-2xl shadow-xl backdrop-blur">
                <slot />
            </div>
        </div>
    </div>
</template>
