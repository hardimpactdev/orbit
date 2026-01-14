<script setup lang="ts">
import { ref, onMounted, watch } from 'vue';
import Modal from './Modal.vue';
import { Loader2, Save, AlertCircle, Eye, EyeOff } from 'lucide-vue-next';

interface ConfigField {
    type: 'string' | 'integer' | 'boolean';
    default: any;
    label: string;
    description?: string;
    enum?: string[];
    secret?: boolean;
}

interface ServiceInfo {
    name: string;
    label: string;
    description: string;
    configSchema: Record<string, ConfigField>;
    config: Record<string, any>;
}

const props = defineProps<{
    show: boolean;
    serviceName: string | null;
    getApiUrl: (path: string) => string;
    csrfToken: string;
}>();

const emit = defineEmits<{
    close: [];
    configUpdated: [name: string];
}>();

const loading = ref(true);
const saving = ref(false);
const serviceInfo = ref<ServiceInfo | null>(null);
const formData = ref<Record<string, any>>({});
const showSecrets = ref<Record<string, boolean>>({});

const fetchInfo = async () => {
    if (!props.serviceName) return;

    loading.value = true;
    try {
        const response = await fetch(props.getApiUrl(`/services/${props.serviceName}/info`));
        const result = await response.json();
        if (result.success && result.data) {
            serviceInfo.value = result.data;
            formData.value = { ...result.data.config };
            
            // Initialize showSecrets
            const secrets: Record<string, boolean> = {};
            Object.entries(result.data.configSchema as Record<string, ConfigField>).forEach(([key, field]) => {
                if (field.secret) secrets[key] = false;
            });
            showSecrets.value = secrets;
        }
    } catch (error) {
        console.error('Failed to fetch service info:', error);
    } finally {
        loading.value = false;
    }
};

const saveConfig = async () => {
    if (!props.serviceName) return;

    saving.value = true;
    try {
        const response = await fetch(props.getApiUrl(`/services/${props.serviceName}/config`), {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': props.csrfToken,
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ config: formData.value }),
        });
        const result = await response.json();
        if (result.success) {
            emit('configUpdated', props.serviceName);
            emit('close');
        } else {
            alert('Failed to update configuration: ' + (result.error || 'Unknown error'));
        }
    } catch (error) {
        alert('Failed to update configuration');
    } finally {
        saving.value = false;
    }
};

const toggleSecret = (key: string) => {
    showSecrets.value[key] = !showSecrets.value[key];
};

watch(() => props.show, (newVal) => {
    if (newVal && props.serviceName) {
        fetchInfo();
    } else {
        serviceInfo.value = null;
        formData.value = {};
    }
});

onMounted(() => {
    if (props.show && props.serviceName) {
        fetchInfo();
    }
});

</script>

<template>
    <Modal :show="show" :title="serviceInfo ? `Configure ${serviceInfo.label}` : 'Configure Service'" maxWidth="max-w-lg" @close="$emit('close')">
        <div class="p-6">
            <div v-if="loading" class="py-12 text-center">
                <Loader2 class="w-8 h-8 mx-auto text-zinc-600 animate-spin mb-3" />
                <p class="text-zinc-500">Loading configuration...</p>
            </div>

            <div v-else-if="!serviceInfo" class="py-12 text-center">
                <AlertCircle class="w-8 h-8 mx-auto text-red-500/50 mb-3" />
                <p class="text-zinc-500">Failed to load service information.</p>
            </div>

            <form v-else @submit.prevent="saveConfig" class="space-y-6">
                <div v-for="(field, key) in serviceInfo.configSchema" :key="key" class="space-y-1.5">
                    <label :for="key" class="block text-sm font-medium text-zinc-300">
                        {{ field.label }}
                        <span v-if="field.secret" class="ml-1 text-[10px] text-zinc-500 uppercase">Secret</span>
                    </label>

                    <div v-if="field.enum" class="relative">
                        <select
                            :id="key"
                            v-model="formData[key]"
                            class="w-full bg-zinc-900 border border-zinc-700 rounded-lg px-3 py-2 text-white focus:outline-none focus:ring-2 focus:ring-lime-500/50 focus:border-lime-500 transition-all appearance-none"
                        >
                            <option v-for="opt in field.enum" :key="opt" :value="opt">{{ opt }}</option>
                        </select>
                        <div class="absolute inset-y-0 right-0 flex items-center px-2 pointer-events-none text-zinc-500">
                            <svg class="w-4 h-4 fill-current" viewBox="0 0 20 20"><path d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" fill-rule="evenodd"></path></svg>
                        </div>
                    </div>

                    <div v-else-if="field.type === 'boolean'" class="flex items-center">
                        <button
                            type="button"
                            @click="formData[key] = !formData[key]"
                            class="relative inline-flex h-6 w-11 items-center rounded-full transition-colors focus:outline-none focus:ring-2 focus:ring-lime-500 focus:ring-offset-2 focus:ring-offset-zinc-900"
                            :class="formData[key] ? 'bg-lime-500' : 'bg-zinc-700'"
                        >
                            <span
                                class="inline-block h-4 w-4 transform rounded-full bg-white transition-transform"
                                :class="formData[key] ? 'translate-x-6' : 'translate-x-1'"
                            />
                        </button>
                        <span class="ml-3 text-sm text-zinc-400">{{ formData[key] ? 'Enabled' : 'Disabled' }}</span>
                    </div>

                    <div v-else class="relative">
                        <input
                            :id="key"
                            :type="field.secret && !showSecrets[key] ? 'password' : (field.type === 'integer' ? 'number' : 'text')"
                            v-model="formData[key]"
                            class="w-full bg-zinc-900 border border-zinc-700 rounded-lg px-3 py-2 text-white placeholder-zinc-600 focus:outline-none focus:ring-2 focus:ring-lime-500/50 focus:border-lime-500 transition-all"
                            :placeholder="field.default?.toString()"
                        />
                        <button
                            v-if="field.secret"
                            type="button"
                            @click="toggleSecret(key)"
                            class="absolute inset-y-0 right-0 px-3 flex items-center text-zinc-500 hover:text-zinc-300"
                        >
                            <EyeOff v-if="showSecrets[key]" class="w-4 h-4" />
                            <Eye v-else class="w-4 h-4" />
                        </button>
                    </div>

                    <p v-if="field.description" class="text-xs text-zinc-500">{{ field.description }}</p>
                </div>

                <div class="pt-4 flex items-center justify-end gap-3 border-t border-zinc-800">
                    <button
                        type="button"
                        @click="$emit('close')"
                        class="btn btn-outline"
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        :disabled="saving"
                        class="btn btn-primary min-w-[100px] justify-center"
                    >
                        <Loader2 v-if="saving" class="w-4 h-4 animate-spin mr-2" />
                        <Save v-else class="w-4 h-4 mr-2" />
                        Save Changes
                    </button>
                </div>
            </form>
        </div>
    </Modal>
</template>
