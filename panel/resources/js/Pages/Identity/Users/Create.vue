<script setup lang="ts">
import { shallowRef, computed } from 'vue';
import { useForm, Head, Link } from '@inertiajs/vue3';
import UiPageHeader from '@shared/Ui/UiPageHeader.vue';
import UiCard from '@shared/Ui/UiCard.vue';
import UiInput from '@shared/Ui/UiInput.vue';
import UiSelect from '@shared/Ui/UiSelect.vue';
import UiTextarea from '@shared/Ui/UiTextarea.vue';
import UiToggle from '@shared/Ui/UiToggle.vue';
import UiBadge from '@shared/Ui/UiBadge.vue';
import UiButton from '@shared/Ui/UiButton.vue';
import { UserPlus, ArrowLeft, Key, Globe, ShieldCheck, ChevronDown, ChevronRight } from '@lucide/vue';

const props = defineProps({
    packages: Array,
});

const showDomainSection = shallowRef(false);

const form = useForm({
    username: '',
    name: '',
    email: '',
    password: '',
    package_id: props.packages[0]?.id || '',
    ssh_public_key: '',
    // Primary Domain (Optional)
    domain: '',
    public_path: '',
    php_version: '83',
    ssl_enabled: true,
});

const calculatedDocRoot = computed(() => {
    const user = form.username.trim() || '<username>';
    const dom = form.domain.trim() || '<domain>';
    const sub = form.public_path.trim().replace(/^\/+|\/+$/g, '');
    const base = `/home/${user}/${dom}/public_html`;
    return sub ? `${base}/${sub}` : base;
});

const submit = () => {
    if (!showDomainSection.value) {
        form.domain = '';
        form.public_path = '';
    }
    form.post('/users');
};
</script>

<template>
    <Head :title="__('Create New Hosting Account')" />

    <div class="max-w-2xl mx-auto space-y-6">
        <!-- Header -->
        <UiPageHeader
            :title="__('Create Hosting Account')"
            :subtitle="__('Add a new Linux tenant account, configure quotas, SSH credentials, and optional domain.')"
        >
            <template #icon>
                <UserPlus class="w-5 h-5 text-theme-accent" />
            </template>
            <template #actions>
                <Link href="/users">
                    <UiButton variant="secondary" size="sm">
                        <ArrowLeft class="w-4 h-4" />
                        <span>{{ __('Back to Users') }}</span>
                    </UiButton>
                </Link>
            </template>
        </UiPageHeader>

        <!-- Form Card -->
        <UiCard>
            <form @submit.prevent="submit" class="space-y-5">
                <!-- User Credentials -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <!-- Username -->
                    <div>
                        <UiInput
                            id="username"
                            v-model="form.username"
                            type="text"
                            :label="__('Username')"
                            :placeholder="__('e.g. john')"
                            :error="form.errors.username"
                            required
                            class="font-mono"
                        />
                    </div>

                    <!-- Full Name -->
                    <div>
                        <UiInput
                            id="name"
                            v-model="form.name"
                            type="text"
                            :label="__('Full Name')"
                            :placeholder="__('e.g. John Doe')"
                            :error="form.errors.name"
                            required
                        />
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <!-- Email -->
                    <div>
                        <UiInput
                            id="email"
                            v-model="form.email"
                            type="email"
                            :label="__('Email Address')"
                            :placeholder="__('john@example.com')"
                            :error="form.errors.email"
                            required
                        />
                    </div>

                    <!-- Password -->
                    <div>
                        <UiInput
                            id="password"
                            v-model="form.password"
                            type="password"
                            :label="__('Password')"
                            placeholder="••••••••••••"
                            :error="form.errors.password"
                            required
                        />
                    </div>
                </div>

                <!-- Hosting Package -->
                <div>
                    <UiSelect
                        id="package_id"
                        v-model="form.package_id"
                        :label="__('Hosting Package')"
                        :options="(packages || []).map(pkg => ({
                            value: pkg.id,
                            label: `${pkg.name} (${pkg.disk_quota_mb ? `${pkg.disk_quota_mb} MB` : 'Unlimited'})`,
                        }))"
                        :error="form.errors.package_id"
                        required
                    />
                </div>

                <!-- SSH Public Key Section -->
                <div>
                    <UiTextarea
                        id="ssh_public_key"
                        v-model="form.ssh_public_key"
                        rows="3"
                        :label="__('SSH Public Key')"
                        :placeholder="__('ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAI... or ssh-rsa AAAA...')"
                        :hint="`${__('Key will be saved to')} ~/.ssh/authorized_keys ${__('with strict 0600 permissions.')}`"
                        :error="form.errors.ssh_public_key"
                    >
                        <template #label-right>
                            <span class="text-[10px] text-theme-text-muted font-normal">{{ __('Optional (for terminal / SFTP access)') }}</span>
                        </template>
                    </UiTextarea>
                </div>

                <!-- Optional Primary Domain Toggle Header -->
                <div class="pt-2 border-t border-theme-border">
                    <button
                        type="button"
                        @click="showDomainSection = !showDomainSection"
                        class="flex items-center justify-between w-full py-2 text-left group"
                    >
                        <div class="flex items-center gap-2">
                            <Globe class="w-4 h-4 text-theme-success" />
                            <span class="text-sm font-semibold text-theme-text-primary group-hover:text-theme-accent transition-colors">
                                Provision Primary Domain Now
                            </span>
                            <UiBadge variant="neutral">{{ __('Optional') }}</UiBadge>
                        </div>
                        <component :is="showDomainSection ? ChevronDown : ChevronRight" class="w-4 h-4 text-theme-text-muted group-hover:text-theme-text-primary transition-colors" />
                    </button>
                    <p class="text-[11px] text-theme-text-muted">{{ __('You can also leave this blank and add domains later anytime from the Domains page.') }}</p>
                </div>

                <!-- Collapsible Primary Domain Fields -->
                <div v-if="showDomainSection" class="p-4 bg-theme-bg-surface border border-theme-border rounded-theme-lg space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <!-- Domain Name -->
                        <div>
                            <UiInput
                                id="domain"
                                v-model="form.domain"
                                type="text"
                                :label="__('Domain Name')"
                                :placeholder="__('example.com')"
                                :required="showDomainSection"
                                :error="form.errors.domain"
                                class="font-mono"
                            />
                        </div>

                        <!-- PHP Version -->
                        <div>
                            <UiSelect
                                id="php_version"
                                v-model="form.php_version"
                                :label="__('Initial PHP Engine')"
                                :options="[
                                    { value: '83', label: __('PHP 8.3 (Default Recommended)') },
                                    { value: '82', label: __('PHP 8.2') },
                                    { value: '81', label: __('PHP 8.1') },
                                    { value: '80', label: __('PHP 8.0') },
                                    { value: '74', label: __('PHP 7.4') },
                                ]"
                            />
                        </div>
                    </div>

                    <!-- Web Root Pointing Location (Subpath) -->
                    <div>
                        <UiInput
                            id="public_path"
                            v-model="form.public_path"
                            type="text"
                            :label="__('Web Root Subpath (Pointing Target)')"
                            :placeholder="__('public (leave empty for standard public_html root)')"
                            class="font-mono"
                        >
                            <template #label-right>
                                <span class="text-[10px] text-theme-text-muted font-normal">{{ __('e.g. "public" for Laravel') }}</span>
                            </template>
                        </UiInput>
                        <div class="mt-1.5 text-[11px] text-theme-text-muted flex items-center gap-1 font-mono">
                            <span>{{ __('Active VirtualHost docRoot:') }}</span>
                            <span class="text-theme-success break-all">{{ calculatedDocRoot }}</span>
                        </div>
                    </div>

                    <!-- SSL Certificate Generation Toggle -->
                    <div class="pt-2 border-t border-theme-border-subtle">
                        <UiToggle
                            v-model="form.ssl_enabled"
                            :label="__('Issue Let\'s Encrypt SSL Certificate')"
                            :description="__('Requires DNS A-record to point to this server. Non-blocking if pending.')"
                        />
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="pt-4 flex items-center justify-end gap-3 border-t border-theme-border">
                    <Link href="/users">
                        <UiButton type="button" variant="secondary">
                            Cancel
                        </UiButton>
                    </Link>
                    <UiButton
                        type="submit"
                        variant="primary"
                        :disabled="form.processing"
                        :loading="form.processing"
                    >
                        {{ form.processing ? 'Provisioning...' : 'Create Account' }}
                    </UiButton>
                </div>
            </form>
        </UiCard>
    </div>
</template>
