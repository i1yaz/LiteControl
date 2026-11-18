<script setup lang="ts">
import { Globe } from '@lucide/vue';
</script>

<template>
    <div class="min-h-screen bg-theme-bg-body text-theme-text-primary font-sans flex flex-col justify-center py-6 sm:px-6 lg:px-8 relative selection:bg-theme-accent selection:text-theme-text-on-accent">
        <div class="sm:mx-auto sm:w-full sm:max-w-md relative z-10 text-center">
            <div class="inline-flex items-center justify-center h-12 w-12 rounded-theme-lg bg-theme-accent text-theme-text-on-accent shadow-md mb-3">
                <Globe class="w-6 h-6" />
            </div>
            <h1 class="text-2xl font-bold tracking-tight text-theme-text-primary">
                Lite<span class="text-theme-accent">{{ __('Control') }}</span>
            </h1>
            <p class="text-xs text-theme-text-secondary mt-1">
                {{ __('Enter your credentials to access the management portal') }}
            </p>
        </div>

        <div class="mt-6 sm:mx-auto sm:w-full sm:max-w-md relative z-10 px-4">
            <div class="bg-theme-bg-card border border-theme-border shadow-lg rounded-theme-lg p-6 sm:p-7">
                <slot />
            </div>

            <!-- Footer Meta -->
            <div class="text-center mt-5 text-xs font-mono text-theme-text-muted">
                LiteControl Platform v1.0.0 · Cobalt Precision
            </div>
        </div>
    </div>
</template>
