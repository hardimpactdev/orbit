<script setup lang="ts">
import { watch, onMounted, onUnmounted } from 'vue';
import { X } from 'lucide-vue-next';

const props = defineProps<{
    show: boolean;
    title: string;
    maxWidth?: string;
}>();

const emit = defineEmits<{
    close: [];
}>();

const close = () => {
    emit('close');
};

const handleEscape = (e: KeyboardEvent) => {
    if (e.key === 'Escape' && props.show) {
        close();
    }
};

onMounted(() => {
    document.addEventListener('keydown', handleEscape);
});

onUnmounted(() => {
    document.removeEventListener('keydown', handleEscape);
});

watch(() => props.show, (show) => {
    document.body.style.overflow = show ? 'hidden' : '';
});
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="transition ease-out duration-200"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition ease-in duration-150"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div
                v-if="show"
                class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50"
                @click.self="close"
            >
                <div
                    class="bg-white dark:bg-gray-800 rounded-lg shadow-xl w-full"
                    :class="maxWidth || 'max-w-md'"
                >
                    <!-- Header -->
                    <div class="flex items-center justify-between px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white">
                            {{ title }}
                        </h3>
                        <button
                            @click="close"
                            class="p-1 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 rounded"
                        >
                            <X class="w-5 h-5" />
                        </button>
                    </div>

                    <!-- Content -->
                    <slot />
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
