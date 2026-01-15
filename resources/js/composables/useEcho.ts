import Echo from 'laravel-echo'
import Pusher from 'pusher-js'

// Make Pusher available globally for Echo
declare global {
  interface Window {
    Pusher: typeof Pusher
    Echo: Echo<'reverb'>
  }
}

window.Pusher = Pusher

let echoInstance: Echo<'reverb'> | null = null
let currentTld: string | null = null

export interface Environment {
  id: number
  tld: string
  [key: string]: any
}

export function useEcho() {
  const connect = (environment: Environment) => {
    // Skip if already connected to this environment
    if (echoInstance && currentTld === environment.tld) {
      return echoInstance
    }

    // Disconnect from previous environment
    disconnect()

    const reverbHost = `launchpad.${environment.tld}`
    
    echoInstance = new Echo({
      broadcaster: 'reverb',
      key: 'launchpad-key',
      wsHost: reverbHost,
      wsPort: 8080,
      wssPort: 8080,
      forceTLS: false,
      enabledTransports: ['ws', 'wss'],
      disableStats: true,
    })

    currentTld = environment.tld
    
    return echoInstance
  }

  const disconnect = () => {
    if (echoInstance) {
      echoInstance.disconnect()
      echoInstance = null
      currentTld = null
    }
  }

  const getEcho = () => echoInstance

  const isConnected = () => echoInstance !== null

  return {
    connect,
    disconnect,
    getEcho,
    isConnected,
  }
}
