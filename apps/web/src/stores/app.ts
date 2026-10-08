import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import type { BootstrapResponse } from '../api/types'

export type ServerStatus = 'checking' | 'online' | 'offline'

/** État technique de l'application : réseau, serveur, horloge. */
export const useAppStore = defineStore('app', () => {
  const browserOnline = ref(typeof navigator === 'undefined' ? true : navigator.onLine)
  const serverStatus = ref<ServerStatus>('checking')
  const bootstrap = ref<BootstrapResponse | null>(null)
  const now = ref(new Date())

  const canReachServer = computed(() => browserOnline.value && serverStatus.value === 'online')
  const version = computed(() => bootstrap.value?.application.version ?? __APP_VERSION__)

  const statusLabel = computed(() => {
    if (!browserOnline.value) return 'Hors ligne'
    if (serverStatus.value === 'online') return 'Serveur disponible'
    if (serverStatus.value === 'offline') return 'Serveur indisponible'
    return 'Vérification'
  })

  async function verifyServer(): Promise<void> {
    if (!navigator.onLine) {
      browserOnline.value = false
      serverStatus.value = 'offline'
      return
    }

    serverStatus.value = 'checking'
    try {
      const [health, boot] = await Promise.all([
        fetch('/api/health', { headers: { Accept: 'application/json' } }),
        fetch('/api/v1/bootstrap', { headers: { Accept: 'application/json' } }),
      ])
      if (!health.ok || !boot.ok) throw new Error('Réponse invalide')
      bootstrap.value = (await boot.json()) as BootstrapResponse
      serverStatus.value = 'online'
    } catch {
      serverStatus.value = 'offline'
    }
  }

  let clock: number | undefined

  function start(): void {
    void verifyServer()
    clock = window.setInterval(() => {
      now.value = new Date()
    }, 30_000)
    window.addEventListener('online', () => {
      browserOnline.value = true
      void verifyServer()
    })
    window.addEventListener('offline', () => {
      browserOnline.value = false
      serverStatus.value = 'offline'
    })
  }

  function stop(): void {
    if (clock !== undefined) window.clearInterval(clock)
  }

  return {
    browserOnline,
    serverStatus,
    bootstrap,
    now,
    canReachServer,
    version,
    statusLabel,
    verifyServer,
    start,
    stop,
  }
})
