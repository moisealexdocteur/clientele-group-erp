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
type VehicleOperationalStatus = 'available' | 'preparation' | 'washing' | 'garage' | 'in_circulation'

interface RentalSite {
  id: string
  code: string
  name: string
}

interface RentalVehicle {
  id: string
  site_id: string
  site: RentalSite | null
  code: string
  category: RentalCategory
  operational_status: VehicleOperationalStatus
  make: string | null
  model: string | null
  model_year: number | null
  latest_odometer_km: number
  is_active: boolean
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

interface CarRentalVehicleListResponse {
  data: RentalVehicle[]
}

interface CarRentalCalendarEntry {
  id: string
  number: string
  state: 'reserved' | 'checked_out'
  pickup_at: string
  due_at: string
  site: RentalSite | null
  vehicle: RentalVehicle | null
}

interface CarRentalCalendarResponse {
  data: CarRentalCalendarEntry[]
  vehicles: RentalVehicle[]
  period: {
    from: string
    to: string
    timezone: string
    timezone_label: string
  }
}

interface SystemCashRegister {
  id: string
  site_id: string
  code: string
  name: string
  automatic_print_enabled: boolean
  customer_display_enabled: boolean
  is_active: boolean
}

interface SystemSite {
  id: string
  code: string
  name: string
  address: string
  is_active: boolean
  cash_registers: SystemCashRegister[]
}

interface SystemCompany {
  id: string
  code: string
  legal_name: string
  display_name: string
  base_currency: 'HTG' | 'USD'
  timezone: string
  timezone_label: string
  is_active: boolean
  sites: SystemSite[]
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
const vehicleBusy = ref(false)
const vehicleMessage = ref('')
const vehicleError = ref(false)
const managedVehicles = ref<RentalVehicle[]>([])
const calendarBusy = ref(false)
const calendarMessage = ref('')
const calendarError = ref(false)
const calendarEntries = ref<CarRentalCalendarEntry[]>([])
const calendarVehicles = ref<RentalVehicle[]>([])
const showSystemConfiguration = ref(false)
const configurationBusy = ref(false)
const configurationMessage = ref('')
const configurationError = ref(false)
const configurationCompanies = ref<SystemCompany[]>([])
const configurationCompanyId = ref('')
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

const vehicleForm = reactive({
  site_id: '',
  code: '',
  category: 'suv' as RentalCategory,
  operational_status: 'available' as VehicleOperationalStatus,
  make: '',
  model: '',
  model_year: '',
  registration_number: '',
  vin: '',
  latest_odometer_km: '0',
})

const vehicleFilters = reactive({
  site_id: '',
  operational_status: '',
})

const calendarForm = reactive({
  site_id: '',
  from: '',
  to: '',
})

const companyConfigurationForm = reactive({
  code: '',
  legal_name: '',
  display_name: '',
  base_currency: 'HTG' as 'HTG' | 'USD',
})

const siteConfigurationForm = reactive({
  code: '',
  name: '',
  address: '',
})

const cashRegisterConfigurationForm = reactive({
  site_id: '',
  code: '',
  name: '',
  automatic_print_enabled: false,
  customer_display_enabled: false,
})

const sections = [
  'Accueil',
  'Réservations',
  'Calendrier',
  'Véhicules',
]

const categoryLabels: Record<RentalCategory, string> = {
  suv: 'SUV',
  mid_suv: 'Mid SUV',
  pickup: 'Pick-up',
}

const vehicleStatusLabels: Record<VehicleOperationalStatus, string> = {
  available: 'Disponible',
  preparation: 'Préparation',
  washing: 'Lavage',
  garage: 'Garage',
  in_circulation: 'En circulation',
}

const reservationStateLabels: Record<CarRentalCalendarEntry['state'], string> = {
  reserved: 'Réservée',
  checked_out: 'En circulation',
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

const canManageSystemConfiguration = computed(() => user.value?.system_role === 'owner')

const selectedConfigurationCompany = computed(() => (
  configurationCompanies.value.find((company) => company.id === configurationCompanyId.value) ?? null
))

const canReadVehicles = computed(() => hasPermission('rental.vehicles.read'))
const canManageVehicles = computed(() => hasPermission('rental.vehicles.manage'))
const canReadCalendar = computed(() => hasPermission('rental.calendar.read'))
const visibleSections = computed(() => sections.filter((section) => {
  if (section === 'Véhicules') return canReadVehicles.value || canManageVehicles.value
  if (section === 'Calendrier') return canReadCalendar.value

  return true
}))

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
    await loadCompanyChoices()
    authView.value = 'authenticated'

    if (companies.value.length === 1 && !showSystemConfiguration.value) {
      await selectCompany(companies.value[0].id)
    }
  } catch {
    clearSession()
    authMessage.value = 'Votre session a expiré. Connectez-vous de nouveau.'
  }
}

async function loadCompanyChoices(): Promise<void> {
  const result = await requestApi<{ user: SessionUser; companies: CompanyChoice[] }>('/api/v1/auth/me')
  user.value = result.user
  companies.value = result.companies
}

async function selectCompany(companyId: string): Promise<void> {
  authMessage.value = ''
  authBusy.value = true

  try {
    const context = await requestApi<CompanyContext>('/api/v1/context', {
      headers: { 'X-Clientele-Company-Id': companyId },
    })
    activeContext.value = context
    showSystemConfiguration.value = false
    activeSection.value = 'Accueil'
    resetRentalForm(context.sites[0]?.id ?? '')
    resetVehicleWorkspace(context.sites[0]?.id ?? '')
  } catch (error) {
    authMessage.value = messageFrom(error)
  } finally {
    authBusy.value = false
  }
}

async function openSystemConfiguration(): Promise<void> {
  if (!canManageSystemConfiguration.value) {
    return
  }

  activeContext.value = null
  showSystemConfiguration.value = true
  configurationMessage.value = ''
  configurationError.value = false
  await loadSystemConfiguration()
}

function closeSystemConfiguration(): void {
  showSystemConfiguration.value = false
  configurationMessage.value = ''
  configurationError.value = false
}

function selectConfigurationCompany(): void {
  cashRegisterConfigurationForm.site_id = selectedConfigurationCompany.value?.sites[0]?.id ?? ''
}

async function loadSystemConfiguration(): Promise<void> {
  if (!canManageSystemConfiguration.value) {
    return
  }

  configurationBusy.value = true

  try {
    const result = await requestApi<{ data: SystemCompany[] }>('/api/v1/system/configuration/companies')
    configurationCompanies.value = result.data

    if (!configurationCompanyId.value || !result.data.some((company) => company.id === configurationCompanyId.value)) {
      configurationCompanyId.value = result.data[0]?.id ?? ''
    }

    const selectedCompany = result.data.find((company) => company.id === configurationCompanyId.value)
    if (!selectedCompany?.sites.some((site) => site.id === cashRegisterConfigurationForm.site_id)) {
      cashRegisterConfigurationForm.site_id = selectedCompany?.sites[0]?.id ?? ''
    }
  } catch (error) {
    configurationError.value = true
    configurationMessage.value = messageFrom(error)
  } finally {
    configurationBusy.value = false
  }
}

async function createSystemCompany(): Promise<void> {
  configurationBusy.value = true
  configurationMessage.value = ''
  configurationError.value = false

  try {
    const result = await requestApi<{ data: SystemCompany }>('/api/v1/system/configuration/companies', {
      method: 'POST',
      body: JSON.stringify(companyConfigurationForm),
    })

    configurationCompanyId.value = result.data.id
    Object.assign(companyConfigurationForm, {
      code: '',
      legal_name: '',
      display_name: '',
      base_currency: 'HTG',
    })
    await Promise.all([loadSystemConfiguration(), loadCompanyChoices()])
    configurationMessage.value = 'La société a été créée. Ajoutez maintenant son adresse opérationnelle.'
  } catch (error) {
    configurationError.value = true
    configurationMessage.value = messageFrom(error)
  } finally {
    configurationBusy.value = false
  }
}

async function createSystemSite(): Promise<void> {
  const companyId = configurationCompanyId.value
  if (!companyId) {
    configurationError.value = true
    configurationMessage.value = 'Créez ou sélectionnez d’abord une société.'
    return
  }

  configurationBusy.value = true
  configurationMessage.value = ''
  configurationError.value = false

  try {
    const result = await requestApi<{ data: SystemSite }>(`/api/v1/system/configuration/companies/${companyId}/sites`, {
      method: 'POST',
      body: JSON.stringify(siteConfigurationForm),
    })

    Object.assign(siteConfigurationForm, { code: '', name: '', address: '' })
    cashRegisterConfigurationForm.site_id = result.data.id
    await loadSystemConfiguration()
    configurationMessage.value = 'L’adresse opérationnelle a été créée.'
  } catch (error) {
    configurationError.value = true
    configurationMessage.value = messageFrom(error)
  } finally {
    configurationBusy.value = false
  }
}

async function createSystemCashRegister(): Promise<void> {
  const companyId = configurationCompanyId.value
  if (!companyId || !cashRegisterConfigurationForm.site_id) {
    configurationError.value = true
    configurationMessage.value = 'Sélectionnez une société et une adresse avant de créer une caisse.'
    return
  }

  configurationBusy.value = true
  configurationMessage.value = ''
  configurationError.value = false

  try {
    await requestApi<{ data: SystemCashRegister }>(`/api/v1/system/configuration/companies/${companyId}/cash-registers`, {
      method: 'POST',
      body: JSON.stringify(cashRegisterConfigurationForm),
    })

    Object.assign(cashRegisterConfigurationForm, {
      site_id: cashRegisterConfigurationForm.site_id,
      code: '',
      name: '',
      automatic_print_enabled: false,
      customer_display_enabled: false,
    })
    await loadSystemConfiguration()
    configurationMessage.value = 'La caisse a été créée.'
  } catch (error) {
    configurationError.value = true
    configurationMessage.value = messageFrom(error)
  } finally {
    configurationBusy.value = false
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
  showSystemConfiguration.value = false
  configurationCompanies.value = []
  configurationCompanyId.value = ''
  authView.value = 'sign-in'
  activeSection.value = 'Accueil'
  resetRentalForm()
  resetVehicleWorkspace()
}

function contextHeaders(): HeadersInit {
  const companyId = activeContext.value?.company.id

  return companyId === undefined ? {} : { 'X-Clientele-Company-Id': companyId }
}

function hasPermission(permission: string): boolean {
  const permissions = activeContext.value?.access.permissions
  const allowed = Array.isArray(permissions) ? permissions : (permissions?.allow ?? [])

  return allowed.includes('*') || allowed.includes(permission)
}

function capHaitienDateInput(dayOffset = 0): string {
  const date = new Date()
  date.setUTCDate(date.getUTCDate() + dayOffset)

  const values = Object.fromEntries(
    new Intl.DateTimeFormat('en-US', {
      timeZone: 'America/Port-au-Prince',
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
    })
      .formatToParts(date)
      .filter((part) => part.type !== 'literal')
      .map((part) => [part.type, part.value]),
  )

  return `${values.year}-${values.month}-${values.day}`
}

function formatCapHaitienDateTime(value: string): string {
  const date = new Date(value)
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
}

function vehicleDisplayName(vehicle: RentalVehicle): string {
  return [vehicle.make, vehicle.model].filter((value): value is string => Boolean(value)).join(' ') || 'Modèle non renseigné'
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

function resetVehicleWorkspace(siteId = ''): void {
  Object.assign(vehicleForm, {
    site_id: siteId,
    code: '',
    category: 'suv',
    operational_status: 'available',
    make: '',
    model: '',
    model_year: '',
    registration_number: '',
    vin: '',
    latest_odometer_km: '0',
  })
  Object.assign(vehicleFilters, {
    site_id: siteId,
    operational_status: '',
  })
  Object.assign(calendarForm, {
    site_id: '',
    from: capHaitienDateInput(),
    to: capHaitienDateInput(7),
  })
  managedVehicles.value = []
  calendarEntries.value = []
  calendarVehicles.value = []
  vehicleMessage.value = ''
  vehicleError.value = false
  calendarMessage.value = ''
  calendarError.value = false
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

async function loadVehicles(): Promise<void> {
  vehicleMessage.value = ''
  vehicleError.value = false

  if (!canReadVehicles.value) {
    vehicleError.value = true
    vehicleMessage.value = 'Vous n’êtes pas autorisé à consulter les véhicules.'
    return
  }

  vehicleBusy.value = true

  try {
    const parameters = new URLSearchParams()
    if (vehicleFilters.site_id) parameters.set('site_id', vehicleFilters.site_id)
    if (vehicleFilters.operational_status) parameters.set('operational_status', vehicleFilters.operational_status)

    const result = await requestApi<CarRentalVehicleListResponse>(`/api/v1/car-rental/vehicles?${parameters}`, {
      headers: contextHeaders(),
    })
    managedVehicles.value = result.data
    vehicleMessage.value = result.data.length === 0
      ? 'Aucun véhicule ne correspond aux critères sélectionnés.'
      : `${result.data.length} véhicule${result.data.length > 1 ? 's' : ''} affiché${result.data.length > 1 ? 's' : ''}.`
  } catch (error) {
    vehicleError.value = true
    vehicleMessage.value = messageFrom(error)
    managedVehicles.value = []
  } finally {
    vehicleBusy.value = false
  }
}

async function createVehicle(): Promise<void> {
  vehicleMessage.value = ''
  vehicleError.value = false

  if (!canManageVehicles.value) {
    vehicleError.value = true
    vehicleMessage.value = 'Vous n’êtes pas autorisé à ajouter un véhicule.'
    return
  }

  if (!vehicleForm.site_id || !vehicleForm.code.trim() || vehicleForm.latest_odometer_km === '') {
    vehicleError.value = true
    vehicleMessage.value = 'Renseignez l’adresse, le code interne et le kilométrage actuel.'
    return
  }

  vehicleBusy.value = true

  try {
    const result = await requestApi<{ data: RentalVehicle }>('/api/v1/car-rental/vehicles', {
      method: 'POST',
      headers: contextHeaders(),
      body: JSON.stringify({
        site_id: vehicleForm.site_id,
        code: vehicleForm.code.trim(),
        category: vehicleForm.category,
        operational_status: vehicleForm.operational_status,
        make: vehicleForm.make.trim() || undefined,
        model: vehicleForm.model.trim() || undefined,
        model_year: vehicleForm.model_year === '' ? undefined : Number(vehicleForm.model_year),
        registration_number: vehicleForm.registration_number.trim() || undefined,
        vin: vehicleForm.vin.trim() || undefined,
        latest_odometer_km: Number(vehicleForm.latest_odometer_km),
      }),
    })

    const siteId = vehicleForm.site_id
    Object.assign(vehicleForm, {
      code: '',
      category: 'suv',
      operational_status: 'available',
      make: '',
      model: '',
      model_year: '',
      registration_number: '',
      vin: '',
      latest_odometer_km: '0',
      site_id: siteId,
    })
    vehicleMessage.value = `Véhicule ${result.data.code} enregistré.`

    if (!vehicleFilters.site_id || vehicleFilters.site_id === result.data.site_id) {
      managedVehicles.value = [
        result.data,
        ...managedVehicles.value.filter((vehicle) => vehicle.id !== result.data.id),
      ].sort((left, right) => left.code.localeCompare(right.code, 'fr'))
    }
  } catch (error) {
    vehicleError.value = true
    vehicleMessage.value = messageFrom(error)
  } finally {
    vehicleBusy.value = false
  }
}

function isVehicleOperationalStatus(value: string): value is VehicleOperationalStatus {
  return Object.prototype.hasOwnProperty.call(vehicleStatusLabels, value)
}

function onVehicleStatusSelected(vehicle: RentalVehicle, event: Event): void {
  const status = (event.target as HTMLSelectElement).value

  if (isVehicleOperationalStatus(status)) {
    void updateVehicleStatus(vehicle, status)
  }
}

async function updateVehicleStatus(vehicle: RentalVehicle, status: VehicleOperationalStatus): Promise<void> {
  vehicleMessage.value = ''
  vehicleError.value = false

  if (!canManageVehicles.value) {
    vehicleError.value = true
    vehicleMessage.value = 'Vous n’êtes pas autorisé à modifier l’état d’un véhicule.'
    return
  }

  if (vehicle.operational_status === status) {
    return
  }

  vehicleBusy.value = true

  try {
    const result = await requestApi<{ data: RentalVehicle }>(`/api/v1/car-rental/vehicles/${vehicle.id}/operational-status`, {
      method: 'PATCH',
      headers: contextHeaders(),
      body: JSON.stringify({ operational_status: status }),
    })
    managedVehicles.value = managedVehicles.value.map((item) => item.id === result.data.id ? result.data : item)
    calendarVehicles.value = calendarVehicles.value.map((item) => item.id === result.data.id ? result.data : item)
    vehicleMessage.value = `État de ${result.data.code} mis à jour : ${vehicleStatusLabels[result.data.operational_status]}.`
  } catch (error) {
    vehicleError.value = true
    vehicleMessage.value = messageFrom(error)
  } finally {
    vehicleBusy.value = false
  }
}

async function loadCalendar(): Promise<void> {
  calendarMessage.value = ''
  calendarError.value = false

  if (!canReadCalendar.value) {
    calendarError.value = true
    calendarMessage.value = 'Vous n’êtes pas autorisé à consulter le planning des véhicules.'
    return
  }

  if (!calendarForm.from || !calendarForm.to) {
    calendarError.value = true
    calendarMessage.value = 'Choisissez une date de début et une date de fin.'
    return
  }

  calendarBusy.value = true

  try {
    const parameters = new URLSearchParams({
      from: calendarForm.from,
      to: calendarForm.to,
    })
    if (calendarForm.site_id) parameters.set('site_id', calendarForm.site_id)

    const result = await requestApi<CarRentalCalendarResponse>(`/api/v1/car-rental/calendar?${parameters}`, {
      headers: contextHeaders(),
    })
    calendarEntries.value = result.data
    calendarVehicles.value = result.vehicles
    calendarMessage.value = result.data.length === 0
      ? 'Aucune réservation active ne chevauche cette période.'
      : `${result.data.length} réservation${result.data.length > 1 ? 's' : ''} active${result.data.length > 1 ? 's' : ''} sur la période.`
  } catch (error) {
    calendarError.value = true
    calendarMessage.value = messageFrom(error)
    calendarEntries.value = []
    calendarVehicles.value = []
  } finally {
    calendarBusy.value = false
  }
}

function openSection(section: string): void {
  activeSection.value = section
}

function changeCompany(): void {
  activeContext.value = null
  activeSection.value = 'Accueil'
  resetRentalForm()
  resetVehicleWorkspace()
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
      Vous êtes hors ligne. La connexion et les opérations nécessitant le serveur ne sont pas disponibles.
    </div>

    <section v-if="authView !== 'authenticated'" class="access-layout" aria-labelledby="access-title">
      <div class="access-intro">
        <p class="eyebrow">Clientèle Group ERP</p>
        <h1 id="access-title">Connexion</h1>
        <p>Utilisez votre compte personnel. Un code de vérification est envoyé à votre courriel.</p>
      </div>

      <section class="access-card" aria-live="polite">
        <template v-if="authView === 'sign-in'">
          <p class="eyebrow">Connexion</p>
          <h2>Se connecter</h2>
          <p class="access-description">Saisissez votre adresse courriel personnelle et votre mot de passe.</p>

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
          <h2>Vérifier votre identité</h2>
          <p class="access-description">Saisissez le code envoyé à {{ email }}. Il est valable 10 minutes.</p>

          <form class="access-form" @submit.prevent="verifyEmailCode">
            <label>
              Code à six chiffres
              <input v-model.trim="emailCode" class="code-input" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required :disabled="authBusy" />
            </label>
            <p v-if="authMessage" class="form-message">{{ authMessage }}</p>
            <button class="primary-button" type="submit" :disabled="authBusy || apiStatus !== 'online'">
              {{ authBusy ? 'Vérification…' : 'Vérifier le code' }}
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
      <section v-if="showSystemConfiguration" class="configuration-page" aria-labelledby="configuration-title">
        <section class="session-strip" aria-label="Session propriétaire">
          <span class="avatar" aria-hidden="true">{{ userInitial }}</span>
          <span><strong>{{ user?.name }}</strong> · Configuration globale</span>
          <button class="text-button change-company" type="button" :disabled="configurationBusy" @click="closeSystemConfiguration">
            Retour aux sociétés
          </button>
          <button class="text-button" type="button" @click="logout">Fermer la session</button>
        </section>

        <section class="configuration-header">
          <p class="eyebrow">Réglages globaux · propriétaire du système</p>
          <h1 id="configuration-title">Configuration globale</h1>
          <p>Créez les sociétés, les adresses et les caisses. Chaque action est journalisée.</p>
        </section>

        <p v-if="configurationMessage" class="configuration-message" :class="{ error: configurationError }">
          {{ configurationMessage }}
        </p>

        <div class="configuration-workspace">
          <section class="configuration-form-card">
            <div class="section-intro">
              <p class="eyebrow">Étape 1</p>
              <h2>Créer une société</h2>
              <p>Utilisez un code interne unique.</p>
            </div>

            <form class="configuration-form" @submit.prevent="createSystemCompany">
              <label>
                Code de société
                <input v-model.trim="companyConfigurationForm.code" type="text" maxlength="32" autocapitalize="characters" placeholder="Ex. CARRENTAL" required :disabled="configurationBusy" />
              </label>
              <label>
                Dénomination légale
                <input v-model.trim="companyConfigurationForm.legal_name" type="text" maxlength="255" required :disabled="configurationBusy" />
              </label>
              <label>
                Nom affiché dans l’application
                <input v-model.trim="companyConfigurationForm.display_name" type="text" maxlength="255" required :disabled="configurationBusy" />
              </label>
              <label>
                Devise de base
                <select v-model="companyConfigurationForm.base_currency" :disabled="configurationBusy">
                  <option value="HTG">HTG</option>
                  <option value="USD">USD</option>
                </select>
              </label>
              <button class="primary-button" type="submit" :disabled="configurationBusy || apiStatus !== 'online'">
                {{ configurationBusy ? 'Enregistrement…' : 'Créer la société' }}
              </button>
            </form>
          </section>

          <section class="configuration-form-card">
            <div class="section-intro">
              <p class="eyebrow">Étape 2</p>
              <h2>Créer une adresse</h2>
              <p>Ajoutez un lieu opérationnel réel.</p>
            </div>

            <form class="configuration-form" @submit.prevent="createSystemSite">
              <label>
                Société
                <select v-model="configurationCompanyId" :disabled="configurationBusy || !configurationCompanies.length" @change="selectConfigurationCompany">
                  <option value="" disabled>Sélectionnez une société</option>
                  <option v-for="company in configurationCompanies" :key="company.id" :value="company.id">
                    {{ company.display_name }} · {{ company.code }}
                  </option>
                </select>
              </label>
              <label>
                Code d’adresse
                <input v-model.trim="siteConfigurationForm.code" type="text" maxlength="32" autocapitalize="characters" placeholder="Ex. CAP-01" required :disabled="configurationBusy || !configurationCompanyId" />
              </label>
              <label>
                Nom de l’adresse
                <input v-model.trim="siteConfigurationForm.name" type="text" maxlength="255" required :disabled="configurationBusy || !configurationCompanyId" />
              </label>
              <label>
                Adresse complète
                <textarea v-model.trim="siteConfigurationForm.address" rows="3" maxlength="1000" required :disabled="configurationBusy || !configurationCompanyId"></textarea>
              </label>
              <button class="primary-button" type="submit" :disabled="configurationBusy || apiStatus !== 'online' || !configurationCompanyId">
                {{ configurationBusy ? 'Enregistrement…' : 'Créer l’adresse' }}
              </button>
            </form>
          </section>

          <section class="configuration-form-card">
            <div class="section-intro">
              <p class="eyebrow">Étape 3</p>
              <h2>Créer une caisse</h2>
              <p>Associez la caisse à une seule adresse.</p>
            </div>

            <form class="configuration-form" @submit.prevent="createSystemCashRegister">
              <label>
                Société
                <select v-model="configurationCompanyId" :disabled="configurationBusy || !configurationCompanies.length" @change="selectConfigurationCompany">
                  <option value="" disabled>Sélectionnez une société</option>
                  <option v-for="company in configurationCompanies" :key="company.id" :value="company.id">
                    {{ company.display_name }} · {{ company.code }}
                  </option>
                </select>
              </label>
              <label>
                Adresse
                <select v-model="cashRegisterConfigurationForm.site_id" :disabled="configurationBusy || !selectedConfigurationCompany?.sites.length">
                  <option value="" disabled>Sélectionnez une adresse</option>
                  <option v-for="site in selectedConfigurationCompany?.sites ?? []" :key="site.id" :value="site.id">
                    {{ site.name }} · {{ site.code }}
                  </option>
                </select>
              </label>
              <label>
                Code de caisse
                <input v-model.trim="cashRegisterConfigurationForm.code" type="text" maxlength="32" autocapitalize="characters" placeholder="Ex. POS-01" required :disabled="configurationBusy || !cashRegisterConfigurationForm.site_id" />
              </label>
              <label>
                Nom de la caisse
                <input v-model.trim="cashRegisterConfigurationForm.name" type="text" maxlength="255" required :disabled="configurationBusy || !cashRegisterConfigurationForm.site_id" />
              </label>
              <label class="toggle-field">
                <input v-model="cashRegisterConfigurationForm.automatic_print_enabled" type="checkbox" :disabled="configurationBusy" />
                <span>Préparer l’impression automatique des reçus</span>
              </label>
              <label class="toggle-field">
                <input v-model="cashRegisterConfigurationForm.customer_display_enabled" type="checkbox" :disabled="configurationBusy" />
                <span>Préparer l’écran client</span>
              </label>
              <button class="primary-button" type="submit" :disabled="configurationBusy || apiStatus !== 'online' || !cashRegisterConfigurationForm.site_id">
                {{ configurationBusy ? 'Enregistrement…' : 'Créer la caisse' }}
              </button>
            </form>
          </section>
        </div>

        <section class="configuration-list-card">
          <div class="workspace-heading">
            <div>
              <p class="eyebrow">Configuration enregistrée</p>
              <h2>Sociétés et adresses</h2>
            </div>
            <button class="refresh-button" type="button" :disabled="configurationBusy" @click="loadSystemConfiguration">
              Actualiser
            </button>
          </div>

          <p v-if="!configurationCompanies.length" class="empty-configuration">
            Aucune société n’est encore enregistrée.
          </p>

          <div v-else class="configuration-company-list">
            <article v-for="company in configurationCompanies" :key="company.id" class="configuration-company-card">
              <header>
                <div>
                  <span>{{ company.code }}</span>
                  <h3>{{ company.display_name }}</h3>
                </div>
                <small>{{ company.base_currency }} · {{ company.timezone_label }}</small>
              </header>
              <p>{{ company.legal_name }}</p>
              <div v-if="company.sites.length" class="configuration-site-list">
                <article v-for="site in company.sites" :key="site.id" class="configuration-site-card">
                  <div>
                    <strong>{{ site.name }}</strong>
                    <span>{{ site.code }}</span>
                  </div>
                  <p>{{ site.address }}</p>
                  <ul v-if="site.cash_registers.length">
                    <li v-for="register in site.cash_registers" :key="register.id">
                      <strong>{{ register.name }}</strong>
                      <span>{{ register.code }}</span>
                      <small>
                        {{ register.automatic_print_enabled ? 'Impression à préparer' : 'Impression non configurée' }} ·
                        {{ register.customer_display_enabled ? 'Écran client à préparer' : 'Écran client non configuré' }}
                      </small>
                    </li>
                  </ul>
                  <p v-else class="configuration-empty">Aucune caisse créée pour cette adresse.</p>
                </article>
              </div>
              <p v-else class="configuration-empty">Aucune adresse créée pour cette société.</p>
            </article>
          </div>
        </section>
      </section>

      <section v-else-if="!activeContext" class="company-choice" aria-labelledby="company-choice-title">
        <p class="eyebrow">Connecté · {{ user?.name }}</p>
        <h1 id="company-choice-title">Choisir une société</h1>
        <p>Les données et les fonctions disponibles dépendent de cette sélection.</p>
        <div v-if="companies.length" class="company-grid">
          <button v-for="company in companies" :key="company.id" class="company-button" type="button" :disabled="authBusy" @click="selectCompany(company.id)">
            <span>{{ company.code }}</span>
            <strong>{{ company.name }}</strong>
            <small>Rôle : {{ company.role_key }}</small>
          </button>
        </div>
        <p v-else class="form-message">
          {{ canManageSystemConfiguration ? 'Aucune société n’est configurée.' : 'Aucune société n’est attribuée à votre compte. Contactez le propriétaire du système.' }}
        </p>
        <p v-if="authMessage" class="form-message">{{ authMessage }}</p>
        <button v-if="canManageSystemConfiguration" class="secondary-button" type="button" @click="openSystemConfiguration">
          Ouvrir la configuration globale
        </button>
        <button class="text-button" type="button" @click="logout">Fermer la session</button>
      </section>

      <template v-else>
        <section class="session-strip" aria-label="Session active">
          <span class="avatar" aria-hidden="true">{{ userInitial }}</span>
          <span><strong>{{ user?.name }}</strong> · {{ activeCompanyName }}</span>
          <button v-if="canManageSystemConfiguration" class="text-button" type="button" @click="openSystemConfiguration">Configuration globale</button>
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

        <nav class="module-nav" aria-label="Menu Car Rental">
          <button
            v-for="section in visibleSections"
            :key="section"
            type="button"
            :class="{ active: activeSection === section }"
            @click="openSection(section)"
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
                <h3>Créer une réservation</h3>
                <p>
                  Sélectionnez une adresse, vérifiez la disponibilité, puis créez une réservation numérotée.
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
              <p class="eyebrow">Fonctions disponibles</p>
              <h3>Contrôles actifs</h3>
              </div>
              <ul>
                <li><span>✓</span> Code par courriel personnel et session révocable</li>
                <li><span>✓</span> Rôle, société active et périmètre de site</li>
                <li><span>✓</span> Journal d’audit sans mot de passe ni jeton</li>
                <li><span>✓</span> Disponibilité et réservation de SUV, Mid SUV et Pick-up</li>
                <li><span>→</span> Contrat, inspection, dépôt et reçu : non disponibles</li>
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

          <template v-else-if="activeSection === 'Calendrier'">
            <div class="planning-workspace">
              <section class="planning-controls-card" aria-labelledby="calendar-title">
                <div class="section-intro">
                  <p class="eyebrow">Planning global</p>
                  <h3 id="calendar-title">Calendrier des véhicules</h3>
                  <p>
                    Consultez les réservations actives et l’état de la flotte pour les adresses autorisées.
                    Les informations clients ne sont pas affichées dans ce planning.
                  </p>
                </div>

                <form class="planning-form" @submit.prevent="loadCalendar">
                  <label>
                    Adresse
                    <select v-model="calendarForm.site_id" :disabled="calendarBusy">
                      <option value="">Toutes les adresses autorisées</option>
                      <option v-for="site in activeContext.sites" :key="site.id" :value="site.id">
                        {{ site.name }} · {{ site.address }}
                      </option>
                    </select>
                  </label>
                  <label>
                    Début
                    <input v-model="calendarForm.from" type="date" required :disabled="calendarBusy" />
                  </label>
                  <label>
                    Fin
                    <input v-model="calendarForm.to" type="date" required :disabled="calendarBusy" />
                  </label>
                  <button class="primary-button planning-submit" type="submit" :disabled="calendarBusy || apiStatus !== 'online'">
                    {{ calendarBusy ? 'Actualisation…' : 'Afficher le planning' }}
                  </button>
                </form>
                <p v-if="calendarMessage" class="rental-message" :class="{ error: calendarError }" role="status">
                  {{ calendarMessage }}
                </p>
              </section>

              <section class="fleet-state-card" aria-labelledby="fleet-status-title">
                <div class="section-intro">
                  <p class="eyebrow">État de flotte</p>
                  <h3 id="fleet-status-title">Véhicules actifs</h3>
                </div>
                <div v-if="calendarVehicles.length" class="fleet-grid">
                  <article v-for="vehicle in calendarVehicles" :key="vehicle.id" class="fleet-card">
                    <div>
                      <span class="vehicle-code">{{ vehicle.code }}</span>
                      <strong>{{ vehicleDisplayName(vehicle) }}</strong>
                    </div>
                    <span class="status-chip" :class="`status-${vehicle.operational_status}`">
                      {{ vehicleStatusLabels[vehicle.operational_status] }}
                    </span>
                    <small>{{ vehicle.site?.name ?? 'Adresse non disponible' }} · {{ categoryLabels[vehicle.category] }}</small>
                  </article>
                </div>
                <p v-else class="field-help">Affichez le planning pour consulter l’état des véhicules actifs.</p>
              </section>

              <section class="calendar-list-card" aria-labelledby="calendar-list-title">
                <div class="section-intro">
                  <p class="eyebrow">Réservations sur la période</p>
                  <h3 id="calendar-list-title">Occupations planifiées</h3>
                </div>
                <div v-if="calendarEntries.length" class="calendar-entry-list">
                  <article v-for="entry in calendarEntries" :key="entry.id" class="calendar-entry-card">
                    <div class="calendar-entry-heading">
                      <span class="vehicle-code">{{ entry.vehicle?.code ?? 'Véhicule non disponible' }}</span>
                      <span class="status-chip" :class="`reservation-${entry.state}`">
                        {{ reservationStateLabels[entry.state] }}
                      </span>
                    </div>
                    <strong>Réservation {{ entry.number }}</strong>
                    <dl>
                      <div>
                        <dt>Départ</dt>
                        <dd>{{ formatCapHaitienDateTime(entry.pickup_at) }}</dd>
                      </div>
                      <div>
                        <dt>Retour</dt>
                        <dd>{{ formatCapHaitienDateTime(entry.due_at) }}</dd>
                      </div>
                      <div>
                        <dt>Adresse</dt>
                        <dd>{{ entry.site?.name ?? 'Non disponible' }}</dd>
                      </div>
                    </dl>
                  </article>
                </div>
                <p v-else class="field-help">Aucune réservation active n’est affichée pour le moment.</p>
              </section>
            </div>
          </template>

          <template v-else-if="activeSection === 'Véhicules'">
            <div class="vehicle-management">
              <section class="vehicle-list-card" aria-labelledby="vehicle-list-title">
                <div class="section-intro">
                  <p class="eyebrow">Flotte</p>
                  <h3 id="vehicle-list-title">Véhicules par adresse</h3>
                  <p>La liste est limitée aux adresses autorisées pour la société active.</p>
                </div>

                <form class="vehicle-filter-form" @submit.prevent="loadVehicles">
                  <label>
                    Adresse
                    <select v-model="vehicleFilters.site_id" :disabled="vehicleBusy">
                      <option value="">Toutes les adresses autorisées</option>
                      <option v-for="site in activeContext.sites" :key="site.id" :value="site.id">
                        {{ site.name }} · {{ site.address }}
                      </option>
                    </select>
                  </label>
                  <label>
                    État opérationnel
                    <select v-model="vehicleFilters.operational_status" :disabled="vehicleBusy">
                      <option value="">Tous les états</option>
                      <option v-for="(label, status) in vehicleStatusLabels" :key="status" :value="status">{{ label }}</option>
                    </select>
                  </label>
                  <button class="refresh-button vehicle-filter-submit" type="submit" :disabled="vehicleBusy || apiStatus !== 'online'">
                    {{ vehicleBusy ? 'Actualisation…' : 'Afficher les véhicules' }}
                  </button>
                </form>
                <p v-if="vehicleMessage" class="rental-message" :class="{ error: vehicleError }" role="status">
                  {{ vehicleMessage }}
                </p>

                <div v-if="managedVehicles.length" class="managed-vehicle-grid">
                  <article v-for="vehicle in managedVehicles" :key="vehicle.id" class="managed-vehicle-card">
                    <div>
                      <span class="vehicle-code">{{ vehicle.code }}</span>
                      <h4>{{ vehicleDisplayName(vehicle) }}</h4>
                      <p>{{ vehicle.site?.name ?? 'Adresse non disponible' }} · {{ categoryLabels[vehicle.category] }}</p>
                    </div>
                    <dl>
                      <div>
                        <dt>Kilométrage</dt>
                        <dd>{{ vehicle.latest_odometer_km.toLocaleString('fr-FR') }} km</dd>
                      </div>
                      <div v-if="vehicle.model_year">
                        <dt>Année</dt>
                        <dd>{{ vehicle.model_year }}</dd>
                      </div>
                    </dl>
                    <label class="status-select">
                      État opérationnel
                      <select
                        :value="vehicle.operational_status"
                        :disabled="vehicleBusy || !canManageVehicles"
                        @change="onVehicleStatusSelected(vehicle, $event)"
                      >
                        <option v-for="(label, status) in vehicleStatusLabels" :key="status" :value="status">{{ label }}</option>
                      </select>
                    </label>
                  </article>
                </div>
                <p v-else class="field-help">Utilisez les critères puis sélectionnez « Afficher les véhicules ».</p>
              </section>

              <section v-if="canManageVehicles" class="vehicle-create-card" aria-labelledby="vehicle-create-title">
                <div class="section-intro">
                  <p class="eyebrow">Nouveau véhicule</p>
                  <h3 id="vehicle-create-title">Ajouter à la flotte</h3>
                  <p>Le code interne, l’immatriculation et le VIN sont contrôlés au niveau de la société.</p>
                </div>

                <form class="vehicle-create-form" @submit.prevent="createVehicle">
                  <label>
                    Adresse
                    <select v-model="vehicleForm.site_id" required :disabled="vehicleBusy">
                      <option value="" disabled>Choisissez une adresse autorisée</option>
                      <option v-for="site in activeContext.sites" :key="site.id" :value="site.id">
                        {{ site.name }} · {{ site.address }}
                      </option>
                    </select>
                  </label>
                  <div class="two-columns">
                    <label>
                      Code interne
                      <input v-model.trim="vehicleForm.code" type="text" maxlength="32" required :disabled="vehicleBusy" />
                    </label>
                    <label>
                      Catégorie
                      <select v-model="vehicleForm.category" :disabled="vehicleBusy">
                        <option v-for="(label, category) in categoryLabels" :key="category" :value="category">{{ label }}</option>
                      </select>
                    </label>
                  </div>
                  <div class="two-columns">
                    <label>
                      Marque
                      <input v-model.trim="vehicleForm.make" type="text" maxlength="64" :disabled="vehicleBusy" />
                    </label>
                    <label>
                      Modèle
                      <input v-model.trim="vehicleForm.model" type="text" maxlength="64" :disabled="vehicleBusy" />
                    </label>
                  </div>
                  <div class="three-columns">
                    <label>
                      Année
                      <input v-model="vehicleForm.model_year" type="number" inputmode="numeric" min="1900" max="2100" :disabled="vehicleBusy" />
                    </label>
                    <label>
                      Kilométrage actuel
                      <input v-model="vehicleForm.latest_odometer_km" type="number" inputmode="numeric" min="0" step="1" required :disabled="vehicleBusy" />
                    </label>
                    <label>
                      État initial
                      <select v-model="vehicleForm.operational_status" :disabled="vehicleBusy">
                        <option v-for="(label, status) in vehicleStatusLabels" :key="status" :value="status">{{ label }}</option>
                      </select>
                    </label>
                  </div>
                  <div class="two-columns">
                    <label>
                      Immatriculation (facultatif)
                      <input v-model.trim="vehicleForm.registration_number" type="text" maxlength="64" :disabled="vehicleBusy" />
                    </label>
                    <label>
                      VIN (facultatif)
                      <input v-model.trim="vehicleForm.vin" type="text" maxlength="64" :disabled="vehicleBusy" />
                    </label>
                  </div>
                  <button class="primary-button create-vehicle" type="submit" :disabled="vehicleBusy || apiStatus !== 'online'">
                    {{ vehicleBusy ? 'Enregistrement…' : 'Enregistrer le véhicule' }}
                  </button>
                </form>
              </section>
            </div>
          </template>

          <template v-else>
            <div class="empty-state">
              <h3>Fonction non disponible</h3>
              <p>Cette fonction n’est pas activée dans cette version.</p>
            </div>
          </template>
        </section>
      </template>
    </template>

    <footer class="application-footer">
      <span>Clientèle Group ERP · {{ bootstrap?.application.version ?? '0.2.0-alpha.8' }}</span>
      <span>HTG · USD · Cap-Haïtien, Haïti</span>
    </footer>
  </main>
</template>
