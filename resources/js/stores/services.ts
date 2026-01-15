import { defineStore } from 'pinia'

export interface Service {
  status: string
  health: string | null
  container: string | null
  type: string
  required?: boolean
}

export interface PendingJob {
  service: string
  action: 'start' | 'stop' | 'restart' | 'enable' | 'disable'
  startedAt: number
  error?: string
}

interface EnvironmentCache {
  services: Record<string, Service>
  pendingJobs: Record<string, PendingJob>
  lastUpdated: number | null
}

interface ServicesState {
  environments: Record<number, EnvironmentCache>
  activeEnvironmentId: number | null
}

const STALE_TTL_MS = 5 * 60 * 1000 // 5 minutes

export const useServicesStore = defineStore('services', {
  state: (): ServicesState => ({
    environments: {},
    activeEnvironmentId: null,
  }),

  getters: {
    currentEnv(): EnvironmentCache | null {
      if (!this.activeEnvironmentId) return null
      return this.environments[this.activeEnvironmentId] ?? null
    },

    services(): Record<string, Service> {
      return this.currentEnv?.services ?? {}
    },

    pendingJobs(): Record<string, PendingJob> {
      return this.currentEnv?.pendingJobs ?? {}
    },

    servicesRunning(): number {
      return Object.values(this.services).filter(s => s.status === 'running').length
    },

    servicesTotal(): number {
      return Object.keys(this.services).length
    },

    isStale(): boolean {
      const lastUpdated = this.currentEnv?.lastUpdated
      if (!lastUpdated) return true
      return Date.now() - lastUpdated > STALE_TTL_MS
    },

    isServicePending(): (service: string) => boolean {
      return (service: string) => 
        Object.values(this.pendingJobs).some(j => j.service === service)
    },

    getServiceError(): (service: string) => string | undefined {
      return (service: string) => {
        const job = Object.values(this.pendingJobs).find(
          j => j.service === service && j.error
        )
        return job?.error
      }
    },
  },

  actions: {
    setActiveEnvironment(envId: number) {
      this.activeEnvironmentId = envId
      if (!this.environments[envId]) {
        this.environments[envId] = {
          services: {},
          pendingJobs: {},
          lastUpdated: null,
        }
      }
    },

    async refreshIfStale(apiUrl: string) {
      if (this.isStale) {
        await this.fetchServices(apiUrl)
      }
    },

    async fetchServices(apiUrl: string) {
      try {
        // Use /status endpoint (works for both local NativePHP and remote Launchpad)
        const response = await fetch(`${apiUrl}/status`)
        
        if (!response.ok) {
          console.error('Failed to fetch services:', response.status, response.statusText)
          return
        }
        
        const result = await response.json()
        
        if (result.success && result.data?.services) {
          this.updateServices(result.data.services)
        } else if (result.services) {
          // Direct format
          this.updateServices(result.services)
        } else if (result.data?.services) {
           this.updateServices(result.data.services)
        }
      } catch (error) {
        console.error('Failed to fetch services:', error)
      }
    },

    updateServices(services: Record<string, Service>) {
      if (!this.activeEnvironmentId) return
      
      const env = this.environments[this.activeEnvironmentId]
      if (env) {
        env.services = services
        env.lastUpdated = Date.now()
      }
    },

    async startService(service: string, apiUrl: string, type: string = 'docker') {
      return this.dispatchServiceAction(service, 'start', apiUrl, type)
    },

    async stopService(service: string, apiUrl: string, type: string = 'docker') {
      return this.dispatchServiceAction(service, 'stop', apiUrl, type)
    },

    async restartService(service: string, apiUrl: string, type: string = 'docker') {
      return this.dispatchServiceAction(service, 'restart', apiUrl, type)
    },

    async startAll(apiUrl: string) {
      return this.dispatchGlobalAction('start', apiUrl)
    },

    async stopAll(apiUrl: string) {
      return this.dispatchGlobalAction('stop', apiUrl)
    },

    async restartAll(apiUrl: string) {
      return this.dispatchGlobalAction('restart', apiUrl)
    },

    async enableService(service: string, apiUrl: string) {
      return this.dispatchServiceAction(service, 'enable', apiUrl)
    },

    async disableService(service: string, apiUrl: string) {
      return this.dispatchServiceAction(service, 'disable', apiUrl)
    },

    async dispatchGlobalAction(action: 'start' | 'stop' | 'restart', apiUrl: string) {
      try {
        const response = await fetch(`${apiUrl}/${action}`, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '',
          },
        })
        return await response.json()
      } catch (error) {
        console.error(`Failed to ${action} all services:`, error)
        throw error
      }
    },

    async dispatchServiceAction(
      service: string, 
      action: PendingJob['action'], 
      apiUrl: string,
      type: string = 'docker'
    ) {
      if (!this.activeEnvironmentId) return

      try {
        const path = type === 'host' ? 'host-services' : 'services'
        const response = await fetch(`${apiUrl}/${path}/${service}/${action}`, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '',
          },
        })
        
        const result = await response.json()
        
        if (result.jobId) {
          const env = this.environments[this.activeEnvironmentId]
          if (env) {
            env.pendingJobs[result.jobId] = {
              service,
              action,
              startedAt: Date.now(),
            }
          }
        }
        
        return result
      } catch (error) {
        console.error(`Failed to ${action} service ${service}:`, error)
        throw error
      }
    },

    handleServiceStatusChanged(
      jobId: string | null,
      service: string,
      status: string,
      error?: string
    ) {
      if (!this.activeEnvironmentId) return

      const env = this.environments[this.activeEnvironmentId]
      if (!env) return

      // Remove from pending jobs
      if (jobId && env.pendingJobs[jobId]) {
        if (error) {
          env.pendingJobs[jobId].error = error
        } else {
          delete env.pendingJobs[jobId]
        }
      }

      // Update service status
      if (env.services[service]) {
        env.services[service].status = status
      }

      env.lastUpdated = Date.now()
    },

    clearPendingJobError(jobId: string) {
      if (!this.activeEnvironmentId) return

      const env = this.environments[this.activeEnvironmentId]
      if (env?.pendingJobs[jobId]) {
        delete env.pendingJobs[jobId]
      }
    },

    clearServiceError(service: string) {
      if (!this.activeEnvironmentId) return

      const env = this.environments[this.activeEnvironmentId]
      if (!env) return

      const jobIds = Object.entries(env.pendingJobs)
        .filter(([_, job]) => job.service === service && job.error)
        .map(([jobId]) => jobId)

      jobIds.forEach(jobId => {
        delete env.pendingJobs[jobId]
      })
    },

    async recoverPendingJobs(apiUrl: string) {
      if (!this.activeEnvironmentId) return

      const env = this.environments[this.activeEnvironmentId]
      if (!env) return

      const staleTimeout = 5 * 60 * 1000 // 5 minutes

      for (const [jobId, job] of Object.entries(env.pendingJobs)) {
        // Clear stale jobs
        if (Date.now() - job.startedAt > staleTimeout) {
          delete env.pendingJobs[jobId]
          continue
        }

        // Check job status from API
        try {
          const response = await fetch(`${apiUrl}/jobs/${jobId}`)
          const result = await response.json()

          if (result.status === 'completed' || result.status === 'failed') {
            if (result.status === 'failed') {
              env.pendingJobs[jobId].error = result.error
            } else {
              delete env.pendingJobs[jobId]
            }
          }
        } catch {
          // Job endpoint might not exist, clear stale job
          delete env.pendingJobs[jobId]
        }
      }
    },
  },

  persist: {
    key: 'launchpad-services',
    pick: ['environments'],
  },
})
