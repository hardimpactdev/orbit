<script setup lang="ts">
import { computed } from 'vue';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@hardimpactdev/craft-ui';

const props = defineProps<{
    show: boolean;
    title: string;
    maxWidth?: string;
}>();

const emit = defineEmits<{
    close: [];
}>();

// Map maxWidth classes to DialogContent sizes
const dialogClass = computed(() => {
    if (!props.maxWidth) return 'sm:max-w-md';
    // Convert max-w-* classes to appropriate dialog sizes
    if (props.maxWidth.includes('max-w-4xl')) return 'sm:max-w-4xl';
    if (props.maxWidth.includes('max-w-3xl')) return 'sm:max-w-3xl';
    if (props.maxWidth.includes('max-w-2xl')) return 'sm:max-w-2xl';
    if (props.maxWidth.includes('max-w-xl')) return 'sm:max-w-xl';
    if (props.maxWidth.includes('max-w-lg')) return 'sm:max-w-lg';
    return props.maxWidth;
});

const handleOpenChange = (open: boolean) => {
    if (!open) {
        emit('close');
    }
};
</script>

<template>
    <Dialog :open="show" @update:open="handleOpenChange">
        <DialogContent :class="dialogClass">
            <DialogHeader>
                <DialogTitle>{{ title }}</DialogTitle>
            </DialogHeader>
            <slot />
        </DialogContent>
    </Dialog>
</template>
