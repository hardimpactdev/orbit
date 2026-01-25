<script setup lang="ts">
import { ref, onMounted, computed } from 'vue';
import Modal from './Modal.vue';
import { useServicesStore } from '@/stores/services';
import {
    Loader2,
    Plus,
    Server,
    Database,
    Mail,
    Globe,
    Wifi,
    Container,
    Check,
} from 'lucide-vue-next';
import { Button } from '@hardimpactdev/craft-ui';

interface ServiceTemplate {
    name: string;
    label: string;
    description: string;
    category: 'core' | 'database' | 'php' | 'utility';
    required: boolean;
}

const props = defineProps<{
    show: boolean;
    getApiUrl: (path: string) => string;
    csrfToken: string;
}>();

const emit = defineEmits<{
    close: [];
    serviceEnabled: [name: string];
}>();

const store = useServicesStore();
const loading = ref(true);
const availableServices = ref<ServiceTemplate[]>([]);
const enabling = ref<string | null>(null);

const baseApiUrl = computed(() => props.getApiUrl(''));

const categories = [
    { key: 'core', label: 'Core Services' },
    { key: 'php', label: 'PHP Servers' },
    { key: 'database', label: 'Databases' },
    { key: 'utility', label: 'Utilities' },
];

const servicesByCategory = computed(() => {
    const result: Record<string, ServiceTemplate[]> = {
        core: [],
        php: [],
        database: [],
        utility: [],
    };

    availableServices.value.forEach((service) => {
        if (result[service.category]) {
            result[service.category].push(service);
        } else {
            result.utility.push(service);
        }
    });

    return result;
});

const fetchAvailable = async () => {
    loading.value = true;
    try {
        const response = await fetch(props.getApiUrl('/services/available'));
        const result = await response.json();
        if (result.success) {
            availableServices.value = result.data || [];
        }
    } catch (error) {
        console.error('Failed to fetch available services:', error);
    } finally {
        loading.value = false;
    }
};

const enableService = async (serviceName: string) => {
    enabling.value = serviceName;
    try {
        const result = await store.enableService(serviceName, baseApiUrl.value);
        if (result?.success) {
            emit('serviceEnabled', serviceName);
            emit('close');
        } else {
            alert('Failed to enable service: ' + (result?.error || 'Unknown error'));
        }
    } catch (error) {
        alert('Failed to enable service');
    } finally {
        enabling.value = null;
    }
};

const getIcon = (category: string) => {
    switch (category) {
        case 'core':
            return Globe;
        case 'database':
            return Database;
        case 'php':
            return Container;
        case 'utility':
            return Mail;
        default:
            return Server;
    }
};

onMounted(() => {
    if (props.show) {
        fetchAvailable();
    }
});
</script>

<template>
    <Modal :show="show" title="Add Service" maxWidth="max-w-2xl" @close="$emit('close')">
        <div class="p-6">
            <div v-if="loading" class="py-12 text-center">
                <Loader2 class="w-8 h-8 mx-auto text-zinc-600 animate-spin mb-3" />
                <p class="text-zinc-500">Discovering available services...</p>
            </div>

            <div v-else-if="availableServices.length === 0" class="py-12 text-center">
                <Server class="w-8 h-8 mx-auto text-zinc-700 mb-3" />
                <p class="text-zinc-500">No additional services available at this time.</p>
            </div>

            <div v-else class="space-y-8">
                <template v-for="category in categories" :key="category.key">
                    <div v-if="servicesByCategory[category.key].length > 0">
                        <h4
                            class="text-xs font-semibold text-zinc-500 uppercase tracking-wider mb-4 px-1"
                        >
                            {{ category.label }}
                        </h4>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <div
                                v-for="service in servicesByCategory[category.key]"
                                :key="service.name"
                                class="p-4 rounded-xl border border-zinc-800 bg-zinc-900/50 hover:bg-zinc-800/50 hover:border-zinc-700 transition-all group flex flex-col justify-between"
                            >
                                <div>
                                    <div class="flex items-start justify-between mb-2">
                                        <div
                                            class="w-10 h-10 rounded-lg bg-zinc-800 flex items-center justify-center text-zinc-400 group-hover:text-lime-400 group-hover:bg-lime-400/10 transition-colors"
                                        >
                                            <component
                                                :is="getIcon(service.category)"
                                                class="w-5 h-5"
                                            />
                                        </div>
                                        <span
                                            v-if="service.required"
                                            class="text-[10px] font-bold uppercase tracking-tight px-1.5 py-0.5 rounded bg-zinc-800 text-zinc-500 border border-zinc-700"
                                        >
                                            Required
                                        </span>
                                    </div>
                                    <h5 class="text-white font-medium mb-1">{{ service.label }}</h5>
                                    <p class="text-sm text-zinc-500 line-clamp-2 mb-4">
                                        {{ service.description }}
                                    </p>
                                </div>
                                <Button
                                    @click="enableService(service.name)"
                                    :disabled="enabling !== null"
                                    variant="secondary"
                                    class="w-full justify-center"
                                >
                                    <template v-if="enabling === service.name">
                                        <Loader2 class="w-4 h-4 animate-spin mr-2" />
                                        Enabling...
                                    </template>
                                    <template v-else>
                                        <Plus class="w-4 h-4 mr-2" />
                                        Add Service
                                    </template>
                                </Button>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </Modal>
</template>
