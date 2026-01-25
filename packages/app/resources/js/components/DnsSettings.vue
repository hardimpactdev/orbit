<script setup lang="ts">
import { ref, onMounted } from 'vue';
import { Loader2, Plus, Trash2, Check, AlertCircle, Server, Network } from 'lucide-vue-next';
import { Button, Input, Select, SelectContent, SelectItem, SelectTrigger, SelectValue, Badge } from '@hardimpactdev/craft-ui';

interface DnsMapping {
    type: 'address' | 'server';
    tld?: string;
    value: string;
}

interface Props {
    environmentId: number;
}

const props = defineProps<Props>();

const mappings = ref<DnsMapping[]>([]);
const loading = ref(true);
const saving = ref(false);
const error = ref<string | null>(null);
const successMessage = ref<string | null>(null);

// Form for adding new mapping
const showAddForm = ref(false);
const newMapping = ref<DnsMapping>({
    type: 'address',
    tld: '',
    value: '',
});

const csrfToken = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '';

const loadMappings = async () => {
    loading.value = true;
    error.value = null;

    try {
        const response = await fetch(`/environments/${props.environmentId}/dns`, {
            headers: {
                'Accept': 'application/json',
            },
        });

        const result = await response.json();

        if (result.success) {
            mappings.value = result.mappings || [];
        } else {
            error.value = result.error || 'Failed to load DNS mappings';
        }
    } catch (e) {
        error.value = 'Failed to load DNS mappings';
        console.error(e);
    } finally {
        loading.value = false;
    }
};

const saveMappings = async () => {
    saving.value = true;
    error.value = null;
    successMessage.value = null;

    try {
        const response = await fetch(`/environments/${props.environmentId}/dns`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
            },
            body: JSON.stringify({ mappings: mappings.value }),
        });

        const result = await response.json();

        if (result.success) {
            successMessage.value = result.dns_restarted 
                ? 'DNS mappings updated and DNS service restarted successfully'
                : 'DNS mappings updated successfully';
            
            // Clear success message after 3 seconds
            setTimeout(() => {
                successMessage.value = null;
            }, 3000);
        } else {
            error.value = result.error || 'Failed to save DNS mappings';
        }
    } catch (e) {
        error.value = 'Failed to save DNS mappings';
        console.error(e);
    } finally {
        saving.value = false;
    }
};

const addMapping = () => {
    const mapping: DnsMapping = { ...newMapping.value };

    // Validation
    if (!mapping.value.trim()) {
        error.value = 'IP address is required';
        return;
    }

    if (mapping.type === 'address' && !mapping.tld?.trim()) {
        error.value = 'TLD is required for address mappings';
        return;
    }

    // Remove tld if not needed
    if (mapping.type === 'server' && !mapping.tld?.trim()) {
        delete mapping.tld;
    }

    mappings.value.push(mapping);
    
    // Reset form
    newMapping.value = {
        type: 'address',
        tld: '',
        value: '',
    };
    showAddForm.value = false;
    error.value = null;
    
    // Auto-save
    saveMappings();
};

const removeMapping = (index: number) => {
    mappings.value.splice(index, 1);
    saveMappings();
};

const getMappingLabel = (mapping: DnsMapping): string => {
    if (mapping.type === 'address') {
        return `*.${mapping.tld} → ${mapping.value}`;
    } else if (mapping.tld) {
        return `*.${mapping.tld} DNS → ${mapping.value}`;
    } else {
        return `Fallback DNS → ${mapping.value}`;
    }
};

onMounted(() => {
    loadMappings();
});
</script>

<template>
    <div class="space-y-6">
        <div>
            <h3 class="text-lg font-semibold text-white mb-2">DNS Mappings</h3>
            <p class="text-sm text-zinc-400">
                Configure DNS resolution for development domains and fallback DNS servers.
            </p>
        </div>

        <!-- Error Message -->
        <div v-if="error" class="p-4 bg-red-500/10 border border-red-500/20 rounded-lg flex items-start gap-3">
            <AlertCircle class="w-5 h-5 text-red-400 flex-shrink-0 mt-0.5" />
            <div>
                <p class="text-red-400 text-sm font-medium">Error</p>
                <p class="text-red-400/80 text-sm mt-1">{{ error }}</p>
            </div>
        </div>

        <!-- Success Message -->
        <div v-if="successMessage" class="p-4 bg-green-500/10 border border-green-500/20 rounded-lg flex items-start gap-3">
            <Check class="w-5 h-5 text-green-400 flex-shrink-0 mt-0.5" />
            <div>
                <p class="text-green-400 text-sm font-medium">Success</p>
                <p class="text-green-400/80 text-sm mt-1">{{ successMessage }}</p>
            </div>
        </div>

        <!-- Loading State -->
        <div v-if="loading" class="flex items-center justify-center py-12">
            <Loader2 class="w-6 h-6 animate-spin text-zinc-500" />
        </div>

        <!-- Mappings List -->
        <div v-else class="space-y-3">
            <div
                v-for="(mapping, index) in mappings"
                :key="index"
                class="flex items-center justify-between p-4 bg-zinc-800/50 border border-zinc-700 rounded-lg hover:border-zinc-600 transition-colors"
            >
                <div class="flex items-center gap-3">
                    <div class="p-2 bg-zinc-700 rounded">
                        <Network v-if="mapping.type === 'address'" class="w-4 h-4 text-lime-400" />
                        <Server v-else class="w-4 h-4 text-blue-400" />
                    </div>
                    <div>
                        <p class="text-sm font-medium text-white">{{ getMappingLabel(mapping) }}</p>
                        <p class="text-xs text-zinc-500 mt-0.5">
                            {{ mapping.type === 'address' ? 'Address mapping' : 'DNS server' }}
                        </p>
                    </div>
                </div>
                <Button
                    variant="ghost"
                    size="sm"
                    @click="removeMapping(index)"
                    :disabled="saving"
                >
                    <Trash2 class="w-4 h-4 text-red-400" />
                </Button>
            </div>

            <!-- Empty State -->
            <div v-if="mappings.length === 0" class="text-center py-12 border border-dashed border-zinc-700 rounded-lg">
                <Network class="w-12 h-12 text-zinc-600 mx-auto mb-3" />
                <p class="text-zinc-400 text-sm">No DNS mappings configured</p>
                <p class="text-zinc-500 text-xs mt-1">Add your first mapping to get started</p>
            </div>
        </div>

        <!-- Add Mapping Form -->
        <div v-if="showAddForm" class="p-4 bg-zinc-800/50 border border-zinc-700 rounded-lg space-y-4">
            <h4 class="text-sm font-medium text-white">Add DNS Mapping</h4>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="text-xs text-zinc-400 mb-2 block">Type</label>
                    <Select v-model="newMapping.type">
                        <SelectTrigger>
                            <SelectValue placeholder="Select type" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="address">Address (*.tld → IP)</SelectItem>
                            <SelectItem value="server">DNS Server</SelectItem>
                        </SelectContent>
                    </Select>
                    <p class="text-xs text-zinc-500 mt-1">
                        {{ newMapping.type === 'address' ? 'Maps a TLD to an IP address' : 'Forwards queries to DNS server' }}
                    </p>
                </div>

                <div v-if="newMapping.type === 'address'">
                    <label class="text-xs text-zinc-400 mb-2 block">TLD</label>
                    <Input
                        v-model="newMapping.tld"
                        placeholder="test"
                        class="w-full"
                    />
                    <p class="text-xs text-zinc-500 mt-1">Without leading dot (e.g., "test" or "ccc")</p>
                </div>

                <div v-else>
                    <label class="text-xs text-zinc-400 mb-2 block">TLD (Optional)</label>
                    <Input
                        v-model="newMapping.tld"
                        placeholder="ccc"
                        class="w-full"
                    />
                    <p class="text-xs text-zinc-500 mt-1">Leave empty for fallback DNS</p>
                </div>
            </div>

            <div>
                <label class="text-xs text-zinc-400 mb-2 block">IP Address</label>
                <Input
                    v-model="newMapping.value"
                    placeholder="127.0.0.1 or 8.8.8.8"
                    class="w-full"
                />
                <p class="text-xs text-zinc-500 mt-1">
                    {{ newMapping.type === 'address' ? 'IP to resolve the TLD to' : 'DNS server IP address' }}
                </p>
            </div>

            <div class="flex gap-2">
                <Button variant="secondary" @click="addMapping" :disabled="saving">
                    <Plus class="w-4 h-4" />
                    Add Mapping
                </Button>
                <Button variant="ghost" @click="showAddForm = false">
                    Cancel
                </Button>
            </div>
        </div>

        <!-- Add Button -->
        <Button v-if="!showAddForm" variant="outline" @click="showAddForm = true" :disabled="saving">
            <Plus class="w-4 h-4" />
            Add DNS Mapping
        </Button>

        <!-- Save Status -->
        <div v-if="saving" class="flex items-center gap-2 text-zinc-400 text-sm">
            <Loader2 class="w-4 h-4 animate-spin" />
            Saving and restarting DNS service...
        </div>
    </div>
</template>
