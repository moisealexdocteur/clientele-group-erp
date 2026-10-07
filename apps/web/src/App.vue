<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'

type ApiStatus = 'checking' | 'online' | 'offline'

interface BootstrapResponse {
  application: {
    name: string
    environment: string
    version: string
  }
  display: {
    locale: string
    timezone: string
    timezone_label: string
    currencies: string[]
  }
  pilot: {
    label: string
    state: string
  }
}

const activeSection = ref('Accueil')
const apiStatus = ref<ApiStatus>('checking')
const isOffline = ref(!navigator.onLine)
const bootstrap = ref<BootstrapResponse | null>(null)
const checkedAt = ref<Date | null>(null)

const sections = [
  'Accueil',
  'Réservations',
  'Locations',
  'Véhicules',
  'Inspections',
  'Dépôts',
  'Rapports',
]

const formattedCapHaitienTime = computed(() => {
  const date = checkedAt.value ?? new Date()
  const datePart = new Intl.DateTimeFormat('fr-FR', {
    timeZone: 'America/Port-au-Prince',
    day: '2-digit',
    month: 'long',
    year: 'numeric',
  }).format(date)
  const timePart = new Intl.DateTimeFormat('en-US', {
    timeZone: 'America/Port-au-Prince',
    hour: '2-digit',
    minute: '2-digit',
    hour12: true,
  }).format(date)

  return `${datePart} · ${timePart}`
})

const statusLabel = computed(() => {
  if (isOffline.value) return 'Hors ligne'
  if (apiStatus.value === 'online') return 'Serveur disponible'
  if (apiStatus.value === 'offline') return 'Serveur indisponible'
  return 'Vérification…'
})

async function verifyApi(): Promise<void> {
  if (!navigator.onLine) {
    isOffline.value = true
    apiStatus.value = 'offline'
    return
  }

  apiStatus.value = 'checking'

  try {
    const [healthResponse, bootstrapResponse] = await Promise.all([
      fetch('/api/health', { headers: { Accept: 'application/json' } }),
      fetch('/api/v1/bootstrap', { headers: { Accept: 'application/json' } }),
    ])

    if (!healthResponse.ok || !bootstrapResponse.ok) {
      throw new Error('Réponse API invalide')
    }

    bootstrap.value = (await bootstrapResponse.json()) as BootstrapResponse
    apiStatus.value = 'online'
    checkedAt.value = new Date()
  } catch {
    apiStatus.value = 'offline'
  }
}

function onOnline(): void {
  isOffline.value = false
  void verifyApi()
}

function onOffline(): void {
  isOffline.value = true
  apiStatus.value = 'offline'
}

onMounted(() => {
  void verifyApi()
  window.addEventListener('online', onOnline)
  window.addEventListener('offline', onOffline)
})

onBeforeUnmount(() => {
  window.removeEventListener('online', onOnline)
  window.removeEventListener('offline', onOffline)
})
</script>

<template>
  <main class="application-shell">
    <header class="topbar">
      <a class="brand" href="/" aria-label="Clientèle Group ERP">
        <img src="/brand/clientele-group-logo.webp" alt="Clientèle Group" />
        <span>ERP</span>
      </a>

      <div class="status-group">
        <span class="city">Cap-Haïtien, Haïti</span>
        <span class="timestamp">{{ formattedCapHaitienTime }}</span>
        <span class="connection" :class="apiStatus">
          <i aria-hidden="true"></i>{{ statusLabel }}
        </span>
      </div>
    </header>

    <div v-if="isOffline" class="offline-notice" role="status">
      Le mode hors ligne est détecté. Aucune opération de production n’est encore activée dans ce socle.
    </div>

    <section class="context-card" aria-labelledby="context-title">
      <div>
        <p class="eyebrow">Contexte de travail obligatoire</p>
        <h1 id="context-title">{{ bootstrap?.pilot.label ?? 'Clientèle Rent a Car' }}</h1>
        <p class="context-description">
          Pilote initial · aucun site, aucune adresse et aucun point de vente de production ne sont configurés.
        </p>
      </div>
      <span class="foundation-badge">Socle de préproduction</span>
    </section>

    <nav class="module-nav" aria-label="Modules du pilote Location de véhicules">
      <button
        v-for="section in sections"
        :key="section"
        type="button"
        :class="{ active: activeSection === section }"
        @click="activeSection = section"
      >
        {{ section }}
      </button>
    </nav>

    <section class="workspace" aria-live="polite">
      <div class="workspace-heading">
        <div>
          <p class="eyebrow">Pilote Car Rental</p>
          <h2>{{ activeSection }}</h2>
        </div>
        <button class="refresh-button" type="button" @click="verifyApi">
          Vérifier le serveur
        </button>
      </div>

      <template v-if="activeSection === 'Accueil'">
        <div class="notice-card">
          <span class="notice-icon" aria-hidden="true">01</span>
          <div>
            <h3>Base technique prête à recevoir le pilote</h3>
            <p>
              Les étapes suivantes sont l’authentification, les sociétés, les sites réels,
              le poste de location et les droits d’accès. Aucune donnée client n’est chargée ici.
            </p>
          </div>
        </div>

        <div class="metric-grid">
          <article class="metric-card">
            <p>Point de vente prévu</p>
            <strong>1</strong>
            <span>à associer à son site réel</span>
          </article>
          <article class="metric-card">
            <p>Devises de travail</p>
            <strong>HTG · USD</strong>
            <span>taux manuel avec contrôle BRH à intégrer</span>
          </article>
          <article class="metric-card">
            <p>Impression</p>
            <strong>80 mm</strong>
            <span>recette matérielle Epson TMIII à faire</span>
          </article>
        </div>

        <div class="checklist-card">
          <div>
            <p class="eyebrow">État de la fondation</p>
            <h3>Contrôles déjà disponibles</h3>
          </div>
          <ul>
            <li><span>✓</span> Application PWA mobile-first</li>
            <li><span>✓</span> API, PostgreSQL et Redis prévus derrière Traefik</li>
            <li><span>✓</span> Politique d’isolation par société définie dans PostgreSQL</li>
            <li><span>→</span> Authentification, rôles et première réservation à construire</li>
          </ul>
        </div>
      </template>

      <template v-else>
        <div class="empty-state">
          <span class="empty-state-number">{{ String(sections.indexOf(activeSection)).padStart(2, '0') }}</span>
          <h3>{{ activeSection }} arrive dans le lot Car Rental</h3>
          <p>
            Cet écran est volontairement vide : aucune réservation, véhicule, inspection,
            dépôt ou rapport fictif ne sera créé avant la configuration validée de la société et du site.
          </p>
        </div>
      </template>
    </section>

    <footer class="application-footer">
      <span>Clientèle Group ERP · {{ bootstrap?.application.version ?? '0.2.0-alpha.1' }}</span>
      <span>Interface en français · {{ bootstrap?.display.currencies.join(' · ') ?? 'HTG · USD' }}</span>
    </footer>
  </main>
</template>
