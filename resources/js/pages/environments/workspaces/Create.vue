<script setup lang="ts">
import { ref, computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import Layout from '@/layouts/Layout.vue';
import Heading from '@/components/Heading.vue';
import { ArrowLeft } from 'lucide-vue-next';
import { Button, Input, Label } from '@hardimpactdev/craft-ui';

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
                <Label for="name" class="text-muted-foreground mb-2">
                    Workspace Name
                </Label>
                <Input
                    id="name"
                    v-model="form.name"
                    type="text"
                    placeholder="my-workspace"
                    class="w-full"
                    :class="{ 'border-red-500': form.name && !isValidSlug }"
                />
                <p v-if="form.name && slug !== form.name" class="mt-1 text-xs text-muted-foreground">
                    Will be created as: <span class="font-mono text-foreground">{{ slug }}</span>
                </p>
                <p v-if="form.name && !isValidSlug" class="mt-1 text-xs text-red-400">
                    Name must contain only lowercase letters, numbers, and hyphens.
                </p>
                <p v-if="form.errors.name" class="mt-1 text-xs text-red-400">
                    {{ form.errors.name }}
                </p>
            </div>

            <div class="flex items-center gap-3">
                <Button
                    type="submit"
                    variant="secondary"
                    :disabled="form.processing || !isValidSlug"
                >
                    Create Workspace
                </Button>
                <Button as-child variant="ghost">
                    <Link :href="`/environments/${environment.id}/workspaces`">
                        Cancel
                    </Link>
                </Button>
            </div>
        </form>
    </div>
</template>
