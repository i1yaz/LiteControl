<script setup>
import { useForm, Head, Link } from '@inertiajs/vue3';
import { UserPlus, ArrowLeft, Package as PackageIcon, ShieldAlert } from 'lucide-vue-next';

const props = defineProps({
    packages: Array,
});

const form = useForm({
    username: '',
    name: '',
    email: '',
    password: '',
    package_id: props.packages[0]?.id || '',
    domain: '',
    php_version: '83',
});

const submit = () => {
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
                        <p class="text-xs text-slate-400">Add a new Linux user, provision virtual host, and assign package limits.</p>
                    </div>
                </div>
            </div>

            <!-- Form Card -->
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-6 shadow-sm">
                <form @submit.prevent="submit" class="space-y-4">
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
                                class="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-lg text-sm text-slate-200 focus:outline-none focus:border-indigo-500"
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

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <!-- Primary Domain -->
                        <div>
                            <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-1">
                                Primary Domain <span class="text-rose-400">*</span>
                            </label>
                            <input
                                v-model="form.domain"
                                type="text"
                                placeholder="example.com"
                                required
                                class="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-lg text-sm text-slate-200 focus:outline-none focus:border-indigo-500"
                            />
                            <p v-if="form.errors.domain" class="text-xs text-rose-400 mt-1">{{ form.errors.domain }}</p>
                        </div>

                        <!-- Package -->
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
                                    {{ pkg.name }} ({{ pkg.disk_quota_mb }} MB)
                                </option>
                            </select>
                            <p v-if="form.errors.package_id" class="text-xs text-rose-400 mt-1">{{ form.errors.package_id }}</p>
                        </div>
                    </div>

                    <!-- PHP Version -->
                    <div>
                        <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-1">
                            Initial PHP Version
                        </label>
                        <select
                            v-model="form.php_version"
                            class="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-lg text-sm text-slate-200 focus:outline-none focus:border-indigo-500"
                        >
                            <option value="83">PHP 8.3 (Default / Recommended)</option>
                            <option value="82">PHP 8.2</option>
                            <option value="81">PHP 8.1</option>
                            <option value="80">PHP 8.0</option>
                            <option value="74">PHP 7.4</option>
                        </select>
                    </div>

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

