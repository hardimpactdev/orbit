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
    noPadding?: boolean;
}>();

const emit = defineEmits<{
    close: [];
}>();

// Map maxWidth classes to appropriate dialog sizes
const dialogClass = computed(() => {
    const classes: string[] = [];
    
    if (!props.maxWidth) {
        classes.push('sm:max-w-md');
    } else if (props.maxWidth.includes('max-w-4xl')) {
        classes.push('sm:max-w-4xl');
    } else if (props.maxWidth.includes('max-w-3xl')) {
        classes.push('sm:max-w-3xl');
    } else if (props.maxWidth.includes('max-w-2xl')) {
        classes.push('sm:max-w-2xl');
    } else if (props.maxWidth.includes('max-w-xl')) {
        classes.push('sm:max-w-xl');
    } else if (props.maxWidth.includes('max-w-lg')) {
        classes.push('sm:max-w-lg');
    } else {
        classes.push(props.maxWidth);
    }
    
    if (props.noPadding) {
        classes.push('!p-0 overflow-hidden');
    }
    
    return classes.join(' ');
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
            <DialogHeader :class="noPadding ? 'px-6 pt-6' : ''">
                <DialogTitle>{{ title }}</DialogTitle>
            </DialogHeader>
            <slot />
        </DialogContent>
    </Dialog>
</template>
