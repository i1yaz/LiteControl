<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { RotateCw, Server, Database, Zap } from '@lucide/vue';
import { confirmDialog } from '@shared/Composables/useDialog';
import UiPageHeader from '@shared/Ui/UiPageHeader.vue';
import UiCard from '@shared/Ui/UiCard.vue';
import UiButton from '@shared/Ui/UiButton.vue';

const props = defineProps({
    stats: Object,
});

const form = useForm({
    action: 'ols_restart',
});

const execute = async (actionName) => {
    form.action = actionName;
    if (await confirmDialog({
        title: 'Confirm Restart',
        message: `Confirm restart action: ${actionName}?`,
        confirmText: 'Restart',
        variant: 'warning',
    })) {
        form.post('/server/restart');
    }
};
</script>

<template>
    <Head :title="__('Restart Services')" />

    <div class="max-w-4xl mx-auto space-y-6">
        <UiPageHeader
            :title="__('Restart Services & Server')"
            subtitle="Trigger graceful restarts of OpenLiteSpeed web server, database, cache, or system components."
        >
            <template #icon>
                <RotateCw class="w-6 h-6 text-theme-accent" />
            </template>
        </UiPageHeader>

        <!-- Actions Grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <!-- OLS Graceful Reload -->
            <UiCard class="flex flex-col justify-between space-y-4">
                <div class="flex items-start gap-3">
                    <div class="p-2.5 rounded-theme-md bg-theme-success-bg text-theme-success-text border border-theme-success-border">
                        <Zap class="w-5 h-5" />
                    </div>
                    <div>
                        <h2 class="text-base font-bold text-theme-text-primary">{{ __('OpenLiteSpeed Safe Reload') }}</h2>
                        <p class="text-xs text-theme-text-secondary mt-1">
                            Zero-downtime graceful reload. Validates syntax before reloading worker pools with automatic rollback on error.
                        </p>
                    </div>
                </div>
                <UiButton
                    @click="execute('ols_reload')"
                    :loading="form.processing"
                    variant="primary"
                    class="w-full"
                >
                    Execute OLS Safe Reload
                </UiButton>
            </UiCard>

            <!-- OLS Full Restart -->
            <UiCard class="flex flex-col justify-between space-y-4">
                <div class="flex items-start gap-3">
                    <div class="p-2.5 rounded-theme-md bg-theme-warning-bg text-theme-warning-text border border-theme-warning-border">
                        <RotateCw class="w-5 h-5" />
                    </div>
                    <div>
                        <h2 class="text-base font-bold text-theme-text-primary">{{ __('OpenLiteSpeed Hard Restart') }}</h2>
                        <p class="text-xs text-theme-text-secondary mt-1">
                            Fully stops and starts OpenLiteSpeed server daemon and all SuEXEC detached PHP processes.
                        </p>
                    </div>
                </div>
                <UiButton
                    @click="execute('ols_restart')"
                    :loading="form.processing"
                    variant="warning"
                    class="w-full"
                >
                    Execute OLS Restart
                </UiButton>
            </UiCard>

            <!-- MariaDB Restart -->
            <UiCard class="flex flex-col justify-between space-y-4">
                <div class="flex items-start gap-3">
                    <div class="p-2.5 rounded-theme-md bg-theme-info-bg text-theme-info-text border border-theme-info-border">
                        <Database class="w-5 h-5" />
                    </div>
                    <div>
                        <h2 class="text-base font-bold text-theme-text-primary">{{ __('MariaDB SQL Restart') }}</h2>
                        <p class="text-xs text-theme-text-secondary mt-1">
                            Restarts the MySQL / MariaDB database engine daemon. May interrupt active client transactions.
                        </p>
                    </div>
                </div>
                <UiButton
                    @click="execute('mariadb_restart')"
                    :loading="form.processing"
                    variant="secondary"
                    class="w-full"
                >
                    Restart MariaDB
                </UiButton>
            </UiCard>

            <!-- Redis Restart -->
            <UiCard class="flex flex-col justify-between space-y-4">
                <div class="flex items-start gap-3">
                    <div class="p-2.5 rounded-theme-md bg-theme-danger-bg text-theme-danger-text border border-theme-danger-border">
                        <Server class="w-5 h-5" />
                    </div>
                    <div>
                        <h2 class="text-base font-bold text-theme-text-primary">{{ __('Redis Cache Restart') }}</h2>
                        <p class="text-xs text-theme-text-secondary mt-1">
                            Restarts Redis in-memory cache server. Cached session keys and transient queue jobs may reset.
                        </p>
                    </div>
                </div>
                <UiButton
                    @click="execute('redis_restart')"
                    :loading="form.processing"
                    variant="danger-soft"
                    class="w-full"
                >
                    Restart Redis
                </UiButton>
            </UiCard>
        </div>
    </div>
</template>
