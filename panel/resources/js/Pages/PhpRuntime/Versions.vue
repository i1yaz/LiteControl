<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { FileCode, Download, Trash2, ShieldCheck } from '@lucide/vue';
import { confirmDialog, alertDialog } from '@shared/Composables/useDialog';
import UiPageHeader from '@shared/Ui/UiPageHeader.vue';
import UiBadge from '@shared/Ui/UiBadge.vue';
import UiButton from '@shared/Ui/UiButton.vue';

const props = defineProps({
    phpVersions: Array,
    cliUsageCounts: {
        type: Object,
        default: () => ({}),
    },
});

const getCliUserCount = (ver: string | number) => {
    const str = String(ver ?? '');
    const dotted = !str.includes('.') && str.length === 2 ? `${str[0]}.${str[1]}` : str;
    return Number(props.cliUsageCounts?.[dotted] ?? props.cliUsageCounts?.[str] ?? 0);
};

const installVer = async (ver: string) => {
    if (await confirmDialog({
        title: 'Install PHP Version',
        message: `Install lsphp${ver} from the official LiteSpeed repository? This will register an external processor.`,
        confirmText: 'Install',
    })) {
        router.post('/php/versions/install', { version: ver });
    }
};

const removeVer = async (ver: string) => {
    if (ver === '83') {
        await alertDialog({
            title: 'Cannot Uninstall',
            message: 'lsphp83 is the primary control panel runtime and cannot be uninstalled.',
            variant: 'warning',
        });
        return;
    }
    if (await confirmDialog({
        title: 'Uninstall PHP Version',
        message: `Uninstall lsphp${ver}? Any virtual hosts assigned to this version will need to be re-pointed.`,
        confirmText: 'Uninstall',
        variant: 'danger',
    })) {
        router.post('/php/versions/remove', { version: ver });
    }
};
</script>

<template>
    <Head :title="__('PHP Version Manager')" />

    <div class="space-y-6">
        <!-- Header -->
        <UiPageHeader
            :title="__('PHP Version Manager')"
            subtitle="Install or remove multiple OpenLiteSpeed PHP versions (lsphp74 through lsphp85)."
        >
            <template #icon>
                <FileCode class="w-6 h-6 text-theme-accent" />
            </template>
        </UiPageHeader>

        <!-- Version Cards Grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <div
                v-for="v in (phpVersions as any[])"
                :key="v.version"
                class="bg-theme-bg-card border border-theme-border rounded-theme-lg p-5 space-y-4 hover:border-theme-border-hover transition-colors shadow-xs"
            >
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <div
                            :class="[
                                'w-10 h-10 rounded-theme-md flex items-center justify-center font-bold text-xs border',
                                v.installed
                                    ? 'bg-theme-accent-subtle border-theme-accent-border text-theme-accent-text'
                                    : 'bg-theme-bg-surface border-theme-border text-theme-text-muted'
                            ]"
                        >
                            <FileCode class="w-5 h-5" />
                        </div>
                        <div>
                            <h3 class="text-base font-bold text-theme-text-primary flex items-center gap-2">
                                <span>PHP {{ v.version }}</span>
                                <UiBadge v-if="v.is_default || v.version === '83'" variant="success">
                                    Core Default
                                </UiBadge>
                            </h3>
                            <span class="text-xs text-theme-text-secondary">
                                Package: lsphp{{ v.version }}
                            </span>
                            <UiBadge
                                v-if="v.installed"
                                variant="info"
                                class="ml-2"
                            >
                                {{ getCliUserCount(v.version) }} CLI {{ getCliUserCount(v.version) === 1 ? 'user' : 'users' }}
                            </UiBadge>
                        </div>
                    </div>

                    <!-- Status Pill -->
                    <UiBadge :variant="v.installed ? 'success' : 'neutral'">
                        {{ v.installed ? 'Installed' : 'Not Installed' }}
                    </UiBadge>
                </div>

                <div class="pt-3 border-t border-theme-border-subtle flex items-center justify-between text-xs">
                    <span class="text-theme-text-muted font-mono text-[11px] truncate max-w-[180px]">
                        {{ v.installed ? (v.path || `/usr/local/lsws/lsphp${v.version}/bin/lsphp`) : 'Available in LiteSpeed repo' }}
                    </span>

                    <div class="flex items-center gap-2">
                        <UiButton
                            v-if="!v.installed"
                            variant="primary"
                            size="sm"
                            @click="installVer(v.version)"
                        >
                            <Download class="w-3.5 h-3.5" />
                            <span>{{ __('Install') }}</span>
                        </UiButton>

                        <UiButton
                            v-else-if="v.version !== '83'"
                            variant="danger-soft"
                            size="sm"
                            @click="removeVer(v.version)"
                            :disabled="getCliUserCount(v.version) > 0"
                            :title="getCliUserCount(v.version) > 0 ? `Cannot remove: ${getCliUserCount(v.version)} user(s) actively using this PHP CLI version` : 'Remove PHP version'"
                        >
                            <Trash2 class="w-3.5 h-3.5" />
                            <span>{{ __('Remove') }}</span>
                        </UiButton>

                        <span v-else class="text-theme-text-muted text-[11px] flex items-center gap-1">
                            <ShieldCheck class="w-3.5 h-3.5 text-theme-success" />
                            System Protected
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>


