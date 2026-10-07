<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'

type ApiStatus = 'checking' | 'online' | 'offline'
type AuthView = 'sign-in' | 'verify' | 'reset-request' | 'reset-confirm' | 'authenticated'

interface BootstrapResponse {
  application: {
    name: string
    environment: string
    version: string
  }
  display: {
    timezone: string
    timezone_label: string
    currencies: string[]
  }
  pilot: {
    label: string
    state: string
  }
}

interface SessionUser {
  id: string
  name: string
  email: string
  system_role: string
  two_factor_email_verified: boolean
}

interface CompanyChoice {
  id: string
  code: string
  name: string
  role_key: string
}

interface CompanyContext {
  company: {
    id: string
    code: string
    name: string
    timezone: string
    timezone_label: string
    base_currency: 'HTG' | 'USD'
  }
  access: {
    role_key: string
    site_scope: 'all' | 'selected'
    permissions: string[] | { allow?: string[] }
  }
  sites: Array<{
    id: string
    code: string
    name: string
    address: string
  }>
}

class ApiError extends Error {
  constructor(
    message: string,
    readonly status: number,
  ) {
    super(message)
  }
}

const activeSection = ref('Accueil')
const apiStatus = ref<ApiStatus>('checking')
const isOffline = ref(!navigator.onLine)
const bootstrap = ref<BootstrapResponse | null>(null)
const checkedAt = ref<Date | null>(null)
const authView = ref<AuthView>('sign-in')
const authMessage = ref('')
const authBusy = ref(false)
const email = ref('')
const password = ref('')
const passwordConfirmation = ref('')
const emailCode = ref('')
const challengeId = ref('')
const sessionToken = ref(sessionStorage.getItem('clientele.erp.session') ?? '')
const user = ref<SessionUser | null>(null)
const companies = ref<CompanyChoice[]>([])
const activeContext = ref<CompanyContext | null>(null)

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

const activeCompanyName = computed(() => activeContext.value?.company.name ?? '')

const userInitial = computed(() => user.value?.name.slice(0, 1).toUpperCase() ?? '?')

async function requestApi<T>(path: string, options: RequestInit = {}): Promise<T> {
  const headers = new Headers(options.headers)
  headers.set('Accept', 'application/json')

  if (options.body !== undefined) {
    headers.set('Content-Type', 'application/json')
  }

  if (sessionToken.value) {
    headers.set('Authorization', `Bearer ${sessionToken.value}`)
  }

  const response = await fetch(path, { ...options, headers })
  const payload = (await response.json().catch(() => ({}))) as { message?: string }

  if (!response.ok) {
    throw new ApiError(payload.message ?? 'La demande ne peut pas être traitée.', response.status)
  }

  return payload as T
}

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

async function signIn(): Promise<void> {
  authMessage.value = ''
  authBusy.value = true

  try {
    const result = await requestApi<{ challenge_id: string }>('/api/v1/auth/login', {
      method: 'POST',
      body: JSON.stringify({
        email: email.value,
        password: password.value,
        device_name: kioskLabel(),
      }),
    })

    challengeId.value = result.challenge_id
    password.value = ''
    emailCode.value = ''
    authView.value = 'verify'
    authMessage.value = 'Un code à six chiffres vient d’être envoyé à votre adresse personnelle.'
  } catch (error) {
    authMessage.value = messageFrom(error)
  } finally {
    authBusy.value = false
  }
}

async function verifyEmailCode(): Promise<void> {
  authMessage.value = ''
  authBusy.value = true

  try {
    const result = await requestApi<{ token: string; user: SessionUser }>('/api/v1/auth/login/verify', {
      method: 'POST',
      body: JSON.stringify({
        challenge_id: challengeId.value,
        code: emailCode.value,
        device_name: kioskLabel(),
      }),
    })

    sessionToken.value = result.token
    sessionStorage.setItem('clientele.erp.session', result.token)
    user.value = result.user
    emailCode.value = ''
    await loadSession()
  } catch (error) {
    authMessage.value = messageFrom(error)
  } finally {
    authBusy.value = false
  }
}

async function requestPasswordReset(): Promise<void> {
  authMessage.value = ''
  authBusy.value = true

  try {
    const result = await requestApi<{ message: string; challenge_id: string }>('/api/v1/auth/password/forgot', {
      method: 'POST',
      body: JSON.stringify({ email: email.value }),
    })

    challengeId.value = result.challenge_id
    password.value = ''
    passwordConfirmation.value = ''
    emailCode.value = ''
    authView.value = 'reset-confirm'
    authMessage.value = result.message
  } catch (error) {
    authMessage.value = messageFrom(error)
  } finally {
    authBusy.value = false
  }
}

async function resetPassword(): Promise<void> {
  authMessage.value = ''
  authBusy.value = true

  try {
    const result = await requestApi<{ message: string }>('/api/v1/auth/password/reset', {
      method: 'POST',
      body: JSON.stringify({
        challenge_id: challengeId.value,
        code: emailCode.value,
        password: password.value,
        password_confirmation: passwordConfirmation.value,
      }),
    })

    password.value = ''
    passwordConfirmation.value = ''
    emailCode.value = ''
    authView.value = 'sign-in'
    authMessage.value = result.message
  } catch (error) {
    authMessage.value = messageFrom(error)
  } finally {
    authBusy.value = false
  }
}

async function loadSession(): Promise<void> {
  if (!sessionToken.value) {
    return
  }

  try {
    const result = await requestApi<{ user: SessionUser; companies: CompanyChoice[] }>('/api/v1/auth/me')
    user.value = result.user
    companies.value = result.companies
    authView.value = 'authenticated'

    if (companies.value.length === 1) {
      await selectCompany(companies.value[0].id)
    }
  } catch {
    clearSession()
    authMessage.value = 'Votre session a expiré. Connectez-vous de nouveau.'
  }
}

async function selectCompany(companyId: string): Promise<void> {
  authMessage.value = ''
  authBusy.value = true

  try {
    activeContext.value = await requestApi<CompanyContext>('/api/v1/context', {
      headers: { 'X-Clientele-Company-Id': companyId },
    })
  } catch (error) {
    authMessage.value = messageFrom(error)
  } finally {
    authBusy.value = false
  }
}

async function logout(): Promise<void> {
  try {
    await requestApi('/api/v1/auth/logout', { method: 'POST' })
  } catch {
    // La suppression locale reste nécessaire même si le réseau a été coupé.
  }

  clearSession()
}

function clearSession(): void {
  sessionStorage.removeItem('clientele.erp.session')
  sessionToken.value = ''
  user.value = null
  companies.value = []
  activeContext.value = null
  authView.value = 'sign-in'
  activeSection.value = 'Accueil'
}

function kioskLabel(): string {
  return `${navigator.platform || 'Poste'} · ${navigator.userAgent.slice(0, 64)}`
}

function messageFrom(error: unknown): string {
  if (error instanceof ApiError) {
    return error.message
  }

  return 'La connexion au serveur a échoué. Vérifiez Internet puis réessayez.'
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
  void loadSession()
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
      Le mode hors ligne est détecté. La connexion et les opérations non synchronisées restent bloquées dans cette version.
    </div>

    <section v-if="authView !== 'authenticated'" class="access-layout" aria-labelledby="access-title">
      <div class="access-intro">
        <p class="eyebrow">Accès personnel obligatoire</p>
        <h1 id="access-title">Un poste sécurisé, une personne identifiée.</h1>
        <p>
          Sélection de société, adresse et données clients restent invisibles avant la connexion.
          Le code reçu par courriel personnel confirme chaque ouverture de session.
        </p>
        <ul class="access-points">
          <li><span>01</span> Compte individuel et mot de passe protégé</li>
          <li><span>02</span> Code de sécurité à usage unique</li>
          <li><span>03</span> Société et site choisis après connexion</li>
        </ul>
      </div>

      <section class="access-card" aria-live="polite">
        <template v-if="authView === 'sign-in'">
          <p class="eyebrow">Connexion</p>
          <h2>Ouvrir la session</h2>
          <p class="access-description">Utilisez votre adresse courriel personnelle et votre mot de passe.</p>

          <form class="access-form" @submit.prevent="signIn">
            <label>
              Adresse courriel personnelle
              <input v-model.trim="email" type="email" autocomplete="username" required :disabled="authBusy" />
            </label>
            <label>
              Mot de passe
              <input v-model="password" type="password" autocomplete="current-password" required :disabled="authBusy" />
            </label>
            <p v-if="authMessage" class="form-message">{{ authMessage }}</p>
            <button class="primary-button" type="submit" :disabled="authBusy || apiStatus !== 'online'">
              {{ authBusy ? 'Vérification…' : 'Continuer' }}
            </button>
            <button class="text-button" type="button" :disabled="authBusy" @click="authView = 'reset-request'; authMessage = ''">
              J’ai oublié mon mot de passe
            </button>
          </form>
        </template>

        <template v-else-if="authView === 'verify'">
          <p class="eyebrow">Vérification en deux étapes</p>
          <h2>Entrez le code reçu</h2>
          <p class="access-description">Le code est lié à {{ email }} et expire rapidement.</p>

          <form class="access-form" @submit.prevent="verifyEmailCode">
            <label>
              Code à six chiffres
              <input v-model.trim="emailCode" class="code-input" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required :disabled="authBusy" />
            </label>
            <p v-if="authMessage" class="form-message">{{ authMessage }}</p>
            <button class="primary-button" type="submit" :disabled="authBusy || apiStatus !== 'online'">
              {{ authBusy ? 'Ouverture…' : 'Ouvrir la session' }}
            </button>
            <button class="text-button" type="button" :disabled="authBusy" @click="authView = 'sign-in'; authMessage = ''">
              Revenir à la connexion
            </button>
          </form>
        </template>

        <template v-else-if="authView === 'reset-request'">
          <p class="eyebrow">Réinitialisation</p>
          <h2>Recevoir un code</h2>
          <p class="access-description">Un code sera envoyé si cette adresse correspond à un compte actif.</p>

          <form class="access-form" @submit.prevent="requestPasswordReset">
            <label>
              Adresse courriel personnelle
              <input v-model.trim="email" type="email" autocomplete="username" required :disabled="authBusy" />
            </label>
            <p v-if="authMessage" class="form-message">{{ authMessage }}</p>
            <button class="primary-button" type="submit" :disabled="authBusy || apiStatus !== 'online'">
              {{ authBusy ? 'Envoi…' : 'Envoyer le code' }}
            </button>
            <button class="text-button" type="button" :disabled="authBusy" @click="authView = 'sign-in'; authMessage = ''">
              Revenir à la connexion
            </button>
          </form>
        </template>

        <template v-else-if="authView === 'reset-confirm'">
          <p class="eyebrow">Nouveau mot de passe</p>
          <h2>Confirmer le code</h2>
          <p class="access-description">Choisissez un mot de passe de 12 caractères ou plus, avec majuscule, chiffre et symbole.</p>

          <form class="access-form" @submit.prevent="resetPassword">
            <label>
              Code à six chiffres
              <input v-model.trim="emailCode" class="code-input" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required :disabled="authBusy" />
            </label>
            <label>
              Nouveau mot de passe
              <input v-model="password" type="password" autocomplete="new-password" required :disabled="authBusy" />
            </label>
            <label>
              Confirmer le nouveau mot de passe
              <input v-model="passwordConfirmation" type="password" autocomplete="new-password" required :disabled="authBusy" />
            </label>
            <p v-if="authMessage" class="form-message">{{ authMessage }}</p>
            <button class="primary-button" type="submit" :disabled="authBusy || apiStatus !== 'online'">
              {{ authBusy ? 'Enregistrement…' : 'Réinitialiser le mot de passe' }}
            </button>
          </form>
        </template>
      </section>
    </section>

    <template v-else>
      <section v-if="!activeContext" class="company-choice" aria-labelledby="company-choice-title">
        <p class="eyebrow">Session ouverte · {{ user?.name }}</p>
        <h1 id="company-choice-title">Choisissez votre société de travail.</h1>
        <p>Cette sélection détermine les données, les sites et les fonctions autorisés pour cette session.</p>
        <div v-if="companies.length" class="company-grid">
          <button v-for="company in companies" :key="company.id" class="company-button" type="button" :disabled="authBusy" @click="selectCompany(company.id)">
            <span>{{ company.code }}</span>
            <strong>{{ company.name }}</strong>
            <small>Rôle : {{ company.role_key }}</small>
          </button>
        </div>
        <p v-else class="form-message">Aucune société active n’est encore attribuée à votre compte.</p>
        <p v-if="authMessage" class="form-message">{{ authMessage }}</p>
        <button class="text-button" type="button" @click="logout">Fermer la session</button>
      </section>

      <template v-else>
        <section class="session-strip" aria-label="Session active">
          <span class="avatar" aria-hidden="true">{{ userInitial }}</span>
          <span><strong>{{ user?.name }}</strong> · {{ activeCompanyName }}</span>
          <button class="text-button" type="button" @click="logout">Fermer la session</button>
        </section>

        <section class="context-card" aria-labelledby="context-title">
          <div>
            <p class="eyebrow">Société active · {{ activeContext.company.code }}</p>
            <h1 id="context-title">{{ activeCompanyName }}</h1>
            <p class="context-description">
              {{ activeContext.sites.length }} site{{ activeContext.sites.length > 1 ? 's' : '' }} autorisé{{ activeContext.sites.length > 1 ? 's' : '' }} · rôle {{ activeContext.access.role_key }} · devise de base {{ activeContext.company.base_currency }}.
            </p>
          </div>
          <span class="foundation-badge">Pilote {{ bootstrap?.pilot.label ?? 'Car Rental' }}</span>
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
              <p class="eyebrow">Pilote Car Rental · {{ activeContext.company.timezone_label }}</p>
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
                <h3>Accès et isolation de société activés</h3>
                <p>
                  La session est limitée à la société choisie et à ses sites autorisés. Les réservations,
                  véhicules, dépôts et inspections seront ajoutés dans le prochain lot Car Rental.
                </p>
              </div>
            </div>

            <div class="metric-grid">
              <article class="metric-card">
                <p>Sites autorisés</p>
                <strong>{{ activeContext.sites.length }}</strong>
                <span>{{ activeContext.sites.map((site) => site.name).join(' · ') || 'Aucun site attribué' }}</span>
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
                <li><span>✓</span> Code par courriel personnel et session révocable</li>
                <li><span>✓</span> Rôle, société active et périmètre de site</li>
                <li><span>✓</span> Journal d’audit sans mot de passe ni jeton</li>
                <li><span>→</span> Réservations, véhicules et inspections à construire</li>
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
      </template>
    </template>

    <footer class="application-footer">
      <span>Clientèle Group ERP · {{ bootstrap?.application.version ?? '0.2.0-alpha.3' }}</span>
      <span>HTG · USD · Cap-Haïtien, Haïti</span>
    </footer>
  </main>
</template>
