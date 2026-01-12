<script setup lang="ts">
defineProps<{
    title: string;
    description?: string;
}>();
</script>

<template>
    <div>
        <h1 class="text-2xl font-semibold text-white">{{ title }}</h1>
        <p v-if="description" class="mt-1 text-sm text-zinc-400">{{ description }}</p>
    </div>
</template>
