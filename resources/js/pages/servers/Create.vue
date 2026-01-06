<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Heading } from '@hardimpactdev/liftoff-vue';
import { ChevronLeft } from 'lucide-vue-next';

const props = defineProps<{
    currentUser: string;
}>();

const form = useForm({
    name: 'Local',
    host: 'localhost',
    user: props.currentUser,
    port: 22,
    is_local: true,
});

const submit = () => {
    form.post('/servers');
};
</script>

<template>
    <Head title="Add Local Environment" />

    <div class="p-6 max-w-2xl">
        <div class="mb-6">
            <Link href="/servers" class="text-blue-600 hover:text-blue-800 flex items-center">
                <ChevronLeft class="w-4 h-4 mr-1" />
                Back to Environments
            </Link>
        </div>

        <Heading title="Add Local Environment" />
        <p class="text-gray-600 dark:text-gray-400 mb-6 mt-2">
            Set up Launchpad on this machine to manage local development sites.
        </p>

        <form @submit.prevent="submit" class="bg-white dark:bg-gray-800 rounded-lg shadow p-6">
            <div class="space-y-4">
                <div>
                    <label for="name" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                        Environment Name
                    </label>
                    <input
                        v-model="form.name"
                        type="text"
                        id="name"
                        required
                        placeholder="Local"
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white"
                    />
                    <p v-if="form.errors.name" class="mt-1 text-sm text-red-600">{{ form.errors.name }}</p>
                </div>
            </div>

            <div class="mt-6 flex justify-end space-x-3">
                <Link
                    href="/servers"
                    class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700"
                >
                    Cancel
                </Link>
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50"
                >
                    Add Local Environment
                </button>
            </div>
        </form>
    </div>
</template>
