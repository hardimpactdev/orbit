import { defineStore } from 'pinia';

interface GitHubState {
    selectedOrg: string | null;
}

export const useGitHubStore = defineStore('github', {
    state: (): GitHubState => ({
        selectedOrg: null,
    }),

    actions: {
        setSelectedOrg(org: string | null) {
            this.selectedOrg = org;
        },
    },

    persist: {
        key: 'orbit-github',
        pick: ['selectedOrg'],
    },
});
