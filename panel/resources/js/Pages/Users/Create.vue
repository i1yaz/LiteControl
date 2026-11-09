<script setup>
import { shallowRef, computed } from 'vue';
import { useForm, Head, Link } from '@inertiajs/vue3';
import { UserPlus, ArrowLeft, Key, Globe, ShieldCheck, ChevronDown, ChevronRight } from 'lucide-vue-next';

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
    <Head title="Create New Hosting Account" />

    <div class="max-w-2xl mx-auto space-y-6">
        <!-- Header -->
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
                <Link
                    href="/users"
                    class="p-2 rounded-lg bg-slate-900 border border-slate-800 text-slate-400 hover:text-white transition-colors"
                >
                    <ArrowLeft class="w-4 h-4" />
                </Link>
                <div>
                    <h1 class="text-xl font-bold text-white flex items-center gap-2">
                        <UserPlus class="w-5 h-5 text-indigo-400" />
                        <span>Create Hosting Account</span>
                    </h1>
                    <p class="text-xs text-slate-400">Add a new Linux tenant account, configure quotas, SSH credentials, and optional domain.</p>
                </div>
            </div>
        </div>

        <!-- Form Card -->
        <div class="bg-slate-900 border border-slate-800 rounded-xl p-6 shadow-sm">
            <form @submit.prevent="submit" class="space-y-5">
                <!-- User Credentials -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <!-- Username -->
                    <div>
                        <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-1">
                            Username <span class="text-rose-400">*</span>
                        </label>
                        <input
                            v-model="form.username"
                            type="text"
                            placeholder="e.g. john"
                            required
                            class="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-lg text-sm text-slate-200 focus:outline-none focus:border-indigo-500 font-mono"
                        />
                        <p v-if="form.errors.username" class="text-xs text-rose-400 mt-1">{{ form.errors.username }}</p>
                    </div>

                    <!-- Full Name -->
                    <div>
                        <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-1">
                            Full Name <span class="text-rose-400">*</span>
                        </label>
                        <input
                            v-model="form.name"
                            type="text"
                            placeholder="e.g. John Doe"
                            required
                            class="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-lg text-sm text-slate-200 focus:outline-none focus:border-indigo-500"
                        />
                        <p v-if="form.errors.name" class="text-xs text-rose-400 mt-1">{{ form.errors.name }}</p>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <!-- Email -->
                    <div>
                        <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-1">
                            Email Address <span class="text-rose-400">*</span>
                        </label>
                        <input
                            v-model="form.email"
                            type="email"
                            placeholder="john@example.com"
                            required
                            class="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-lg text-sm text-slate-200 focus:outline-none focus:border-indigo-500"
                        />
                        <p v-if="form.errors.email" class="text-xs text-rose-400 mt-1">{{ form.errors.email }}</p>
                    </div>

                    <!-- Password -->
                    <div>
                        <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-1">
                            Password <span class="text-rose-400">*</span>
                        </label>
                        <input
                            v-model="form.password"
                            type="password"
                            placeholder="••••••••••••"
                            required
                            class="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-lg text-sm text-slate-200 focus:outline-none focus:border-indigo-500"
                        />
                        <p v-if="form.errors.password" class="text-xs text-rose-400 mt-1">{{ form.errors.password }}</p>
                    </div>
                </div>

                <!-- Hosting Package -->
                <div>
                    <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-1">
                        Hosting Package <span class="text-rose-400">*</span>
                    </label>
                    <select
                        v-model="form.package_id"
                        required
                        class="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-lg text-sm text-slate-200 focus:outline-none focus:border-indigo-500"
                    >
                        <option v-for="pkg in packages" :key="pkg.id" :value="pkg.id">
                            {{ pkg.name }} ({{ pkg.disk_quota_mb ? `${pkg.disk_quota_mb} MB` : 'Unlimited' }})
                        </option>
                    </select>
                    <p v-if="form.errors.package_id" class="text-xs text-rose-400 mt-1">{{ form.errors.package_id }}</p>
                </div>

                <!-- SSH Public Key Section -->
                <div>
                    <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-1 flex items-center justify-between">
                        <span class="flex items-center gap-1.5">
                            <Key class="w-3.5 h-3.5 text-indigo-400" />
                            <span>SSH Public Key</span>
                        </span>
                        <span class="text-[10px] text-slate-500 font-normal">Optional (for terminal / SFTP access)</span>
                    </label>
                    <textarea
                        v-model="form.ssh_public_key"
                        rows="3"
                        placeholder="ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAI... or ssh-rsa AAAA..."
                        class="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-lg text-xs font-mono text-slate-200 placeholder:text-slate-600 focus:outline-none focus:border-indigo-500"
                    ></textarea>
                    <p class="text-[11px] text-slate-500 mt-0.5">Key will be saved to <code class="text-slate-400 font-mono">~/.ssh/authorized_keys</code> with strict 0600 permissions.</p>
                    <p v-if="form.errors.ssh_public_key" class="text-xs text-rose-400 mt-1">{{ form.errors.ssh_public_key }}</p>
                </div>

                <!-- Optional Primary Domain Toggle Header -->
                <div class="pt-2 border-t border-slate-800">
                    <button
                        type="button"
                        @click="showDomainSection = !showDomainSection"
                        class="flex items-center justify-between w-full py-2 text-left group"
                    >
                        <div class="flex items-center gap-2">
                            <Globe class="w-4 h-4 text-emerald-400" />
                            <span class="text-sm font-semibold text-slate-200 group-hover:text-white transition-colors">
                                Provision Primary Domain Now
                            </span>
                            <span class="text-[10px] px-2 py-0.5 rounded-full bg-slate-800 text-slate-400 font-medium">Optional</span>
                        </div>
                        <component :is="showDomainSection ? ChevronDown : ChevronRight" class="w-4 h-4 text-slate-400 group-hover:text-white transition-colors" />
                    </button>
                    <p class="text-[11px] text-slate-500">You can also leave this blank and add domains later anytime from the Domains page.</p>
                </div>

                <!-- Collapsible Primary Domain Fields -->
                <div v-if="showDomainSection" class="p-4 bg-slate-950 border border-slate-800/80 rounded-xl space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <!-- Domain Name -->
                        <div>
                            <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-1">
                                Domain Name <span class="text-emerald-400">*</span>
                            </label>
                            <input
                                v-model="form.domain"
                                type="text"
                                placeholder="example.com"
                                :required="showDomainSection"
                                class="w-full px-3 py-2 bg-slate-900 border border-slate-800 rounded-lg text-sm text-slate-200 focus:outline-none focus:border-emerald-500 font-mono"
                            />
                            <p v-if="form.errors.domain" class="text-xs text-rose-400 mt-1">{{ form.errors.domain }}</p>
                        </div>

                        <!-- PHP Version -->
                        <div>
                            <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-1">
                                Initial PHP Engine
                            </label>
                            <select
                                v-model="form.php_version"
                                class="w-full px-3 py-2 bg-slate-900 border border-slate-800 rounded-lg text-sm text-slate-200 focus:outline-none focus:border-emerald-500"
                            >
                                <option value="83">PHP 8.3 (Default Recommended)</option>
                                <option value="82">PHP 8.2</option>
                                <option value="81">PHP 8.1</option>
                                <option value="80">PHP 8.0</option>
                                <option value="74">PHP 7.4</option>
                            </select>
                        </div>
                    </div>

                    <!-- Web Root Pointing Location (Subpath) -->
                    <div>
                        <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-1 flex items-center justify-between">
                            <span>Web Root Subpath (Pointing Target)</span>
                            <span class="text-[10px] text-slate-500 font-normal">e.g. "public" for Laravel</span>
                        </label>
                        <input
                            v-model="form.public_path"
                            type="text"
                            placeholder="public (leave empty for standard public_html root)"
                            class="w-full px-3 py-2 bg-slate-900 border border-slate-800 rounded-lg text-xs font-mono text-slate-200 placeholder:text-slate-600 focus:outline-none focus:border-emerald-500"
                        />
                        <div class="mt-1.5 text-[11px] text-slate-500 flex items-center gap-1 font-mono">
                            <span>Active VirtualHost docRoot:</span>
                            <span class="text-emerald-400/90 break-all">{{ calculatedDocRoot }}</span>
                        </div>
                    </div>

                    <!-- SSL Certificate Generation Toggle -->
                    <div class="pt-2 border-t border-slate-800/60 flex items-center justify-between">
                        <div class="flex items-center gap-2.5">
                            <ShieldCheck class="w-4 h-4 text-emerald-400" />
                            <div>
                                <span class="text-xs font-semibold text-slate-200 block">Issue Let's Encrypt SSL Certificate</span>
                                <span class="text-[10px] text-slate-500">Requires DNS A-record to point to this server. Non-blocking if pending.</span>
                            </div>
                        </div>
                        <label class="relative inline-flex items-center cursor-pointer">
                            <input
                                v-model="form.ssl_enabled"
                                type="checkbox"
                                class="sr-only peer"
                            />
                            <div class="w-9 h-5 bg-slate-800 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-emerald-600"></div>
                        </label>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="pt-4 flex items-center justify-end gap-3 border-t border-slate-800">
                    <Link
                        href="/users"
                        class="px-4 py-2 rounded-lg bg-slate-800 hover:bg-slate-700 text-sm font-medium text-slate-300 transition-colors"
                    >
                        Cancel
                    </Link>
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="px-5 py-2 rounded-lg bg-indigo-600 hover:bg-indigo-500 disabled:opacity-50 text-sm font-medium text-white transition-colors shadow-sm shadow-indigo-600/30"
                    >
                        {{ form.processing ? 'Provisioning...' : 'Create Account' }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</template>
