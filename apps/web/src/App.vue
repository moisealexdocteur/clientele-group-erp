<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'

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

type RentalCategory = 'suv' | 'mid_suv' | 'pickup'
type RentalLocation = 'site' | 'cap_haitien_airport' | 'custom'
type KilometerPlan = 'limited' | 'unlimited'

interface RentalVehicle {
  id: string
  code: string
  category: RentalCategory
  operational_status: string
  make: string | null
  model: string | null
  model_year: number | null
  latest_odometer_km: number
}

interface CarRentalAvailabilityResponse {
  data: RentalVehicle[]
  period: {
    pickup_at: string
    due_at: string
    timezone: string
    timezone_label: string
  }
}

interface CarRentalReservation {
  id: string
  number: string
  state: string
  site_id: string
  pickup_at: string
  due_at: string
  currency: 'HTG' | 'USD'
  daily_rate: string
  kilometer_plan: KilometerPlan
  included_km: number | null
  additional_km_rate: string | null
  vehicle: RentalVehicle | null
  customer: {
    id: string
    display_name: string
    customer_type: 'individual' | 'institution'
  } | null
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
const currentTime = ref(new Date())
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
const rentalBusy = ref(false)
const rentalMessage = ref('')
const rentalError = ref(false)
const availableVehicles = ref<RentalVehicle[]>([])
const reservationCreated = ref<CarRentalReservation | null>(null)
let clockTimer: number | undefined

const reservationForm = reactive({
  site_id: '',
  vehicle_id: '',
  category: 'suv' as RentalCategory,
  customer_type: 'individual' as 'individual' | 'institution',
  customer_name: '',
  customer_email: '',
  customer_phone: '',
  pickup_at: '',
  due_at: '',
  pickup_location_type: 'site' as RentalLocation,
  pickup_location_detail: '',
  dropoff_location_type: 'cap_haitien_airport' as RentalLocation,
  dropoff_location_detail: '',
  currency: 'USD' as 'HTG' | 'USD',
  daily_rate: '',
  kilometer_plan: 'limited' as KilometerPlan,
  included_km: '300',
  additional_km_rate: '',
})

const sections = [
  'Accueil',
  'Réservations',
  'Locations',
  'Véhicules',
  'Inspections',
  'Dépôts',
  'Rapports',
]

const categoryLabels: Record<RentalCategory, string> = {
  suv: 'SUV',
  mid_suv: 'Mid SUV',
  pickup: 'Pick-up',
}

const formattedCapHaitienTime = computed(() => {
  const date = currentTime.value
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

  return `${datePart} · ${timePart} · heure de Cap-Haïtien`
})

const statusLabel = computed(() => {
  if (isOffline.value) return 'Hors ligne'
  if (apiStatus.value === 'online') return 'Serveur disponible'
  if (apiStatus.value === 'offline') return 'Serveur indisponible'
  return 'Vérification…'
})

const activeCompanyName = computed(() => activeContext.value?.company.name ?? '')

const userInitial = computed(() => user.value?.name.slice(0, 1).toUpperCase() ?? '?')

const activeSite = computed(() => (
  activeContext.value?.sites.find((site) => site.id === reservationForm.site_id) ?? null
))

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
    currentTime.value = new Date()
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
    const context = await requestApi<CompanyContext>('/api/v1/context', {
      headers: { 'X-Clientele-Company-Id': companyId },
    })
    activeContext.value = context
    activeSection.value = 'Accueil'
    resetRentalForm(context.sites[0]?.id ?? '')
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
  resetRentalForm()
}

function contextHeaders(): HeadersInit {
  const companyId = activeContext.value?.company.id

  return companyId === undefined ? {} : { 'X-Clientele-Company-Id': companyId }
}

function resetRentalForm(siteId = ''): void {
  Object.assign(reservationForm, {
    site_id: siteId,
    vehicle_id: '',
    category: 'suv',
    customer_type: 'individual',
    customer_name: '',
    customer_email: '',
    customer_phone: '',
    pickup_at: '',
    due_at: '',
    pickup_location_type: 'site',
    pickup_location_detail: '',
    dropoff_location_type: 'cap_haitien_airport',
    dropoff_location_detail: '',
    currency: 'USD',
    daily_rate: '',
    kilometer_plan: 'limited',
    included_km: '300',
    additional_km_rate: '',
  })
  availableVehicles.value = []
  reservationCreated.value = null
  rentalMessage.value = ''
  rentalError.value = false
}

function onReservationSiteChanged(): void {
  reservationForm.vehicle_id = ''
  availableVehicles.value = []
  reservationCreated.value = null
  rentalMessage.value = ''
  rentalError.value = false
}

function selectRentalCategory(category: RentalCategory): void {
  reservationForm.category = category
  onReservationSiteChanged()
}

async function loadAvailability(): Promise<void> {
  rentalMessage.value = ''
  rentalError.value = false

  if (!reservationForm.site_id || !reservationForm.pickup_at || !reservationForm.due_at) {
    rentalError.value = true
    rentalMessage.value = 'Choisissez l’adresse de l’opération, la date de départ et la date de retour avant de vérifier la disponibilité.'
    return
  }

  rentalBusy.value = true

  try {
    const parameters = new URLSearchParams({
      site_id: reservationForm.site_id,
      pickup_at: reservationForm.pickup_at,
      due_at: reservationForm.due_at,
      category: reservationForm.category,
    })
    const result = await requestApi<CarRentalAvailabilityResponse>(`/api/v1/car-rental/availability?${parameters}`, {
      headers: contextHeaders(),
    })

    availableVehicles.value = result.data
    reservationForm.vehicle_id = result.data.some((vehicle) => vehicle.id === reservationForm.vehicle_id)
      ? reservationForm.vehicle_id
      : ''
    rentalMessage.value = result.data.length === 0
      ? 'Aucun véhicule de cette catégorie n’est disponible sur cette période pour cette adresse.'
      : `${result.data.length} véhicule${result.data.length > 1 ? 's' : ''} disponible${result.data.length > 1 ? 's' : ''} pour cette adresse.`
  } catch (error) {
    rentalError.value = true
    rentalMessage.value = messageFrom(error)
    availableVehicles.value = []
  } finally {
    rentalBusy.value = false
  }
}

async function createReservation(): Promise<void> {
  rentalMessage.value = ''
  rentalError.value = false

  if (!reservationForm.site_id || !reservationForm.customer_name.trim() || !reservationForm.pickup_at || !reservationForm.due_at || !reservationForm.daily_rate) {
    rentalError.value = true
    rentalMessage.value = 'Complétez l’adresse, le client, les dates et le tarif avant d’enregistrer la réservation.'
    return
  }

  if (!reservationForm.vehicle_id && !reservationForm.category) {
    rentalError.value = true
    rentalMessage.value = 'Choisissez une catégorie ou un véhicule disponible.'
    return
  }

  rentalBusy.value = true

  try {
    const result = await requestApi<{ data: CarRentalReservation }>('/api/v1/car-rental/reservations', {
      method: 'POST',
      headers: contextHeaders(),
      body: JSON.stringify({
        site_id: reservationForm.site_id,
        vehicle_id: reservationForm.vehicle_id || undefined,
        category: reservationForm.category,
        customer: {
          customer_type: reservationForm.customer_type,
          display_name: reservationForm.customer_name.trim(),
          email: reservationForm.customer_email.trim() || undefined,
          phone: reservationForm.customer_phone.trim() || undefined,
          group_contact_sharing_consent: false,
        },
        pickup_at: reservationForm.pickup_at,
        due_at: reservationForm.due_at,
        pickup_location_type: reservationForm.pickup_location_type,
        pickup_location_detail: reservationForm.pickup_location_type === 'custom'
          ? reservationForm.pickup_location_detail.trim()
          : undefined,
        dropoff_location_type: reservationForm.dropoff_location_type,
        dropoff_location_detail: reservationForm.dropoff_location_type === 'custom'
          ? reservationForm.dropoff_location_detail.trim()
          : undefined,
        currency: reservationForm.currency,
        daily_rate: reservationForm.daily_rate,
        kilometer_plan: reservationForm.kilometer_plan,
        included_km: reservationForm.kilometer_plan === 'limited'
          ? Number(reservationForm.included_km)
          : undefined,
        additional_km_rate: reservationForm.kilometer_plan === 'limited'
          ? reservationForm.additional_km_rate
          : undefined,
      }),
    })

    reservationCreated.value = result.data
    rentalMessage.value = `Réservation ${result.data.number} créée et journalisée.`
    rentalError.value = false
    availableVehicles.value = availableVehicles.value.filter((vehicle) => vehicle.id !== result.data.vehicle?.id)
    reservationForm.vehicle_id = ''
  } catch (error) {
    rentalError.value = true
    rentalMessage.value = messageFrom(error)
  } finally {
    rentalBusy.value = false
  }
}

function changeCompany(): void {
  activeContext.value = null
  activeSection.value = 'Accueil'
  resetRentalForm()
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
  clockTimer = window.setInterval(() => {
    currentTime.value = new Date()
  }, 30_000)
  window.addEventListener('online', onOnline)
  window.addEventListener('offline', onOffline)
})

onBeforeUnmount(() => {
  if (clockTimer !== undefined) {
    window.clearInterval(clockTimer)
  }
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
          <button class="text-button change-company" type="button" @click="changeCompany">Changer de société</button>
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
                <h3>Réservations sécurisées par société et adresse</h3>
                <p>
                  La session est limitée à la société choisie et aux adresses autorisées. Le premier flux
                  permet maintenant de vérifier la disponibilité et de créer une réservation numérotée.
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
                <span>tarif de location saisi dans la devise choisie</span>
              </article>
              <article class="metric-card">
                <p>Référence de réservation</p>
                <strong>0000 0001</strong>
                <span>huit chiffres, séquentiels par société</span>
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
                <li><span>✓</span> Disponibilité et réservation de SUV, Mid SUV et Pick-up</li>
                <li><span>→</span> Contrat, inspection, dépôt et reçu suivront dans les prochains lots</li>
              </ul>
            </div>
          </template>

          <template v-else-if="activeSection === 'Réservations'">
            <div class="reservation-workspace">
              <section class="reservation-form-card" aria-labelledby="reservation-title">
                <div class="section-intro">
                  <p class="eyebrow">Nouvelle réservation</p>
                  <h3 id="reservation-title">Réserver à partir d’une adresse réelle</h3>
                  <p>
                    Les véhicules proposés appartiennent uniquement à la société et à l’adresse de travail
                    sélectionnées. Une réservation concurrente est refusée par le serveur.
                  </p>
                </div>

                <form class="reservation-form" @submit.prevent="createReservation">
                  <fieldset>
                    <legend>1 · Lieu et période</legend>
                    <label>
                      Adresse de l’opération
                      <select v-model="reservationForm.site_id" required :disabled="rentalBusy" @change="onReservationSiteChanged">
                        <option value="" disabled>Choisissez une adresse autorisée</option>
                        <option v-for="site in activeContext.sites" :key="site.id" :value="site.id">
                          {{ site.name }} · {{ site.address }}
                        </option>
                      </select>
                    </label>
                    <p v-if="activeSite" class="site-context">
                      Société : <strong>{{ activeContext.company.name }}</strong><br />
                      Adresse active : <strong>{{ activeSite.name }} · {{ activeSite.address }}</strong>
                    </p>
                    <div class="two-columns">
                      <label>
                        Départ prévu
                        <input v-model="reservationForm.pickup_at" type="datetime-local" required :disabled="rentalBusy" />
                      </label>
                      <label>
                        Retour prévu
                        <input v-model="reservationForm.due_at" type="datetime-local" required :disabled="rentalBusy" />
                      </label>
                    </div>
                  </fieldset>

                  <fieldset>
                    <legend>2 · Véhicule disponible</legend>
                    <div class="category-choice" role="group" aria-label="Catégorie de véhicule">
                      <button
                        v-for="(label, category) in categoryLabels"
                        :key="category"
                        type="button"
                        :class="{ selected: reservationForm.category === category }"
                        :disabled="rentalBusy"
                        @click="selectRentalCategory(category)"
                      >
                        {{ label }}
                      </button>
                    </div>
                    <button class="availability-button" type="button" :disabled="rentalBusy || apiStatus !== 'online'" @click="loadAvailability">
                      {{ rentalBusy ? 'Vérification…' : 'Voir les véhicules disponibles' }}
                    </button>
                    <div v-if="availableVehicles.length" class="vehicle-list" aria-label="Véhicules disponibles">
                      <button
                        v-for="vehicle in availableVehicles"
                        :key="vehicle.id"
                        class="vehicle-choice"
                        type="button"
                        :class="{ selected: reservationForm.vehicle_id === vehicle.id }"
                        :disabled="rentalBusy"
                        @click="reservationForm.vehicle_id = vehicle.id"
                      >
                        <span>{{ vehicle.code }}</span>
                        <strong>{{ vehicle.make }} {{ vehicle.model }}</strong>
                        <small>{{ categoryLabels[vehicle.category] }} · {{ vehicle.latest_odometer_km.toLocaleString('fr-FR') }} km</small>
                      </button>
                    </div>
                    <p v-else class="field-help">Sélectionnez une période puis vérifiez les disponibilités avant de choisir le véhicule.</p>
                  </fieldset>

                  <fieldset>
                    <legend>3 · Client et locations</legend>
                    <div class="two-columns">
                      <label>
                        Type de client
                        <select v-model="reservationForm.customer_type" :disabled="rentalBusy">
                          <option value="individual">Particulier</option>
                          <option value="institution">Institution</option>
                        </select>
                      </label>
                      <label>
                        Nom complet ou raison sociale
                        <input v-model.trim="reservationForm.customer_name" type="text" autocomplete="name" maxlength="160" required :disabled="rentalBusy" />
                      </label>
                    </div>
                    <div class="two-columns">
                      <label>
                        Courriel (facultatif)
                        <input v-model.trim="reservationForm.customer_email" type="email" autocomplete="email" maxlength="254" :disabled="rentalBusy" />
                      </label>
                      <label>
                        Téléphone (facultatif)
                        <input v-model.trim="reservationForm.customer_phone" type="tel" autocomplete="tel" maxlength="64" :disabled="rentalBusy" />
                      </label>
                    </div>
                  </fieldset>

                  <fieldset>
                    <legend>4 · Départ, retour et tarif</legend>
                    <div class="two-columns">
                      <label>
                        Lieu de départ
                        <select v-model="reservationForm.pickup_location_type" :disabled="rentalBusy">
                          <option value="site">Adresse de l’opération</option>
                          <option value="cap_haitien_airport">Aéroport International du Cap-Haïtien</option>
                          <option value="custom">Autre lieu précisé</option>
                        </select>
                      </label>
                      <label>
                        Lieu de retour
                        <select v-model="reservationForm.dropoff_location_type" :disabled="rentalBusy">
                          <option value="site">Adresse de l’opération</option>
                          <option value="cap_haitien_airport">Aéroport International du Cap-Haïtien</option>
                          <option value="custom">Autre lieu précisé</option>
                        </select>
                      </label>
                    </div>
                    <div v-if="reservationForm.pickup_location_type === 'custom' || reservationForm.dropoff_location_type === 'custom'" class="two-columns">
                      <label v-if="reservationForm.pickup_location_type === 'custom'">
                        Précision du départ
                        <input v-model.trim="reservationForm.pickup_location_detail" type="text" maxlength="1000" required :disabled="rentalBusy" />
                      </label>
                      <label v-if="reservationForm.dropoff_location_type === 'custom'">
                        Précision du retour
                        <input v-model.trim="reservationForm.dropoff_location_detail" type="text" maxlength="1000" required :disabled="rentalBusy" />
                      </label>
                    </div>
                    <div class="three-columns">
                      <label>
                        Devise du tarif
                        <select v-model="reservationForm.currency" :disabled="rentalBusy">
                          <option value="USD">USD</option>
                          <option value="HTG">HTG</option>
                        </select>
                      </label>
                      <label>
                        Tarif journalier
                        <input v-model="reservationForm.daily_rate" type="number" inputmode="decimal" min="0" step="0.01" required :disabled="rentalBusy" />
                      </label>
                      <label>
                        Kilométrage
                        <select v-model="reservationForm.kilometer_plan" :disabled="rentalBusy">
                          <option value="limited">Limité</option>
                          <option value="unlimited">Illimité</option>
                        </select>
                      </label>
                    </div>
                    <div v-if="reservationForm.kilometer_plan === 'limited'" class="two-columns">
                      <label>
                        Kilomètres inclus
                        <input v-model="reservationForm.included_km" type="number" inputmode="numeric" min="0" step="1" required :disabled="rentalBusy" />
                      </label>
                      <label>
                        Tarif par kilomètre supplémentaire
                        <input v-model="reservationForm.additional_km_rate" type="number" inputmode="decimal" min="0" step="0.01" required :disabled="rentalBusy" />
                      </label>
                    </div>
                  </fieldset>

                  <p v-if="rentalMessage" class="rental-message" :class="{ error: rentalError }" role="status">{{ rentalMessage }}</p>
                  <button class="primary-button create-reservation" type="submit" :disabled="rentalBusy || apiStatus !== 'online'">
                    {{ rentalBusy ? 'Enregistrement…' : 'Créer la réservation numérotée' }}
                  </button>
                </form>
              </section>

              <aside class="reservation-summary" aria-label="Résumé de sécurité de la réservation">
                <p class="eyebrow">Contrôles actifs</p>
                <h3>Ce que le système vérifie</h3>
                <ul>
                  <li><span>✓</span> La société sélectionnée par la session</li>
                  <li><span>✓</span> L’adresse précise autorisée à l’utilisateur</li>
                  <li><span>✓</span> L’absence de chevauchement de réservation</li>
                  <li><span>✓</span> Une référence à huit chiffres journalisée</li>
                </ul>
                <p class="summary-note">
                  Le dépôt de garantie, le contrat et les inspections sont volontairement séparés : ils seront ajoutés sans exposer les données d’une autre société.
                </p>

                <div v-if="reservationCreated" class="reservation-created">
                  <p class="eyebrow">Réservation créée</p>
                  <strong>{{ reservationCreated.number }}</strong>
                  <span>{{ reservationCreated.customer?.display_name }}</span>
                  <span>{{ reservationCreated.vehicle?.code }} · {{ reservationCreated.currency }} {{ reservationCreated.daily_rate }} / jour</span>
                  <small>Statut : {{ reservationCreated.state }}</small>
                </div>
              </aside>
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
      <span>Clientèle Group ERP · {{ bootstrap?.application.version ?? '0.2.0-alpha.4' }}</span>
      <span>HTG · USD · Cap-Haïtien, Haïti</span>
    </footer>
  </main>
</template>
