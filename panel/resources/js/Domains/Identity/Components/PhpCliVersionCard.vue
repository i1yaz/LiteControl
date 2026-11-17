<script setup>
import { useForm } from '@inertiajs/vue3';

const props = defineProps({
    user: {
        type: Object,
        required: true,
    },
    installedVersions: {
        type: Array,
        default: () => [],
    },
});

const form = useForm({
    version: props.user.php_cli_version ?? '8.3',
});

const submit = () => {
    form.patch(route('users.php-cli-version', props.user.id), {
        preserveScroll: true,
    });
};
</script>

<template>
    <div class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">
            Default PHP CLI Version
        </h3>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
            Select the default PHP CLI binary used for SSH sessions and cron jobs for
            <span class="font-mono font-medium">{{ user.username }}</span>.
        </p>

        <form @submit.prevent="submit" class="mt-4 space-y-4">
            <div>
                <label
                    for="php-cli-version"
                    class="block text-sm font-medium text-gray-700 dark:text-gray-300"
                >
                    PHP CLI Version
                </label>
                <div class="mt-1 flex items-center gap-3">
                    <select
                        id="php-cli-version"
                        v-model="form.version"
                        class="block w-48 rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"
                    >
                        <option
                            v-for="ver in installedVersions"
                            :key="ver"
                            :value="ver"
                        >
                            PHP {{ ver }}
                        </option>
                    </select>

                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="inline-flex items-center rounded-md bg-indigo-600 px-3.5 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-500 disabled:opacity-50"
                    >
                        {{ form.processing ? 'Saving...' : 'Save' }}
                    </button>
                </div>
                <p v-if="form.errors.version" class="mt-1.5 text-sm text-red-600 dark:text-red-400">
                    {{ form.errors.version }}
                </p>
            </div>

            <div class="space-y-2 rounded-md bg-gray-50 p-3 text-xs text-gray-600 dark:bg-gray-900/50 dark:text-gray-400">
                <p>
                    <strong>Shared INI:</strong>
                    OpenLiteSpeed's CLI binary (<code>/usr/local/lsws/lsphp{XY}/bin/php</code>) shares the same
                    <code>php.ini</code> and loaded extensions as the <code>lsphp{XY}</code> web handler.
                </p>
                <p>
                    <strong>Per-Project Override:</strong>
                    Placing a <code>.php-version</code> file (e.g. <code>8.2</code>) inside any project root overrides
                    this user-level default for both interactive shells and cron jobs targeting that directory.
                </p>
                <p>
                    <strong>Worker Restart Reminder:</strong>
                    Long-running Supervisor processes (<code>php artisan queue:work</code>, Horizon, Octane) must be
                    restarted to use the newly selected PHP binary.
                </p>
            </div>
        </form>
    </div>
</template>
