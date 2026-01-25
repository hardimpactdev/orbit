<script setup lang="ts">
defineProps<{
    title: string;
    description?: string;
}>();
</script>

<template>
    <div>
        <h1 class="text-2xl font-semibold tracking-tight text-zinc-100">{{ title }}</h1>
        <p v-if="description" class="mt-1 text-sm text-zinc-500">{{ description }}</p>
    </div>
</template>
