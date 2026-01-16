<script setup lang="ts">
import { ref, computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import Layout from '@/layouts/Layout.vue';
import Heading from '@/components/Heading.vue';
import { ArrowLeft } from 'lucide-vue-next';

interface Environment {
    id: number;
    name: string;
}

const props = defineProps<{
    environment: Environment;
}>();

defineOptions({
    layout: Layout,
});

const form = useForm({
    name: '',
});

const slugify = (str: string) => {
    return str
        .toLowerCase()
        .replace(/[^a-z0-9-]/g, '-')
        .replace(/-+/g, '-')
        .replace(/^-|-$/g, '');
};

const slug = computed(() => slugify(form.name));
const isValidSlug = computed(() => /^[a-z0-9-]+$/.test(slug.value) && slug.value.length > 0);

const submit = () => {
    form.transform(() => ({
        name: slug.value,
    })).post(`/environments/${props.environment.id}/workspaces`);
};
</script>

<template>
    <Head title="Create Workspace" />

    <div class="space-y-6">
        <div class="flex items-center gap-4">
            <Link
                :href="`/environments/${environment.id}/workspaces`"
                class="p-2 rounded-lg hover:bg-white/5 text-zinc-400 hover:text-white"
            >
                <ArrowLeft class="w-5 h-5" />
            </Link>
            <Heading title="Create Workspace" description="Group related projects together" />
        </div>

        <form @submit.prevent="submit" class="max-w-xl space-y-6">
            <div>
                <label for="name" class="block text-sm font-medium text-zinc-300 mb-2">
                    Workspace Name
                </label>
                <input
                    id="name"
                    v-model="form.name"
                    type="text"
                    placeholder="my-workspace"
                    class="w-full"
                    :class="{ 'border-red-500': form.name && !isValidSlug }"
                />
                <p v-if="form.name && slug !== form.name" class="mt-1 text-xs text-zinc-500">
                    Will be created as: <span class="font-mono text-zinc-400">{{ slug }}</span>
                </p>
                <p v-if="form.name && !isValidSlug" class="mt-1 text-xs text-red-400">
                    Name must contain only lowercase letters, numbers, and hyphens.
                </p>
                <p v-if="form.errors.name" class="mt-1 text-xs text-red-400">
                    {{ form.errors.name }}
                </p>
            </div>

            <div class="flex items-center gap-3">
                <button
                    type="submit"
                    class="btn btn-secondary"
                    :disabled="form.processing || !isValidSlug"
                >
                    Create Workspace
                </button>
                <Link
                    :href="`/environments/${environment.id}/workspaces`"
                    class="btn btn-plain"
                >
                    Cancel
                </Link>
            </div>
        </form>
    </div>
</template>
