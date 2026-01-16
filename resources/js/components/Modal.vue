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
                class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/30"
                @click.self="close"
            >
                <Transition
                    enter-active-class="transition ease-out duration-200"
                    enter-from-class="opacity-0 scale-95"
                    enter-to-class="opacity-100 scale-100"
                    leave-active-class="transition ease-in duration-150"
                    leave-from-class="opacity-100 scale-100"
                    leave-to-class="opacity-0 scale-95"
                >
                    <div
                        v-if="show"
                        class="bg-zinc-900 border border-white/10 rounded-lg shadow-xl ring-1 ring-white/10 w-full"
                        :class="maxWidth || 'max-w-md'"
                    >
                        <!-- Header -->
                        <div class="flex items-center justify-between px-6 py-4 border-b border-zinc-800">
                            <h3 class="text-lg font-semibold text-white">
                                {{ title }}
                            </h3>
                            <button
                                @click="close"
                                class="p-1.5 text-zinc-500 hover:text-white hover:bg-white/10 rounded transition-colors"
                            >
                                <X class="w-5 h-5" />
                            </button>
                        </div>

                        <!-- Content -->
                        <slot />
                    </div>
                </Transition>
            </div>
        </Transition>
    </Teleport>
</template>
