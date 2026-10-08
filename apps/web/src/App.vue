<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import {
  CLIENTELE_CAR_RENTAL_FLEET_ADDRESS,
  clienteleFleetCatalog,
  type FleetCatalogVehicle,
} from './data/clienteleFleetCatalog'

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
type VehicleRegistrationStatus = 'demonstration' | 'location' | 'normal'
type VehicleDocumentType = 'registration' | 'oavct_insurance' | 'tint_permit'
type VehicleDocumentStatus = 'not_recorded' | 'not_applicable' | 'expired' | 'expiring_soon' | 'current'
type ReservationState = 'draft' | 'reserved' | 'checked_out' | 'completed' | 'cancelled'
type ReservationCancellationReason = 'customer_request' | 'vehicle_unavailable' | 'business_decision' | 'other'

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
  registration_number?: string
  registration_status?: VehicleRegistrationStatus
  reference_photo?: {
    key: string
    url: string
    label: string
    source_url: string
  } | null
  document_statuses?: Array<{
    type: VehicleDocumentType
    status: VehicleDocumentStatus
    expires_at: string | null
  }>
}

interface RentalVehicleDocument {
  id: string
  type: VehicleDocumentType
  document_number: string | null
  issued_at: string | null
  expires_at: string | null
  status: VehicleDocumentStatus
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
  state: ReservationState
  site_id: string
  site: RentalSite | null
  pickup_at: string
  due_at: string
  checked_out_at: string | null
  returned_at: string | null
  lock_version: number
  airport_pickup_fee_usd: string
  airport_dropoff_fee_usd: string
  airport_fees_total_usd: string
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

interface CarRentalReservationListEntry {
  id: string
  number: string
  state: ReservationState
  pickup_at: string
  due_at: string
  site: RentalSite | null
  vehicle: RentalVehicle | null
  customer: {
    id: string
    display_name: string
    customer_type: 'individual' | 'institution'
  } | null
}

interface CarRentalReservationListResponse {
  data: CarRentalReservationListEntry[]
  period: {
    from: string
    to: string
    timezone: string
    timezone_label: string
  }
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

interface SystemCompanyUser {
  id: string
  user_id: string
  name: string
  email: string
  role_key: string
  site_scope: 'all' | 'selected'
  is_active: boolean
  is_system_owner: boolean
  can_edit_personal_profile: boolean
  can_delete_permanently: boolean
  sites: Array<{
    id: string
    code: string
    name: string
  }>
}

interface ConfirmationRequest {
  title: string
  message: string
  confirm_label: string
  danger?: boolean
  action: () => Promise<void>
}

type CarRentalUserRole = 'car_rental_administrator' | 'car_rental_agent' | 'car_rental_fleet'

type ApiValidationErrors = Record<string, string[]>

class ApiError extends Error {
  constructor(
    message: string,
    readonly status: number,
    readonly errors: ApiValidationErrors = {},
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
const fleetCatalogMessage = ref('')
const vehicleCreateSection = ref<HTMLElement | null>(null)
const managedVehicles = ref<RentalVehicle[]>([])
const selectedVehicle = ref<RentalVehicle | null>(null)
const selectedVehicleDocuments = ref<RentalVehicleDocument[]>([])
const vehicleDocumentsBusy = ref(false)
const vehicleDocumentsMessage = ref('')
const vehicleDocumentsError = ref(false)
const calendarBusy = ref(false)
const calendarMessage = ref('')
const calendarError = ref(false)
const calendarEntries = ref<CarRentalCalendarEntry[]>([])
const calendarVehicles = ref<RentalVehicle[]>([])
const reservationListBusy = ref(false)
const reservationListMessage = ref('')
const reservationListError = ref(false)
const reservationList = ref<CarRentalReservationListEntry[]>([])
const selectedReservation = ref<CarRentalReservation | null>(null)
const reservationDetailsBusy = ref(false)
const reservationManagementMessage = ref('')
const reservationManagementError = ref(false)
const reservationManagementVehicles = ref<RentalVehicle[]>([])
const showSystemConfiguration = ref(false)
const configurationBusy = ref(false)
const configurationMessage = ref('')
const configurationError = ref(false)
const configurationCompanies = ref<SystemCompany[]>([])
const configurationCompanyId = ref('')
const configurationCompanyUsers = ref<SystemCompanyUser[]>([])
const configurationUsersBusy = ref(false)
const companyConfigurationErrors = ref<Record<string, string>>({})
const siteConfigurationErrors = ref<Record<string, string>>({})
const cashRegisterConfigurationErrors = ref<Record<string, string>>({})
const companyUserConfigurationErrors = ref<Record<string, string>>({})
const companyUserManagementErrors = ref<Record<string, string>>({})
const selectedCompanyUser = ref<SystemCompanyUser | null>(null)
const confirmationRequest = ref<ConfirmationRequest | null>(null)
const confirmationBusy = ref(false)
const deletionConfirmationOpen = ref(false)
const deletionConfirmationEmail = ref('')
const deletionConfirmationUser = ref<SystemCompanyUser | null>(null)
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
  apply_airport_pickup_fee: false,
  apply_airport_dropoff_fee: false,
  currency: 'USD' as 'HTG' | 'USD',
  daily_rate: '',
  kilometer_plan: 'limited' as KilometerPlan,
  included_km: '300',
  additional_km_rate: '',
})

const vehicleForm = reactive({
  site_id: '',
  category: 'suv' as RentalCategory,
  operational_status: 'available' as VehicleOperationalStatus,
  make: '',
  model: '',
  model_year: '',
  registration_number: '',
  registration_status: 'normal' as VehicleRegistrationStatus,
  reference_photo_key: '',
  vin: '',
  latest_odometer_km: '',
})

const vehicleRegistrationForm = reactive({
  registration_number: '',
  registration_status: 'normal' as VehicleRegistrationStatus,
})

const vehicleDocumentsForm = reactive({
  registration_document_number: '',
  registration_issued_at: '',
  oavct_document_number: '',
  oavct_expires_at: '',
  tint_document_number: '',
  tint_expires_at: '',
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

const reservationListFilters = reactive({
  state: '' as '' | ReservationState,
  query: '',
  from: '',
  to: '',
})

const reservationManagementForm = reactive({
  vehicle_id: '',
  pickup_at: '',
  due_at: '',
  extension_due_at: '',
  cancellation_reason: 'customer_request' as ReservationCancellationReason,
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
})

const companyUserConfigurationForm = reactive({
  name: '',
  email: '',
  password: '',
  password_confirmation: '',
  role_key: 'car_rental_agent' as CarRentalUserRole,
  site_scope: 'all' as 'all' | 'selected',
  site_ids: [] as string[],
})

const companyUserManagementForm = reactive({
  name: '',
  email: '',
  role_key: 'car_rental_agent' as CarRentalUserRole,
  site_scope: 'all' as 'all' | 'selected',
  site_ids: [] as string[],
  password: '',
  password_confirmation: '',
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

const registrationStatusLabels: Record<VehicleRegistrationStatus, string> = {
  demonstration: 'Démonstration',
  location: 'Location',
  normal: 'Normale',
}

const vehicleDocumentTypeLabels: Record<VehicleDocumentType, string> = {
  registration: 'Immatriculation',
  oavct_insurance: 'Assurance OAVCT',
  tint_permit: 'Permis de vitres teintées',
}

const vehicleDocumentStatusLabels: Record<VehicleDocumentStatus, string> = {
  not_recorded: 'Non renseigné',
  not_applicable: 'Sans expiration',
  expired: 'Expiré',
  expiring_soon: 'Expire bientôt',
  current: 'À jour',
}

const carRentalUserRoleLabels: Record<CarRentalUserRole, string> = {
  car_rental_administrator: 'Administrateur Car Rental',
  car_rental_agent: 'Agent de location',
  car_rental_fleet: 'Gestionnaire de flotte',
}

function companyUserRoleLabel(roleKey: string): string {
  if (roleKey === 'owner') {
    return 'Propriétaire du système'
  }

  return carRentalUserRoleLabels[roleKey as CarRentalUserRole] ?? roleKey
}

const reservationStateLabels: Record<ReservationState, string> = {
  draft: 'Brouillon',
  reserved: 'Réservée',
  checked_out: 'En circulation',
  completed: 'Terminée',
  cancelled: 'Annulée',
}

const reservationCancellationReasonLabels: Record<ReservationCancellationReason, string> = {
  customer_request: 'Demande du client',
  vehicle_unavailable: 'Véhicule indisponible',
  business_decision: 'Décision interne',
  other: 'Autre motif',
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

const airportFeesTotalUsd = computed(() => (
  (reservationForm.pickup_location_type === 'cap_haitien_airport' && reservationForm.apply_airport_pickup_fee ? 20 : 0)
  + (reservationForm.dropoff_location_type === 'cap_haitien_airport' && reservationForm.apply_airport_dropoff_fee ? 20 : 0)
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
  const payload = (await response.json().catch(() => ({}))) as {
    message?: string
    errors?: ApiValidationErrors
  }

  if (!response.ok) {
    const errors = payload.errors ?? {}
    const firstValidationMessage = Object.values(errors)
      .flat()
      .find((message) => message.length > 0)

    throw new ApiError(
      firstValidationMessage ?? payload.message ?? 'La demande ne peut pas être traitée.',
      response.status,
      errors,
    )
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
  clearConfigurationValidationErrors()
  await loadSystemConfiguration()
}

function closeSystemConfiguration(): void {
  showSystemConfiguration.value = false
  configurationMessage.value = ''
  configurationError.value = false
  clearConfigurationValidationErrors()
  resetCompanyUserManagement()
  confirmationRequest.value = null
  closeDeletionConfirmation()
}

function selectConfigurationCompany(): void {
  cashRegisterConfigurationForm.site_id = selectedConfigurationCompany.value?.sites[0]?.id ?? ''
  companyUserConfigurationForm.site_ids = []
  resetCompanyUserManagement()
  clearConfigurationFieldError(cashRegisterConfigurationErrors, 'site_id')
  clearConfigurationFieldError(companyUserConfigurationErrors, 'site_ids')
  void loadCompanyUsers()
}

function clearConfigurationValidationErrors(): void {
  companyConfigurationErrors.value = {}
  siteConfigurationErrors.value = {}
  cashRegisterConfigurationErrors.value = {}
  companyUserConfigurationErrors.value = {}
  companyUserManagementErrors.value = {}
}

function resetCompanyUserManagement(): void {
  selectedCompanyUser.value = null
  Object.assign(companyUserManagementForm, {
    name: '',
    email: '',
    role_key: 'car_rental_agent',
    site_scope: 'all',
    site_ids: [],
    password: '',
    password_confirmation: '',
  })
  companyUserManagementErrors.value = {}
}

function clearConfigurationFieldError(
  errors: typeof companyConfigurationErrors,
  field: string,
): void {
  if (!(field in errors.value)) {
    return
  }

  const nextErrors = { ...errors.value }
  delete nextErrors[field]
  errors.value = nextErrors
}

function clearConfigurationFormFieldError(
  form: 'company' | 'site' | 'cash-register' | 'company-user',
  field: string,
): void {
  const errors = form === 'company'
    ? companyConfigurationErrors
    : form === 'site'
      ? siteConfigurationErrors
      : form === 'cash-register'
        ? cashRegisterConfigurationErrors
        : companyUserConfigurationErrors

  clearConfigurationFieldError(errors, field)
}

function validationErrorsFrom(error: unknown): Record<string, string> {
  if (!(error instanceof ApiError)) {
    return {}
  }

  return Object.fromEntries(
    Object.entries(error.errors)
      .filter(([, messages]) => messages.length > 0)
      .map(([field, messages]) => [field, messages[0]]),
  )
}

function showConfigurationRequestError(
  error: unknown,
  errors: typeof companyConfigurationErrors,
): void {
  errors.value = validationErrorsFrom(error)
  configurationError.value = true
  configurationMessage.value = Object.keys(errors.value).length > 0
    ? 'Vérifiez les champs signalés.'
    : messageFrom(error)
}

function normalizeConfigurationCode(value: string): string {
  return value
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .trim()
    .replace(/[^a-zA-Z0-9_-]+/g, '-')
    .replace(/[-_]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .toUpperCase()
}

function normalizeCompanyConfigurationCode(): void {
  companyConfigurationForm.code = normalizeConfigurationCode(companyConfigurationForm.code)
}

function normalizeSiteConfigurationCode(): void {
  siteConfigurationForm.code = normalizeConfigurationCode(siteConfigurationForm.code)
}

function normalizeCashRegisterConfigurationCode(): void {
  cashRegisterConfigurationForm.code = normalizeConfigurationCode(cashRegisterConfigurationForm.code)
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
    await loadCompanyUsers()
  } catch (error) {
    configurationError.value = true
    configurationMessage.value = messageFrom(error)
  } finally {
    configurationBusy.value = false
  }
}

async function loadCompanyUsers(): Promise<void> {
  const companyId = configurationCompanyId.value

  if (!companyId || !canManageSystemConfiguration.value) {
    configurationCompanyUsers.value = []
    return
  }

  configurationUsersBusy.value = true

  try {
    const result = await requestApi<{ data: SystemCompanyUser[] }>(`/api/v1/system/configuration/companies/${companyId}/users`)
    configurationCompanyUsers.value = result.data
  } catch (error) {
    configurationError.value = true
    configurationMessage.value = messageFrom(error)
    configurationCompanyUsers.value = []
  } finally {
    configurationUsersBusy.value = false
  }
}

function onCompanyUserSiteScopeChanged(): void {
  if (companyUserConfigurationForm.site_scope === 'all') {
    companyUserConfigurationForm.site_ids = []
  }

  clearConfigurationFieldError(companyUserConfigurationErrors, 'site_scope')
  clearConfigurationFieldError(companyUserConfigurationErrors, 'site_ids')
}

function hasValidInitialPassword(): boolean {
  return hasValidPassword(companyUserConfigurationForm.password)
}

function hasValidPassword(value: string): boolean {
  return value.length >= 12
    && /[a-z]/.test(value)
    && /[A-Z]/.test(value)
    && /\d/.test(value)
    && /[^A-Za-z0-9]/.test(value)
}

async function createCompanyUser(): Promise<void> {
  const companyId = configurationCompanyId.value
  if (!companyId) {
    configurationError.value = true
    configurationMessage.value = 'Sélectionnez une société avant d’ajouter un utilisateur.'
    return
  }

  companyUserConfigurationErrors.value = {}
  configurationError.value = false
  configurationMessage.value = ''

  if (!hasValidInitialPassword()) {
    companyUserConfigurationErrors.value = {
      password: 'Utilisez au moins 12 caractères, avec majuscule, minuscule, chiffre et symbole.',
    }
    configurationError.value = true
    configurationMessage.value = 'Vérifiez les champs signalés.'
    return
  }

  if (companyUserConfigurationForm.password !== companyUserConfigurationForm.password_confirmation) {
    companyUserConfigurationErrors.value = {
      password_confirmation: 'Les deux mots de passe ne correspondent pas.',
    }
    configurationError.value = true
    configurationMessage.value = 'Vérifiez les champs signalés.'
    return
  }

  if (companyUserConfigurationForm.site_scope === 'selected' && !companyUserConfigurationForm.site_ids.length) {
    companyUserConfigurationErrors.value = {
      site_ids: 'Sélectionnez au moins une adresse pour un accès limité.',
    }
    configurationError.value = true
    configurationMessage.value = 'Vérifiez les champs signalés.'
    return
  }

  configurationBusy.value = true

  try {
    const result = await requestApi<{ data: SystemCompanyUser; notification?: { sent: boolean } }>(`/api/v1/system/configuration/companies/${companyId}/users`, {
      method: 'POST',
      body: JSON.stringify({
        ...companyUserConfigurationForm,
        name: companyUserConfigurationForm.name.trim(),
        email: companyUserConfigurationForm.email.trim().toLowerCase(),
      }),
    })

    configurationCompanyUsers.value = [
      ...configurationCompanyUsers.value,
      result.data,
    ].sort((left, right) => left.name.localeCompare(right.name, 'fr'))

    Object.assign(companyUserConfigurationForm, {
      name: '',
      email: '',
      password: '',
      password_confirmation: '',
      role_key: 'car_rental_agent',
      site_scope: 'all',
      site_ids: [],
    })
    configurationMessage.value = result.notification?.sent === false
      ? 'Utilisateur créé. La notification de création n’a pas pu être envoyée. Vérifiez le courriel et demandez une réinitialisation de mot de passe si nécessaire.'
      : 'Utilisateur créé. Un courriel de création a été envoyé. Un code de sécurité sera demandé à chaque connexion.'
  } catch (error) {
    showConfigurationRequestError(error, companyUserConfigurationErrors)
  } finally {
    configurationBusy.value = false
  }
}

function selectCompanyUser(companyUser: SystemCompanyUser): void {
  selectedCompanyUser.value = companyUser
  Object.assign(companyUserManagementForm, {
    name: companyUser.name ?? '',
    email: companyUser.email ?? '',
    role_key: companyUser.role_key as CarRentalUserRole,
    site_scope: companyUser.site_scope,
    site_ids: companyUser.sites.map((site) => site.id),
    password: '',
    password_confirmation: '',
  })
  companyUserManagementErrors.value = {}
  configurationError.value = false
  configurationMessage.value = ''
}

function onManagedCompanyUserSiteScopeChanged(): void {
  if (companyUserManagementForm.site_scope === 'all') {
    companyUserManagementForm.site_ids = []
  }

  clearConfigurationFieldError(companyUserManagementErrors, 'site_scope')
  clearConfigurationFieldError(companyUserManagementErrors, 'site_ids')
}

function clearManagedCompanyUserFieldError(field: string): void {
  clearConfigurationFieldError(companyUserManagementErrors, field)
}

function replaceCompanyUser(companyUser: SystemCompanyUser): void {
  configurationCompanyUsers.value = configurationCompanyUsers.value
    .map((item) => item.id === companyUser.id ? companyUser : item)
    .sort((left, right) => (left.name ?? '').localeCompare(right.name ?? '', 'fr'))
  selectedCompanyUser.value = companyUser
}

async function saveCompanyUser(): Promise<void> {
  const companyId = configurationCompanyId.value
  const companyUser = selectedCompanyUser.value

  if (!companyId || !companyUser) {
    return
  }

  companyUserManagementErrors.value = {}
  configurationError.value = false
  configurationMessage.value = ''

  if (companyUserManagementForm.site_scope === 'selected' && !companyUserManagementForm.site_ids.length) {
    companyUserManagementErrors.value = { site_ids: 'Sélectionnez au moins une adresse pour un accès limité.' }
    configurationError.value = true
    configurationMessage.value = 'Vérifiez les champs signalés.'
    return
  }

  configurationBusy.value = true

  try {
    const result = await requestApi<{ data: SystemCompanyUser }>(`/api/v1/system/configuration/companies/${companyId}/users/${companyUser.id}`, {
      method: 'PATCH',
      body: JSON.stringify({
        name: companyUserManagementForm.name.trim(),
        email: companyUserManagementForm.email.trim().toLowerCase(),
        role_key: companyUserManagementForm.role_key,
        site_scope: companyUserManagementForm.site_scope,
        site_ids: companyUserManagementForm.site_ids,
      }),
    })
    replaceCompanyUser(result.data)
    selectCompanyUser(result.data)
    configurationMessage.value = 'Modifications enregistrées.'
  } catch (error) {
    showConfigurationRequestError(error, companyUserManagementErrors)
  } finally {
    configurationBusy.value = false
  }
}

async function executeCompanyUserStatusChange(isActive: boolean): Promise<void> {
  const companyId = configurationCompanyId.value
  const companyUser = selectedCompanyUser.value
  if (!companyId || !companyUser) return

  configurationBusy.value = true
  configurationError.value = false

  try {
    const result = await requestApi<{ data: SystemCompanyUser }>(`/api/v1/system/configuration/companies/${companyId}/users/${companyUser.id}/status`, {
      method: 'PATCH',
      body: JSON.stringify({ is_active: isActive }),
    })
    replaceCompanyUser(result.data)
    configurationMessage.value = isActive ? 'Accès réactivé.' : 'Accès désactivé pour cette société.'
  } catch (error) {
    showConfigurationRequestError(error, companyUserManagementErrors)
  } finally {
    configurationBusy.value = false
  }
}

function requestCompanyUserStatusChange(isActive: boolean): void {
  const companyUser = selectedCompanyUser.value
  if (!companyUser) return

  requestConfirmation({
    title: isActive ? 'Réactiver l’accès' : 'Désactiver l’accès',
    message: isActive
      ? `L’accès de ${companyUser.name} sera rétabli pour cette société.`
      : `L’accès de ${companyUser.name} sera supprimé pour cette société. Les données et le journal restent conservés.`,
    confirm_label: isActive ? 'Réactiver' : 'Désactiver',
    danger: !isActive,
    action: () => executeCompanyUserStatusChange(isActive),
  })
}

async function resetCompanyUserPassword(): Promise<void> {
  const companyId = configurationCompanyId.value
  const companyUser = selectedCompanyUser.value
  if (!companyId || !companyUser) return

  companyUserManagementErrors.value = {}
  configurationError.value = false
  configurationMessage.value = ''

  if (!hasValidPassword(companyUserManagementForm.password)) {
    companyUserManagementErrors.value = {
      password: 'Utilisez au moins 12 caractères, avec majuscule, minuscule, chiffre et symbole.',
    }
    configurationError.value = true
    configurationMessage.value = 'Vérifiez les champs signalés.'
    return
  }

  if (companyUserManagementForm.password !== companyUserManagementForm.password_confirmation) {
    companyUserManagementErrors.value = { password_confirmation: 'Les deux mots de passe ne correspondent pas.' }
    configurationError.value = true
    configurationMessage.value = 'Vérifiez les champs signalés.'
    return
  }

  configurationBusy.value = true

  try {
    const result = await requestApi<{ message: string }>(`/api/v1/system/configuration/companies/${companyId}/users/${companyUser.id}/reset-password`, {
      method: 'POST',
      body: JSON.stringify({
        password: companyUserManagementForm.password,
        password_confirmation: companyUserManagementForm.password_confirmation,
      }),
    })
    companyUserManagementForm.password = ''
    companyUserManagementForm.password_confirmation = ''
    configurationMessage.value = result.message
  } catch (error) {
    showConfigurationRequestError(error, companyUserManagementErrors)
  } finally {
    configurationBusy.value = false
  }
}

function requestCompanyUserDeletion(): void {
  const companyUser = selectedCompanyUser.value
  if (!companyUser) return

  requestConfirmation({
    title: 'Supprimer définitivement l’utilisateur',
    message: `Saisissez le courriel de ${companyUser.name} dans l’étape suivante. Le compte sera supprimé définitivement. Les transactions et le journal d’audit resteront conservés.`,
    confirm_label: 'Continuer',
    danger: true,
    action: async () => {
      // La confirmation finale demande le courriel afin d'éviter une suppression accidentelle.
      deletionConfirmationEmail.value = ''
      deletionConfirmationUser.value = companyUser
      deletionConfirmationOpen.value = true
    },
  })
}

async function executeCompanyUserDeletion(): Promise<void> {
  const companyId = configurationCompanyId.value
  const companyUser = deletionConfirmationUser.value
  if (!companyId || !companyUser) return

  configurationBusy.value = true
  configurationError.value = false
  companyUserManagementErrors.value = {}

  try {
    await requestApi(`/api/v1/system/configuration/companies/${companyId}/users/${companyUser.id}`, {
      method: 'DELETE',
      body: JSON.stringify({ confirmation_email: deletionConfirmationEmail.value.trim().toLowerCase() }),
    })
    configurationCompanyUsers.value = configurationCompanyUsers.value.filter((item) => item.id !== companyUser.id)
    deletionConfirmationOpen.value = false
    deletionConfirmationEmail.value = ''
    deletionConfirmationUser.value = null
    resetCompanyUserManagement()
    configurationMessage.value = 'Utilisateur supprimé définitivement.'
  } catch (error) {
    showConfigurationRequestError(error, companyUserManagementErrors)
  } finally {
    configurationBusy.value = false
  }
}

function closeDeletionConfirmation(): void {
  if (configurationBusy.value) return

  deletionConfirmationOpen.value = false
  deletionConfirmationEmail.value = ''
  deletionConfirmationUser.value = null
}

function requestConfirmation(request: ConfirmationRequest): void {
  confirmationRequest.value = request
}

function cancelConfirmation(): void {
  if (!confirmationBusy.value) {
    confirmationRequest.value = null
  }
}

async function confirmRequestedAction(): Promise<void> {
  const request = confirmationRequest.value
  if (!request) return

  confirmationBusy.value = true

  try {
    await request.action()
    confirmationRequest.value = null
  } finally {
    confirmationBusy.value = false
  }
}

async function createSystemCompany(): Promise<void> {
  configurationBusy.value = true
  configurationMessage.value = ''
  configurationError.value = false
  companyConfigurationErrors.value = {}
  normalizeCompanyConfigurationCode()

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
    configurationMessage.value = 'Société créée. Vous pouvez maintenant ajouter une adresse.'
  } catch (error) {
    showConfigurationRequestError(error, companyConfigurationErrors)
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
  siteConfigurationErrors.value = {}
  normalizeSiteConfigurationCode()

  try {
    const result = await requestApi<{ data: SystemSite }>(`/api/v1/system/configuration/companies/${companyId}/sites`, {
      method: 'POST',
      body: JSON.stringify(siteConfigurationForm),
    })

    Object.assign(siteConfigurationForm, { code: '', name: '', address: '' })
    cashRegisterConfigurationForm.site_id = result.data.id
    await loadSystemConfiguration()
    configurationMessage.value = 'Adresse créée.'
  } catch (error) {
    showConfigurationRequestError(error, siteConfigurationErrors)
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
  cashRegisterConfigurationErrors.value = {}
  normalizeCashRegisterConfigurationCode()

  try {
    await requestApi<{ data: SystemCashRegister }>(`/api/v1/system/configuration/companies/${companyId}/cash-registers`, {
      method: 'POST',
      body: JSON.stringify(cashRegisterConfigurationForm),
    })

    Object.assign(cashRegisterConfigurationForm, {
      site_id: cashRegisterConfigurationForm.site_id,
      code: '',
      name: '',
    })
    await loadSystemConfiguration()
    configurationMessage.value = 'Caisse créée.'
  } catch (error) {
    showConfigurationRequestError(error, cashRegisterConfigurationErrors)
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
  configurationCompanyUsers.value = []
  resetCompanyUserManagement()
  confirmationRequest.value = null
  closeDeletionConfirmation()
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

function capHaitienDateParts(): { year: number; month: number; day: number } {
  const date = new Date()
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

  return {
    year: Number(values.year),
    month: Number(values.month),
    day: Number(values.day),
  }
}

function formatDateInput(year: number, month: number, day: number): string {
  return `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`
}

function calendarDefaultPeriod(): { from: string; to: string } {
  const today = capHaitienDateParts()
  const lastDayOfMonth = new Date(Date.UTC(today.year, today.month, 0)).getUTCDate()
  const isEndOfMonth = today.day >= lastDayOfMonth - 6

  if (!isEndOfMonth) {
    return {
      from: formatDateInput(today.year, today.month, 1),
      to: formatDateInput(today.year, today.month, lastDayOfMonth),
    }
  }

  const firstNextMonth = new Date(Date.UTC(today.year, today.month, 1))

  return {
    from: formatDateInput(today.year, today.month, 1),
    to: formatDateInput(firstNextMonth.getUTCFullYear(), firstNextMonth.getUTCMonth() + 1, 7),
  }
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
    apply_airport_pickup_fee: false,
    apply_airport_dropoff_fee: false,
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
  resetReservationWorkspace()
}

function resetReservationWorkspace(): void {
  Object.assign(reservationListFilters, {
    state: '',
    query: '',
    ...calendarDefaultPeriod(),
  })
  Object.assign(reservationManagementForm, {
    vehicle_id: '',
    pickup_at: '',
    due_at: '',
    extension_due_at: '',
    cancellation_reason: 'customer_request',
  })
  reservationList.value = []
  selectedReservation.value = null
  reservationManagementVehicles.value = []
  reservationListMessage.value = ''
  reservationListError.value = false
  reservationManagementMessage.value = ''
  reservationManagementError.value = false
}

function resetVehicleWorkspace(siteId = ''): void {
  Object.assign(vehicleForm, {
    site_id: siteId,
    category: 'suv',
    operational_status: 'available',
    make: '',
    model: '',
    model_year: '',
    registration_number: '',
    registration_status: 'normal',
    reference_photo_key: '',
    vin: '',
    latest_odometer_km: '',
  })
  fleetCatalogMessage.value = ''
  selectedVehicle.value = null
  selectedVehicleDocuments.value = []
  vehicleDocumentsMessage.value = ''
  vehicleDocumentsError.value = false
  Object.assign(vehicleRegistrationForm, {
    registration_number: '',
    registration_status: 'normal',
  })
  Object.assign(vehicleDocumentsForm, {
    registration_document_number: '',
    registration_issued_at: '',
    oavct_document_number: '',
    oavct_expires_at: '',
    tint_document_number: '',
    tint_expires_at: '',
  })
  Object.assign(vehicleFilters, {
    site_id: siteId,
    operational_status: '',
  })
  Object.assign(calendarForm, {
    site_id: '',
    ...calendarDefaultPeriod(),
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
    const result = await requestApi<{ data: CarRentalReservation; customer_notification_sent?: boolean }>('/api/v1/car-rental/reservations', {
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
        apply_airport_pickup_fee: reservationForm.pickup_location_type === 'cap_haitien_airport'
          && reservationForm.apply_airport_pickup_fee,
        apply_airport_dropoff_fee: reservationForm.dropoff_location_type === 'cap_haitien_airport'
          && reservationForm.apply_airport_dropoff_fee,
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
    rentalMessage.value = result.customer_notification_sent
      ? `Réservation ${result.data.number} créée. Le courriel de confirmation a été envoyé au client.`
      : `Réservation ${result.data.number} créée et journalisée.`
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

function formatCapHaitienDateTimeInput(value: string): string {
  const date = new Date(value)
  const values = Object.fromEntries(
    new Intl.DateTimeFormat('en-US', {
      timeZone: 'America/Port-au-Prince',
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
      hourCycle: 'h23',
    })
      .formatToParts(date)
      .filter((part) => part.type !== 'literal')
      .map((part) => [part.type, part.value]),
  )

  return `${values.year}-${values.month}-${values.day}T${values.hour}:${values.minute}`
}

function applyManagedReservation(reservation: CarRentalReservation): void {
  selectedReservation.value = reservation
  Object.assign(reservationManagementForm, {
    vehicle_id: reservation.vehicle?.id ?? '',
    pickup_at: formatCapHaitienDateTimeInput(reservation.pickup_at),
    due_at: formatCapHaitienDateTimeInput(reservation.due_at),
    extension_due_at: formatCapHaitienDateTimeInput(reservation.due_at),
    cancellation_reason: 'customer_request',
  })

  const entry: CarRentalReservationListEntry = {
    id: reservation.id,
    number: reservation.number,
    state: reservation.state,
    pickup_at: reservation.pickup_at,
    due_at: reservation.due_at,
    site: reservation.site,
    vehicle: reservation.vehicle,
    customer: reservation.customer,
  }
  const exists = reservationList.value.some((item) => item.id === reservation.id)
  reservationList.value = exists
    ? reservationList.value.map((item) => item.id === reservation.id ? entry : item)
    : [entry, ...reservationList.value]
}

async function loadReservationList(): Promise<void> {
  reservationListError.value = false
  reservationListMessage.value = ''

  if (!hasPermission('rental.reservations.read')) {
    reservationListError.value = true
    reservationListMessage.value = 'Votre rôle ne permet pas de consulter les réservations.'
    return
  }

  reservationListBusy.value = true

  try {
    const parameters = new URLSearchParams({
      from: reservationListFilters.from,
      to: reservationListFilters.to,
    })
    if (reservationListFilters.query.trim()) parameters.set('query', reservationListFilters.query.trim())
    if (reservationListFilters.state) parameters.set('state', reservationListFilters.state)

    const result = await requestApi<CarRentalReservationListResponse>(`/api/v1/car-rental/reservations?${parameters}`, {
      headers: contextHeaders(),
    })
    reservationList.value = result.data
    reservationListMessage.value = result.data.length === 0
      ? 'Aucune réservation ne correspond aux critères.'
      : `${result.data.length} réservation${result.data.length > 1 ? 's' : ''} affichée${result.data.length > 1 ? 's' : ''}.`
  } catch (error) {
    reservationListError.value = true
    reservationListMessage.value = messageFrom(error)
    reservationList.value = []
  } finally {
    reservationListBusy.value = false
  }
}

async function loadReservationManagementVehicles(siteId: string): Promise<void> {
  if (!siteId || !hasPermission('rental.vehicles.read')) {
    reservationManagementVehicles.value = []
    return
  }

  try {
    const parameters = new URLSearchParams({ site_id: siteId })
    const result = await requestApi<CarRentalVehicleListResponse>(`/api/v1/car-rental/vehicles?${parameters}`, {
      headers: contextHeaders(),
    })
    reservationManagementVehicles.value = result.data
  } catch {
    reservationManagementVehicles.value = []
  }
}

async function selectReservation(entry: CarRentalReservationListEntry): Promise<void> {
  reservationDetailsBusy.value = true
  reservationManagementError.value = false
  reservationManagementMessage.value = ''

  try {
    const result = await requestApi<{ data: CarRentalReservation }>(`/api/v1/car-rental/reservations/${entry.id}`, {
      headers: contextHeaders(),
    })
    applyManagedReservation(result.data)
    await loadReservationManagementVehicles(result.data.site_id)
  } catch (error) {
    reservationManagementError.value = true
    reservationManagementMessage.value = messageFrom(error)
  } finally {
    reservationDetailsBusy.value = false
  }
}

async function saveReservationSchedule(): Promise<void> {
  const reservation = selectedReservation.value

  if (!reservation || !reservationManagementForm.vehicle_id || !reservationManagementForm.pickup_at || !reservationManagementForm.due_at) {
    reservationManagementError.value = true
    reservationManagementMessage.value = 'Sélectionnez un véhicule et renseignez les dates avant d’enregistrer.'
    return
  }

  reservationDetailsBusy.value = true
  reservationManagementError.value = false
  reservationManagementMessage.value = ''

  try {
    const result = await requestApi<{ data: CarRentalReservation }>(`/api/v1/car-rental/reservations/${reservation.id}`, {
      method: 'PATCH',
      headers: contextHeaders(),
      body: JSON.stringify({
        vehicle_id: reservationManagementForm.vehicle_id,
        pickup_at: reservationManagementForm.pickup_at,
        due_at: reservationManagementForm.due_at,
        expected_lock_version: reservation.lock_version,
      }),
    })
    applyManagedReservation(result.data)
    reservationManagementMessage.value = 'Réservation mise à jour. Le tarif et les paiements existants n’ont pas été modifiés.'
    void loadCalendar()
  } catch (error) {
    reservationManagementError.value = true
    reservationManagementMessage.value = messageFrom(error)
  } finally {
    reservationDetailsBusy.value = false
  }
}

async function executeReservationCheckOut(): Promise<void> {
  const reservation = selectedReservation.value
  if (!reservation) return

  reservationDetailsBusy.value = true
  reservationManagementError.value = false

  try {
    const result = await requestApi<{ data: CarRentalReservation; customer_notification_sent?: boolean }>(`/api/v1/car-rental/reservations/${reservation.id}/check-out`, {
      method: 'POST',
      headers: contextHeaders(),
      body: JSON.stringify({ expected_lock_version: reservation.lock_version }),
    })
    applyManagedReservation(result.data)
    reservationManagementMessage.value = result.customer_notification_sent
      ? 'Location mise en circulation. Le courriel client a été envoyé.'
      : 'Location mise en circulation.'
    void loadCalendar()
  } catch (error) {
    reservationManagementError.value = true
    reservationManagementMessage.value = messageFrom(error)
  } finally {
    reservationDetailsBusy.value = false
  }
}

function requestReservationCheckOut(): void {
  const reservation = selectedReservation.value
  if (!reservation) return

  requestConfirmation({
    title: 'Mettre le véhicule en circulation',
    message: `La réservation ${reservation.number} passera au statut « En circulation ».`,
    confirm_label: 'Mettre en circulation',
    action: executeReservationCheckOut,
  })
}

async function extendReservation(): Promise<void> {
  const reservation = selectedReservation.value
  if (!reservation || !reservationManagementForm.extension_due_at) {
    reservationManagementError.value = true
    reservationManagementMessage.value = 'Saisissez la nouvelle date de retour.'
    return
  }

  reservationDetailsBusy.value = true
  reservationManagementError.value = false
  reservationManagementMessage.value = ''

  try {
    const result = await requestApi<{ data: CarRentalReservation; customer_notification_sent?: boolean }>(`/api/v1/car-rental/reservations/${reservation.id}/extend`, {
      method: 'POST',
      headers: contextHeaders(),
      body: JSON.stringify({
        due_at: reservationManagementForm.extension_due_at,
        expected_lock_version: reservation.lock_version,
      }),
    })
    applyManagedReservation(result.data)
    reservationManagementMessage.value = result.customer_notification_sent
      ? 'Date de retour mise à jour. Le courriel client a été envoyé.'
      : 'Date de retour mise à jour. Toute facturation complémentaire est enregistrée séparément.'
    void loadCalendar()
  } catch (error) {
    reservationManagementError.value = true
    reservationManagementMessage.value = messageFrom(error)
  } finally {
    reservationDetailsBusy.value = false
  }
}

async function executeReservationReturn(): Promise<void> {
  const reservation = selectedReservation.value
  if (!reservation) return

  reservationDetailsBusy.value = true
  reservationManagementError.value = false

  try {
    const result = await requestApi<{ data: CarRentalReservation; customer_notification_sent?: boolean }>(`/api/v1/car-rental/reservations/${reservation.id}/return`, {
      method: 'POST',
      headers: contextHeaders(),
      body: JSON.stringify({ expected_lock_version: reservation.lock_version }),
    })
    applyManagedReservation(result.data)
    reservationManagementMessage.value = result.customer_notification_sent
      ? 'Retour enregistré. Le courriel client a été envoyé.'
      : 'Retour enregistré. Le montant prévu au contrat n’a pas été recalculé.'
    void loadCalendar()
  } catch (error) {
    reservationManagementError.value = true
    reservationManagementMessage.value = messageFrom(error)
  } finally {
    reservationDetailsBusy.value = false
  }
}

function requestReservationReturn(): void {
  const reservation = selectedReservation.value
  if (!reservation) return

  requestConfirmation({
    title: 'Enregistrer le retour',
    message: 'Le véhicule passera en préparation. Le tarif et les paiements existants ne seront pas modifiés.',
    confirm_label: 'Enregistrer le retour',
    action: executeReservationReturn,
  })
}

async function executeReservationCancellation(): Promise<void> {
  const reservation = selectedReservation.value
  if (!reservation) return

  reservationDetailsBusy.value = true
  reservationManagementError.value = false

  try {
    const result = await requestApi<{ data: CarRentalReservation }>(`/api/v1/car-rental/reservations/${reservation.id}/cancel`, {
      method: 'POST',
      headers: contextHeaders(),
      body: JSON.stringify({
        reason_code: reservationManagementForm.cancellation_reason,
        expected_lock_version: reservation.lock_version,
      }),
    })
    applyManagedReservation(result.data)
    reservationManagementMessage.value = 'Réservation annulée. Aucun remboursement n’a été créé automatiquement.'
    void loadCalendar()
  } catch (error) {
    reservationManagementError.value = true
    reservationManagementMessage.value = messageFrom(error)
  } finally {
    reservationDetailsBusy.value = false
  }
}

function requestReservationCancellation(): void {
  const reservation = selectedReservation.value
  if (!reservation) return

  requestConfirmation({
    title: 'Annuler la réservation',
    message: `La réservation ${reservation.number} sera annulée. Cette action ne crée pas de remboursement automatique.`,
    confirm_label: 'Annuler la réservation',
    danger: true,
    action: executeReservationCancellation,
  })
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
    if (selectedVehicle.value && !result.data.some((vehicle) => vehicle.id === selectedVehicle.value?.id)) {
      selectedVehicle.value = null
      selectedVehicleDocuments.value = []
    }
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

  if (!vehicleForm.site_id || !vehicleForm.registration_number.trim() || vehicleForm.latest_odometer_km === '') {
    vehicleError.value = true
    vehicleMessage.value = 'Renseignez l’adresse, la plaque en cours et le kilométrage actuel.'
    return
  }

  vehicleBusy.value = true

  try {
    const result = await requestApi<{ data: RentalVehicle }>('/api/v1/car-rental/vehicles', {
      method: 'POST',
      headers: contextHeaders(),
      body: JSON.stringify({
        site_id: vehicleForm.site_id,
        category: vehicleForm.category,
        operational_status: vehicleForm.operational_status,
        make: vehicleForm.make.trim() || undefined,
        model: vehicleForm.model.trim() || undefined,
        model_year: vehicleForm.model_year === '' ? undefined : Number(vehicleForm.model_year),
        registration_number: vehicleForm.registration_number.trim(),
        registration_status: vehicleForm.registration_status,
        reference_photo_key: vehicleForm.reference_photo_key || undefined,
        vin: vehicleForm.vin.trim() || undefined,
        latest_odometer_km: Number(vehicleForm.latest_odometer_km),
      }),
    })

    const siteId = vehicleForm.site_id
    Object.assign(vehicleForm, {
      category: 'suv',
      operational_status: 'available',
      make: '',
      model: '',
      model_year: '',
      registration_number: '',
      registration_status: 'normal',
      reference_photo_key: '',
      vin: '',
      latest_odometer_km: '',
      site_id: siteId,
    })
    fleetCatalogMessage.value = ''
    vehicleMessage.value = `Véhicule ${result.data.registration_number ?? result.data.code} enregistré.`

    if (!vehicleFilters.site_id || vehicleFilters.site_id === result.data.site_id) {
      managedVehicles.value = [
        result.data,
        ...managedVehicles.value.filter((vehicle) => vehicle.id !== result.data.id),
      ].sort((left, right) => left.code.localeCompare(right.code, 'fr'))
    }
    await selectVehicle(result.data)
  } catch (error) {
    vehicleError.value = true
    vehicleMessage.value = messageFrom(error)
  } finally {
    vehicleBusy.value = false
  }
}


function prefillVehicleFromCatalog(candidate: FleetCatalogVehicle): void {
  const normalizedFleetAddress = normalizeFleetSiteText(CLIENTELE_CAR_RENTAL_FLEET_ADDRESS)
  const defaultFleetSite = activeContext.value?.sites.find((site) => (
    normalizeFleetSiteText(`${site.name} ${site.address}`).includes(normalizedFleetAddress)
  ))

  Object.assign(vehicleForm, {
    site_id: defaultFleetSite?.id ?? '',
    category: candidate.category,
    operational_status: 'available',
    make: candidate.make,
    model: candidate.model,
    model_year: '',
    registration_number: candidate.registrationNumber,
    registration_status: candidate.registrationStatus,
    reference_photo_key: candidate.referencePhoto?.key ?? '',
    vin: '',
    latest_odometer_km: '',
  })

  const siteMessage = defaultFleetSite === undefined
    ? `Créez ou sélectionnez l’adresse « ${CLIENTELE_CAR_RENTAL_FLEET_ADDRESS} », puis saisissez le kilométrage actuel.`
    : `L’adresse « ${CLIENTELE_CAR_RENTAL_FLEET_ADDRESS} » a été sélectionnée. Saisissez le kilométrage actuel.`

  fleetCatalogMessage.value = candidate.requiresReview
    ? `Informations préremplies. Vérifiez la plaque et le modèle avant l’enregistrement. ${siteMessage}`
    : `Informations préremplies. ${siteMessage}`

  vehicleCreateSection.value?.scrollIntoView({ behavior: 'smooth', block: 'start' })
}

function normalizeFleetSiteText(value: string): string {
  return value
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, ' ')
    .trim()
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
    applyVehicleUpdate(result.data)
    calendarVehicles.value = calendarVehicles.value.map((item) => item.id === result.data.id ? result.data : item)
    vehicleMessage.value = `État de ${result.data.registration_number ?? result.data.code} mis à jour : ${vehicleStatusLabels[result.data.operational_status]}.`
  } catch (error) {
    vehicleError.value = true
    vehicleMessage.value = messageFrom(error)
  } finally {
    vehicleBusy.value = false
  }
}

function applyVehicleUpdate(vehicle: RentalVehicle): void {
  managedVehicles.value = managedVehicles.value.map((item) => item.id === vehicle.id ? vehicle : item)
  if (selectedVehicle.value?.id === vehicle.id) {
    selectedVehicle.value = vehicle
  }
}

function documentFor(type: VehicleDocumentType): RentalVehicleDocument | undefined {
  return selectedVehicleDocuments.value.find((document) => document.type === type)
}

function vehicleDocumentStatusFor(vehicle: RentalVehicle, type: VehicleDocumentType): VehicleDocumentStatus {
  return vehicle.document_statuses?.find((document) => document.type === type)?.status ?? 'not_recorded'
}

function applyVehicleDocuments(documents: RentalVehicleDocument[]): void {
  selectedVehicleDocuments.value = documents
  const registration = documentFor('registration')
  const oavct = documentFor('oavct_insurance')
  const tint = documentFor('tint_permit')

  Object.assign(vehicleDocumentsForm, {
    registration_document_number: registration?.document_number ?? '',
    registration_issued_at: registration?.issued_at ?? '',
    oavct_document_number: oavct?.document_number ?? '',
    oavct_expires_at: oavct?.expires_at ?? '',
    tint_document_number: tint?.document_number ?? '',
    tint_expires_at: tint?.expires_at ?? '',
  })

  if (selectedVehicle.value) {
    const documentStatuses = (Object.keys(vehicleDocumentTypeLabels) as VehicleDocumentType[]).map((type) => {
      const document = documents.find((item) => item.type === type)

      return {
        type,
        status: document?.status ?? 'not_recorded',
        expires_at: document?.expires_at ?? null,
      }
    })
    applyVehicleUpdate({
      ...selectedVehicle.value,
      document_statuses: documentStatuses,
    })
  }
}

async function selectVehicle(vehicle: RentalVehicle): Promise<void> {
  if (!canManageVehicles.value) {
    return
  }

  selectedVehicle.value = vehicle
  vehicleRegistrationForm.registration_number = vehicle.registration_number ?? vehicle.code
  vehicleRegistrationForm.registration_status = vehicle.registration_status ?? 'normal'
  vehicleDocumentsMessage.value = ''
  vehicleDocumentsError.value = false
  vehicleDocumentsBusy.value = true
  const vehicleId = vehicle.id

  try {
    const result = await requestApi<{ data: RentalVehicleDocument[] }>(`/api/v1/car-rental/vehicles/${vehicleId}/documents`, {
      headers: contextHeaders(),
    })

    if (selectedVehicle.value?.id === vehicleId) {
      applyVehicleDocuments(result.data)
    }
  } catch (error) {
    if (selectedVehicle.value?.id === vehicleId) {
      vehicleDocumentsError.value = true
      vehicleDocumentsMessage.value = messageFrom(error)
      selectedVehicleDocuments.value = []
    }
  } finally {
    vehicleDocumentsBusy.value = false
  }
}

async function updateVehicleRegistration(): Promise<void> {
  const vehicle = selectedVehicle.value
  if (!vehicle || !vehicleRegistrationForm.registration_number.trim()) {
    vehicleDocumentsError.value = true
    vehicleDocumentsMessage.value = 'Saisissez la plaque en cours avant de l’enregistrer.'
    return
  }

  vehicleDocumentsBusy.value = true
  vehicleDocumentsError.value = false
  vehicleDocumentsMessage.value = ''

  try {
    const result = await requestApi<{ data: RentalVehicle }>(`/api/v1/car-rental/vehicles/${vehicle.id}/registration`, {
      method: 'PATCH',
      headers: contextHeaders(),
      body: JSON.stringify({
        registration_number: vehicleRegistrationForm.registration_number.trim(),
        registration_status: vehicleRegistrationForm.registration_status,
      }),
    })
    applyVehicleUpdate(result.data)
    vehicleRegistrationForm.registration_number = result.data.registration_number ?? result.data.code
    vehicleRegistrationForm.registration_status = result.data.registration_status ?? 'normal'
    vehicleDocumentsMessage.value = 'Plaque mise à jour. L’ancienne plaque est conservée dans l’historique du véhicule.'
  } catch (error) {
    vehicleDocumentsError.value = true
    vehicleDocumentsMessage.value = messageFrom(error)
  } finally {
    vehicleDocumentsBusy.value = false
  }
}

async function saveVehicleDocuments(): Promise<void> {
  const vehicle = selectedVehicle.value
  if (!vehicle) {
    return
  }

  const documents: Array<Record<string, string>> = []
  const registrationHasValue = vehicleDocumentsForm.registration_document_number.trim() || vehicleDocumentsForm.registration_issued_at
  const oavctHasValue = vehicleDocumentsForm.oavct_document_number.trim() || vehicleDocumentsForm.oavct_expires_at
  const tintHasValue = vehicleDocumentsForm.tint_document_number.trim() || vehicleDocumentsForm.tint_expires_at

  if (registrationHasValue) {
    documents.push({
      type: 'registration',
      document_number: vehicleDocumentsForm.registration_document_number.trim(),
      issued_at: vehicleDocumentsForm.registration_issued_at,
    })
  }
  if (oavctHasValue) {
    documents.push({
      type: 'oavct_insurance',
      document_number: vehicleDocumentsForm.oavct_document_number.trim(),
      expires_at: vehicleDocumentsForm.oavct_expires_at,
    })
  }
  if (tintHasValue) {
    documents.push({
      type: 'tint_permit',
      document_number: vehicleDocumentsForm.tint_document_number.trim(),
      expires_at: vehicleDocumentsForm.tint_expires_at,
    })
  }

  if (!documents.length) {
    vehicleDocumentsError.value = true
    vehicleDocumentsMessage.value = 'Saisissez au moins une référence ou une date avant d’enregistrer.'
    return
  }

  vehicleDocumentsBusy.value = true
  vehicleDocumentsError.value = false
  vehicleDocumentsMessage.value = ''

  try {
    const result = await requestApi<{ data: RentalVehicleDocument[] }>(`/api/v1/car-rental/vehicles/${vehicle.id}/documents`, {
      method: 'PUT',
      headers: contextHeaders(),
      body: JSON.stringify({ documents }),
    })
    applyVehicleDocuments(result.data)
    vehicleDocumentsMessage.value = 'Documents enregistrés.'
  } catch (error) {
    vehicleDocumentsError.value = true
    vehicleDocumentsMessage.value = messageFrom(error)
  } finally {
    vehicleDocumentsBusy.value = false
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

  if (section === 'Réservations') {
    void loadReservationList()
  }
  if (section === 'Calendrier') {
    void loadCalendar()
  }
  if (section === 'Véhicules') {
    void loadVehicles()
  }
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
    if (error.message.startsWith('validation.')) {
      return 'Vérifiez les champs signalés.'
    }

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
          <span><strong>{{ user?.name }}</strong> · Configuration système</span>
          <button class="text-button change-company" type="button" :disabled="configurationBusy" @click="closeSystemConfiguration">
            Retour aux sociétés
          </button>
          <button class="text-button" type="button" @click="logout">Fermer la session</button>
        </section>

        <section class="configuration-header">
          <p class="eyebrow">Configuration système</p>
          <h1 id="configuration-title">Sociétés et utilisateurs</h1>
          <p>Créez les sociétés, les adresses, les caisses et les accès nécessaires. Les modifications sont journalisées.</p>
        </section>

        <p v-if="configurationMessage" class="configuration-message" :class="{ error: configurationError }" :role="configurationError ? 'alert' : 'status'" aria-live="polite">
          {{ configurationMessage }}
        </p>

        <div class="configuration-workspace">
          <section class="configuration-form-card">
            <div class="section-intro">
              <p class="eyebrow">Société</p>
              <h2>Ajouter une société</h2>
              <p>Saisissez les informations légales et la devise de base.</p>
            </div>

            <form class="configuration-form" @submit.prevent="createSystemCompany">
              <div class="form-field">
                <label for="company-code">Code interne</label>
                <input id="company-code" v-model.trim="companyConfigurationForm.code" name="code" type="text" maxlength="32" autocapitalize="characters" placeholder="Ex. CLIENTELE-RENT-A-CAR" required :disabled="configurationBusy" :aria-invalid="Boolean(companyConfigurationErrors.code)" :aria-describedby="companyConfigurationErrors.code ? 'company-code-help company-code-error' : 'company-code-help'" @blur="normalizeCompanyConfigurationCode" @input="clearConfigurationFormFieldError('company', 'code')" />
                <span id="company-code-help" class="field-help">Vous pouvez saisir un nom. Les espaces et accents sont convertis automatiquement.</span>
                <span v-if="companyConfigurationErrors.code" id="company-code-error" class="field-error" role="alert">{{ companyConfigurationErrors.code }}</span>
              </div>
              <div class="form-field">
                <label for="company-legal-name">Dénomination légale</label>
                <input id="company-legal-name" v-model.trim="companyConfigurationForm.legal_name" name="legal_name" type="text" maxlength="255" required :disabled="configurationBusy" :aria-invalid="Boolean(companyConfigurationErrors.legal_name)" :aria-describedby="companyConfigurationErrors.legal_name ? 'company-legal-name-error' : undefined" @input="clearConfigurationFormFieldError('company', 'legal_name')" />
                <span v-if="companyConfigurationErrors.legal_name" id="company-legal-name-error" class="field-error" role="alert">{{ companyConfigurationErrors.legal_name }}</span>
              </div>
              <div class="form-field">
                <label for="company-display-name">Nom affiché</label>
                <input id="company-display-name" v-model.trim="companyConfigurationForm.display_name" name="display_name" type="text" maxlength="255" required :disabled="configurationBusy" :aria-invalid="Boolean(companyConfigurationErrors.display_name)" :aria-describedby="companyConfigurationErrors.display_name ? 'company-display-name-error' : undefined" @input="clearConfigurationFormFieldError('company', 'display_name')" />
                <span v-if="companyConfigurationErrors.display_name" id="company-display-name-error" class="field-error" role="alert">{{ companyConfigurationErrors.display_name }}</span>
              </div>
              <div class="form-field">
                <label for="company-base-currency">Devise de base</label>
                <select id="company-base-currency" v-model="companyConfigurationForm.base_currency" name="base_currency" :disabled="configurationBusy" :aria-invalid="Boolean(companyConfigurationErrors.base_currency)" :aria-describedby="companyConfigurationErrors.base_currency ? 'company-base-currency-error' : undefined" @change="clearConfigurationFormFieldError('company', 'base_currency')">
                  <option value="HTG">HTG</option>
                  <option value="USD">USD</option>
                </select>
                <span v-if="companyConfigurationErrors.base_currency" id="company-base-currency-error" class="field-error" role="alert">{{ companyConfigurationErrors.base_currency }}</span>
              </div>
              <button class="primary-button" type="submit" :disabled="configurationBusy || apiStatus !== 'online'">
                {{ configurationBusy ? 'Enregistrement…' : 'Créer la société' }}
              </button>
            </form>
          </section>

          <section class="configuration-form-card">
            <div class="section-intro">
              <p class="eyebrow">Adresse</p>
              <h2>Ajouter une adresse</h2>
              <p>Sélectionnez la société concernée.</p>
            </div>

            <form class="configuration-form" @submit.prevent="createSystemSite">
              <div class="form-field">
                <label for="site-company-id">Société</label>
                <select id="site-company-id" v-model="configurationCompanyId" name="company_id" :disabled="configurationBusy || !configurationCompanies.length" @change="selectConfigurationCompany">
                  <option value="" disabled>Sélectionnez une société</option>
                  <option v-for="company in configurationCompanies" :key="company.id" :value="company.id">
                    {{ company.display_name }} · {{ company.code }}
                  </option>
                </select>
              </div>
              <div class="form-field">
                <label for="site-code">Code d’adresse</label>
                <input id="site-code" v-model.trim="siteConfigurationForm.code" name="code" type="text" maxlength="32" autocapitalize="characters" placeholder="Ex. CAP-01" required :disabled="configurationBusy || !configurationCompanyId" :aria-invalid="Boolean(siteConfigurationErrors.code)" :aria-describedby="siteConfigurationErrors.code ? 'site-code-help site-code-error' : 'site-code-help'" @blur="normalizeSiteConfigurationCode" @input="clearConfigurationFormFieldError('site', 'code')" />
                <span id="site-code-help" class="field-help">Les espaces et accents sont convertis automatiquement.</span>
                <span v-if="siteConfigurationErrors.code" id="site-code-error" class="field-error" role="alert">{{ siteConfigurationErrors.code }}</span>
              </div>
              <div class="form-field">
                <label for="site-name">Nom de l’adresse</label>
                <input id="site-name" v-model.trim="siteConfigurationForm.name" name="name" type="text" maxlength="255" required :disabled="configurationBusy || !configurationCompanyId" :aria-invalid="Boolean(siteConfigurationErrors.name)" :aria-describedby="siteConfigurationErrors.name ? 'site-name-error' : undefined" @input="clearConfigurationFormFieldError('site', 'name')" />
                <span v-if="siteConfigurationErrors.name" id="site-name-error" class="field-error" role="alert">{{ siteConfigurationErrors.name }}</span>
              </div>
              <div class="form-field">
                <label for="site-address">Adresse complète</label>
                <textarea id="site-address" v-model.trim="siteConfigurationForm.address" name="address" rows="3" maxlength="1000" required :disabled="configurationBusy || !configurationCompanyId" :aria-invalid="Boolean(siteConfigurationErrors.address)" :aria-describedby="siteConfigurationErrors.address ? 'site-address-error' : undefined" @input="clearConfigurationFormFieldError('site', 'address')"></textarea>
                <span v-if="siteConfigurationErrors.address" id="site-address-error" class="field-error" role="alert">{{ siteConfigurationErrors.address }}</span>
              </div>
              <button class="primary-button" type="submit" :disabled="configurationBusy || apiStatus !== 'online' || !configurationCompanyId">
                {{ configurationBusy ? 'Enregistrement…' : 'Créer l’adresse' }}
              </button>
            </form>
          </section>

          <section class="configuration-form-card">
            <div class="section-intro">
              <p class="eyebrow">Caisse</p>
              <h2>Ajouter une caisse</h2>
              <p>Sélectionnez l’adresse où la caisse sera utilisée.</p>
            </div>

            <form class="configuration-form" @submit.prevent="createSystemCashRegister">
              <div class="form-field">
                <label for="cash-register-company-id">Société</label>
                <select id="cash-register-company-id" v-model="configurationCompanyId" name="company_id" :disabled="configurationBusy || !configurationCompanies.length" @change="selectConfigurationCompany">
                  <option value="" disabled>Sélectionnez une société</option>
                  <option v-for="company in configurationCompanies" :key="company.id" :value="company.id">
                    {{ company.display_name }} · {{ company.code }}
                  </option>
                </select>
              </div>
              <div class="form-field">
                <label for="cash-register-site-id">Adresse</label>
                <select id="cash-register-site-id" v-model="cashRegisterConfigurationForm.site_id" name="site_id" :disabled="configurationBusy || !selectedConfigurationCompany?.sites.length" :aria-invalid="Boolean(cashRegisterConfigurationErrors.site_id)" :aria-describedby="cashRegisterConfigurationErrors.site_id ? 'cash-register-site-id-error' : undefined" @change="clearConfigurationFormFieldError('cash-register', 'site_id')">
                  <option value="" disabled>Sélectionnez une adresse</option>
                  <option v-for="site in selectedConfigurationCompany?.sites ?? []" :key="site.id" :value="site.id">
                    {{ site.name }} · {{ site.code }}
                  </option>
                </select>
                <span v-if="cashRegisterConfigurationErrors.site_id" id="cash-register-site-id-error" class="field-error" role="alert">{{ cashRegisterConfigurationErrors.site_id }}</span>
              </div>
              <div class="form-field">
                <label for="cash-register-code">Code de caisse</label>
                <input id="cash-register-code" v-model.trim="cashRegisterConfigurationForm.code" name="code" type="text" maxlength="32" autocapitalize="characters" placeholder="Ex. POS-01" required :disabled="configurationBusy || !cashRegisterConfigurationForm.site_id" :aria-invalid="Boolean(cashRegisterConfigurationErrors.code)" :aria-describedby="cashRegisterConfigurationErrors.code ? 'cash-register-code-help cash-register-code-error' : 'cash-register-code-help'" @blur="normalizeCashRegisterConfigurationCode" @input="clearConfigurationFormFieldError('cash-register', 'code')" />
                <span id="cash-register-code-help" class="field-help">Les espaces et accents sont convertis automatiquement.</span>
                <span v-if="cashRegisterConfigurationErrors.code" id="cash-register-code-error" class="field-error" role="alert">{{ cashRegisterConfigurationErrors.code }}</span>
              </div>
              <div class="form-field">
                <label for="cash-register-name">Nom de la caisse</label>
                <input id="cash-register-name" v-model.trim="cashRegisterConfigurationForm.name" name="name" type="text" maxlength="255" required :disabled="configurationBusy || !cashRegisterConfigurationForm.site_id" :aria-invalid="Boolean(cashRegisterConfigurationErrors.name)" :aria-describedby="cashRegisterConfigurationErrors.name ? 'cash-register-name-error' : undefined" @input="clearConfigurationFormFieldError('cash-register', 'name')" />
                <span v-if="cashRegisterConfigurationErrors.name" id="cash-register-name-error" class="field-error" role="alert">{{ cashRegisterConfigurationErrors.name }}</span>
              </div>
              <button class="primary-button" type="submit" :disabled="configurationBusy || apiStatus !== 'online' || !cashRegisterConfigurationForm.site_id">
                {{ configurationBusy ? 'Enregistrement…' : 'Créer la caisse' }}
              </button>
            </form>
          </section>
        </div>

        <section class="configuration-user-card" aria-labelledby="company-users-title">
          <div class="section-intro">
            <p class="eyebrow">Utilisateurs</p>
            <h2 id="company-users-title">Utilisateurs Car Rental</h2>
            <p>Ajoutez, modifiez, désactivez ou supprimez définitivement un compte. Chaque action est journalisée.</p>
          </div>

          <div class="configuration-user-workspace">
            <form class="configuration-form" @submit.prevent="createCompanyUser">
              <div class="section-intro">
                <p class="eyebrow">Nouvel utilisateur</p>
                <h3>Créer un compte</h3>
              </div>
              <div class="form-field">
                <label for="company-user-company-id">Société</label>
                <select id="company-user-company-id" v-model="configurationCompanyId" name="company_id" :disabled="configurationBusy || !configurationCompanies.length" @change="selectConfigurationCompany">
                  <option value="" disabled>Sélectionnez une société</option>
                  <option v-for="company in configurationCompanies" :key="company.id" :value="company.id">
                    {{ company.display_name }} · {{ company.code }}
                  </option>
                </select>
              </div>
              <div class="two-columns">
                <div class="form-field">
                  <label for="company-user-name">Nom complet</label>
                  <input id="company-user-name" v-model.trim="companyUserConfigurationForm.name" name="name" type="text" maxlength="255" autocomplete="name" required :disabled="configurationBusy || !configurationCompanyId" :aria-invalid="Boolean(companyUserConfigurationErrors.name)" :aria-describedby="companyUserConfigurationErrors.name ? 'company-user-name-error' : undefined" @input="clearConfigurationFormFieldError('company-user', 'name')" />
                  <span v-if="companyUserConfigurationErrors.name" id="company-user-name-error" class="field-error" role="alert">{{ companyUserConfigurationErrors.name }}</span>
                </div>
                <div class="form-field">
                  <label for="company-user-email">Courriel personnel</label>
                  <input id="company-user-email" v-model.trim="companyUserConfigurationForm.email" name="email" type="email" maxlength="254" autocomplete="email" required :disabled="configurationBusy || !configurationCompanyId" :aria-invalid="Boolean(companyUserConfigurationErrors.email)" :aria-describedby="companyUserConfigurationErrors.email ? 'company-user-email-error' : undefined" @input="clearConfigurationFormFieldError('company-user', 'email')" />
                  <span v-if="companyUserConfigurationErrors.email" id="company-user-email-error" class="field-error" role="alert">{{ companyUserConfigurationErrors.email }}</span>
                </div>
              </div>
              <div class="form-field">
                <label for="company-user-role">Profil Car Rental</label>
                <select id="company-user-role" v-model="companyUserConfigurationForm.role_key" name="role_key" :disabled="configurationBusy || !configurationCompanyId" :aria-invalid="Boolean(companyUserConfigurationErrors.role_key)" :aria-describedby="companyUserConfigurationErrors.role_key ? 'company-user-role-error' : undefined" @change="clearConfigurationFormFieldError('company-user', 'role_key')">
                  <option v-for="(label, roleKey) in carRentalUserRoleLabels" :key="roleKey" :value="roleKey">{{ label }}</option>
                </select>
                <span v-if="companyUserConfigurationErrors.role_key" id="company-user-role-error" class="field-error" role="alert">{{ companyUserConfigurationErrors.role_key }}</span>
              </div>
              <fieldset class="site-scope-fields" :disabled="configurationBusy || !configurationCompanyId">
                <legend>Adresses autorisées</legend>
                <label><input v-model="companyUserConfigurationForm.site_scope" type="radio" value="all" name="company-user-site-scope" @change="onCompanyUserSiteScopeChanged" /> Toutes les adresses actives</label>
                <label><input v-model="companyUserConfigurationForm.site_scope" type="radio" value="selected" name="company-user-site-scope" @change="onCompanyUserSiteScopeChanged" /> Adresses sélectionnées</label>
                <div v-if="companyUserConfigurationForm.site_scope === 'selected'" class="site-checkbox-list">
                  <label v-for="site in selectedConfigurationCompany?.sites ?? []" :key="site.id">
                    <input v-model="companyUserConfigurationForm.site_ids" type="checkbox" :value="site.id" :disabled="configurationBusy" @change="clearConfigurationFormFieldError('company-user', 'site_ids')" />
                    {{ site.name }} · {{ site.code }}
                  </label>
                </div>
              </fieldset>
              <span v-if="companyUserConfigurationErrors.site_scope" class="field-error" role="alert">{{ companyUserConfigurationErrors.site_scope }}</span>
              <span v-if="companyUserConfigurationErrors.site_ids" class="field-error" role="alert">{{ companyUserConfigurationErrors.site_ids }}</span>
              <div class="two-columns">
                <div class="form-field">
                  <label for="company-user-password">Mot de passe initial</label>
                  <input id="company-user-password" v-model="companyUserConfigurationForm.password" name="password" type="password" minlength="12" autocomplete="new-password" required :disabled="configurationBusy || !configurationCompanyId" :aria-invalid="Boolean(companyUserConfigurationErrors.password)" :aria-describedby="companyUserConfigurationErrors.password ? 'company-user-password-help company-user-password-error' : 'company-user-password-help'" @input="clearConfigurationFormFieldError('company-user', 'password')" />
                  <span id="company-user-password-help" class="field-help">Au moins 12 caractères, avec majuscule, minuscule, chiffre et symbole.</span>
                  <span v-if="companyUserConfigurationErrors.password" id="company-user-password-error" class="field-error" role="alert">{{ companyUserConfigurationErrors.password }}</span>
                </div>
                <div class="form-field">
                  <label for="company-user-password-confirmation">Confirmer le mot de passe</label>
                  <input id="company-user-password-confirmation" v-model="companyUserConfigurationForm.password_confirmation" name="password_confirmation" type="password" minlength="12" autocomplete="new-password" required :disabled="configurationBusy || !configurationCompanyId" :aria-invalid="Boolean(companyUserConfigurationErrors.password_confirmation)" :aria-describedby="companyUserConfigurationErrors.password_confirmation ? 'company-user-password-confirmation-error' : undefined" @input="clearConfigurationFormFieldError('company-user', 'password_confirmation')" />
                  <span v-if="companyUserConfigurationErrors.password_confirmation" id="company-user-password-confirmation-error" class="field-error" role="alert">{{ companyUserConfigurationErrors.password_confirmation }}</span>
                </div>
              </div>
              <button class="primary-button" type="submit" :disabled="configurationBusy || apiStatus !== 'online' || !configurationCompanyId">
                {{ configurationBusy ? 'Enregistrement…' : 'Créer l’utilisateur' }}
              </button>
            </form>

            <section class="configuration-user-list" aria-labelledby="company-user-list-title">
              <div class="workspace-heading">
                <div>
                  <p class="eyebrow">Accès attribués</p>
                  <h3 id="company-user-list-title">Utilisateurs de la société</h3>
                </div>
                <button class="refresh-button" type="button" :disabled="configurationUsersBusy || !configurationCompanyId" @click="loadCompanyUsers">
                  {{ configurationUsersBusy ? 'Actualisation…' : 'Actualiser' }}
                </button>
              </div>
              <p v-if="!configurationCompanyId" class="field-help">Sélectionnez une société pour afficher ses utilisateurs.</p>
              <p v-else-if="configurationUsersBusy" class="field-help">Chargement des utilisateurs…</p>
              <div v-else-if="configurationCompanyUsers.length" class="company-user-list">
                <article v-for="companyUser in configurationCompanyUsers" :key="companyUser.id" class="company-user-item">
                  <div>
                    <strong>{{ companyUser.name }}</strong>
                    <span>{{ companyUser.email }}</span>
                  </div>
                  <p>{{ companyUserRoleLabel(companyUser.role_key) }}<span v-if="!companyUser.is_active"> · Accès désactivé</span></p>
                  <small>{{ companyUser.site_scope === 'all' ? 'Toutes les adresses actives' : companyUser.sites.map((site) => site.name).join(', ') }}</small>
                  <button v-if="!companyUser.is_system_owner" class="secondary-button company-user-manage-button" type="button" :disabled="configurationBusy" @click="selectCompanyUser(companyUser)">Gérer</button>
                  <small v-else>Le compte propriétaire se gère depuis la sécurité du compte.</small>
                </article>
              </div>
              <p v-else class="field-help">Aucun utilisateur n’est encore attribué à cette société.</p>
            </section>
          </div>
        </section>

        <section v-if="selectedCompanyUser" class="company-user-management-card" aria-labelledby="company-user-management-title">
          <div class="section-intro">
            <p class="eyebrow">Utilisateur sélectionné</p>
            <h2 id="company-user-management-title">Gérer {{ selectedCompanyUser.name }}</h2>
            <p>Modifiez l’accès de cet utilisateur pour la société sélectionnée.</p>
          </div>

          <p v-if="!selectedCompanyUser.can_edit_personal_profile" class="management-note">
            Les informations personnelles et le mot de passe sont gérés par l’utilisateur, car ce compte est actif dans une autre société.
          </p>

          <form class="company-user-management-form" @submit.prevent="saveCompanyUser">
            <div class="two-columns">
              <div class="form-field">
                <label for="managed-company-user-name">Nom complet</label>
                <input id="managed-company-user-name" v-model.trim="companyUserManagementForm.name" type="text" maxlength="255" autocomplete="name" required :disabled="configurationBusy || !selectedCompanyUser.can_edit_personal_profile" :aria-invalid="Boolean(companyUserManagementErrors.name)" @input="clearManagedCompanyUserFieldError('name')" />
                <span v-if="companyUserManagementErrors.name" class="field-error" role="alert">{{ companyUserManagementErrors.name }}</span>
              </div>
              <div class="form-field">
                <label for="managed-company-user-email">Courriel personnel</label>
                <input id="managed-company-user-email" v-model.trim="companyUserManagementForm.email" type="email" maxlength="254" autocomplete="email" required :disabled="configurationBusy || !selectedCompanyUser.can_edit_personal_profile" :aria-invalid="Boolean(companyUserManagementErrors.email)" @input="clearManagedCompanyUserFieldError('email')" />
                <span v-if="companyUserManagementErrors.email" class="field-error" role="alert">{{ companyUserManagementErrors.email }}</span>
              </div>
            </div>

            <div class="form-field">
              <label for="managed-company-user-role">Profil Car Rental</label>
              <select id="managed-company-user-role" v-model="companyUserManagementForm.role_key" :disabled="configurationBusy" :aria-invalid="Boolean(companyUserManagementErrors.role_key)" @change="clearManagedCompanyUserFieldError('role_key')">
                <option v-for="(label, roleKey) in carRentalUserRoleLabels" :key="roleKey" :value="roleKey">{{ label }}</option>
              </select>
              <span v-if="companyUserManagementErrors.role_key" class="field-error" role="alert">{{ companyUserManagementErrors.role_key }}</span>
            </div>

            <fieldset class="site-scope-fields" :disabled="configurationBusy">
              <legend>Adresses autorisées</legend>
              <label><input v-model="companyUserManagementForm.site_scope" type="radio" value="all" name="managed-company-user-site-scope" @change="onManagedCompanyUserSiteScopeChanged" /> Toutes les adresses actives</label>
              <label><input v-model="companyUserManagementForm.site_scope" type="radio" value="selected" name="managed-company-user-site-scope" @change="onManagedCompanyUserSiteScopeChanged" /> Adresses sélectionnées</label>
              <div v-if="companyUserManagementForm.site_scope === 'selected'" class="site-checkbox-list">
                <label v-for="site in selectedConfigurationCompany?.sites ?? []" :key="site.id">
                  <input v-model="companyUserManagementForm.site_ids" type="checkbox" :value="site.id" :disabled="configurationBusy" @change="clearManagedCompanyUserFieldError('site_ids')" />
                  {{ site.name }} · {{ site.code }}
                </label>
              </div>
            </fieldset>
            <span v-if="companyUserManagementErrors.site_scope" class="field-error" role="alert">{{ companyUserManagementErrors.site_scope }}</span>
            <span v-if="companyUserManagementErrors.site_ids" class="field-error" role="alert">{{ companyUserManagementErrors.site_ids }}</span>

            <button class="primary-button" type="submit" :disabled="configurationBusy || apiStatus !== 'online'">
              {{ configurationBusy ? 'Enregistrement…' : 'Enregistrer les modifications' }}
            </button>
          </form>

          <div class="company-user-management-actions">
            <section>
              <h3>Accès à la société</h3>
              <p>{{ selectedCompanyUser.is_active ? 'L’utilisateur peut accéder à cette société.' : 'L’accès à cette société est désactivé.' }}</p>
              <button v-if="selectedCompanyUser.is_active" class="secondary-button" type="button" :disabled="configurationBusy || apiStatus !== 'online'" @click="requestCompanyUserStatusChange(false)">Désactiver l’accès</button>
              <button v-else class="primary-button" type="button" :disabled="configurationBusy || apiStatus !== 'online'" @click="requestCompanyUserStatusChange(true)">Réactiver l’accès</button>
            </section>

            <section v-if="selectedCompanyUser.can_edit_personal_profile">
              <h3>Réinitialiser le mot de passe</h3>
              <p>Le nouveau mot de passe doit contenir au moins 12 caractères, une majuscule, une minuscule, un chiffre et un symbole.</p>
              <form class="password-reset-form" @submit.prevent="resetCompanyUserPassword">
                <label for="managed-company-user-password">
                  Nouveau mot de passe
                  <input id="managed-company-user-password" v-model="companyUserManagementForm.password" type="password" minlength="12" autocomplete="new-password" :disabled="configurationBusy" :aria-invalid="Boolean(companyUserManagementErrors.password)" @input="clearManagedCompanyUserFieldError('password')" />
                </label>
                <span v-if="companyUserManagementErrors.password" class="field-error" role="alert">{{ companyUserManagementErrors.password }}</span>
                <label for="managed-company-user-password-confirmation">
                  Confirmer le nouveau mot de passe
                  <input id="managed-company-user-password-confirmation" v-model="companyUserManagementForm.password_confirmation" type="password" minlength="12" autocomplete="new-password" :disabled="configurationBusy" :aria-invalid="Boolean(companyUserManagementErrors.password_confirmation)" @input="clearManagedCompanyUserFieldError('password_confirmation')" />
                </label>
                <span v-if="companyUserManagementErrors.password_confirmation" class="field-error" role="alert">{{ companyUserManagementErrors.password_confirmation }}</span>
                <button class="secondary-button" type="submit" :disabled="configurationBusy || apiStatus !== 'online'">Réinitialiser le mot de passe</button>
              </form>
            </section>

            <section class="danger-zone">
              <h3>Supprimer définitivement</h3>
              <p>Le compte sera supprimé. Les transactions et le journal d’audit restent conservés.</p>
              <button class="danger-button" type="button" :disabled="configurationBusy || apiStatus !== 'online' || !selectedCompanyUser.can_delete_permanently" @click="requestCompanyUserDeletion">Supprimer l’utilisateur</button>
              <p v-if="!selectedCompanyUser.can_delete_permanently" class="field-help">La suppression définitive n’est pas disponible pour un compte rattaché à une autre société.</p>
            </section>
          </div>
        </section>

        <section class="configuration-list-card">
          <div class="workspace-heading">
            <div>
              <p class="eyebrow">Configuration enregistrée</p>
              <h2>Sociétés, adresses et caisses</h2>
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
          Ouvrir la configuration système
        </button>
        <button class="text-button" type="button" @click="logout">Fermer la session</button>
      </section>

      <template v-else>
        <section class="session-strip" aria-label="Session active">
          <span class="avatar" aria-hidden="true">{{ userInitial }}</span>
          <span><strong>{{ user?.name }}</strong> · {{ activeCompanyName }}</span>
          <button v-if="canManageSystemConfiguration" class="text-button" type="button" @click="openSystemConfiguration">Configuration système</button>
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
                  <h3 id="reservation-title">Créer une réservation</h3>
                  <p>
                    Le bureau sélectionné est le lieu de départ par défaut. Les véhicules proposés appartiennent uniquement
                    à la société et à cette adresse. Une réservation concurrente est refusée.
                  </p>
                </div>

                <form class="reservation-form" @submit.prevent="createReservation">
                  <fieldset>
                    <legend>1 · Lieu et période</legend>
                    <label>
                      Bureau de départ
                      <select v-model="reservationForm.site_id" required :disabled="rentalBusy" @change="onReservationSiteChanged">
                        <option value="" disabled>Choisissez une adresse autorisée</option>
                        <option v-for="site in activeContext.sites" :key="site.id" :value="site.id">
                          {{ site.name }} · {{ site.address }}
                        </option>
                      </select>
                    </label>
                    <p v-if="activeSite" class="site-context">
                      Société : <strong>{{ activeContext.company.name }}</strong><br />
                      Bureau physique : <strong>{{ activeSite.name }} · {{ activeSite.address }}</strong>
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
                        Départ
                        <select v-model="reservationForm.pickup_location_type" :disabled="rentalBusy">
                          <option value="site">Bureau sélectionné</option>
                          <option value="cap_haitien_airport">Aéroport International du Cap-Haïtien</option>
                          <option value="custom">Autre lieu précisé</option>
                        </select>
                      </label>
                      <label>
                        Retour
                        <select v-model="reservationForm.dropoff_location_type" :disabled="rentalBusy">
                          <option value="site">Bureau sélectionné</option>
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
                    <fieldset v-if="reservationForm.pickup_location_type === 'cap_haitien_airport' || reservationForm.dropoff_location_type === 'cap_haitien_airport'" class="service-fee-options">
                      <legend>Frais de service aéroport</legend>
                      <label v-if="reservationForm.pickup_location_type === 'cap_haitien_airport'">
                        <input v-model="reservationForm.apply_airport_pickup_fee" type="checkbox" :disabled="rentalBusy" />
                        Appliquer 20 USD pour la prise en charge à l’aéroport
                      </label>
                      <label v-if="reservationForm.dropoff_location_type === 'cap_haitien_airport'">
                        <input v-model="reservationForm.apply_airport_dropoff_fee" type="checkbox" :disabled="rentalBusy" />
                        Appliquer 20 USD pour le retour à l’aéroport
                      </label>
                      <p v-if="airportFeesTotalUsd" class="field-help">Frais aéroport retenus : USD {{ airportFeesTotalUsd.toFixed(2) }}.</p>
                    </fieldset>
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
                  <span v-if="Number(reservationCreated.airport_fees_total_usd) > 0">Frais aéroport : USD {{ reservationCreated.airport_fees_total_usd }}</span>
                  <small>Statut : {{ reservationCreated.state }}</small>
                </div>
              </aside>
            </div>

            <section v-if="hasPermission('rental.reservations.read')" class="reservation-management-card" aria-labelledby="reservation-management-title">
              <div class="section-intro">
                <p class="eyebrow">Suivi des réservations</p>
                <h3 id="reservation-management-title">Rechercher et gérer</h3>
                <p>La liste du mois en cours est chargée automatiquement. Recherchez par référence ou par plaque, puis affinez si nécessaire.</p>
              </div>

              <form class="reservation-search-form" @submit.prevent="loadReservationList">
                <label>
                  Référence ou plaque
                  <input v-model.trim="reservationListFilters.query" type="search" inputmode="search" maxlength="32" placeholder="Ex. 0000 0001 ou LO-01723" :disabled="reservationListBusy" />
                </label>
                <label>
                  État
                  <select v-model="reservationListFilters.state" :disabled="reservationListBusy">
                    <option value="">Tous les états</option>
                    <option v-for="(label, state) in reservationStateLabels" :key="state" :value="state">{{ label }}</option>
                  </select>
                </label>
                <label>
                  Du
                  <input v-model="reservationListFilters.from" type="date" required :disabled="reservationListBusy" />
                </label>
                <label>
                  Au
                  <input v-model="reservationListFilters.to" type="date" required :disabled="reservationListBusy" />
                </label>
                <button class="refresh-button reservation-search-submit" type="submit" :disabled="reservationListBusy || apiStatus !== 'online'">
                  {{ reservationListBusy ? 'Actualisation…' : 'Actualiser' }}
                </button>
              </form>
              <p v-if="reservationListMessage" class="rental-message" :class="{ error: reservationListError }" role="status">{{ reservationListMessage }}</p>

              <div class="reservation-management-workspace">
                <section class="reservation-result-list" aria-label="Résultats de réservation">
                  <p v-if="reservationListBusy" class="field-help">Chargement des réservations…</p>
                  <div v-else-if="reservationList.length" class="reservation-result-grid">
                    <button
                      v-for="entry in reservationList"
                      :key="entry.id"
                      class="reservation-result-card"
                      :class="{ selected: selectedReservation?.id === entry.id }"
                      type="button"
                      :disabled="reservationDetailsBusy"
                      @click="selectReservation(entry)"
                    >
                      <span class="vehicle-code">{{ entry.number }}</span>
                      <strong>{{ entry.vehicle?.code ?? 'Véhicule non disponible' }}</strong>
                      <span>{{ entry.customer?.display_name ?? 'Client non disponible' }}</span>
                      <small>{{ formatCapHaitienDateTime(entry.pickup_at) }} → {{ formatCapHaitienDateTime(entry.due_at) }}</small>
                      <span class="status-chip" :class="`reservation-${entry.state}`">{{ reservationStateLabels[entry.state] }}</span>
                    </button>
                  </div>
                  <p v-else class="field-help">Aucune réservation à afficher.</p>
                </section>

                <section class="reservation-action-panel" aria-live="polite">
                  <p v-if="reservationDetailsBusy" class="field-help">Chargement de la réservation…</p>
                  <p v-else-if="!selectedReservation" class="field-help">Sélectionnez une réservation pour voir les actions disponibles.</p>
                  <template v-else>
                    <div class="reservation-action-heading">
                      <div>
                        <p class="eyebrow">Réservation {{ selectedReservation.number }}</p>
                        <h4>{{ selectedReservation.vehicle?.code ?? 'Véhicule non disponible' }}</h4>
                        <p>{{ selectedReservation.customer?.display_name }}</p>
                      </div>
                      <span class="status-chip" :class="`reservation-${selectedReservation.state}`">{{ reservationStateLabels[selectedReservation.state] }}</span>
                    </div>

                    <dl class="reservation-facts">
                      <div>
                        <dt>Départ prévu</dt>
                        <dd>{{ formatCapHaitienDateTime(selectedReservation.pickup_at) }}</dd>
                      </div>
                      <div>
                        <dt>Retour prévu</dt>
                        <dd>{{ formatCapHaitienDateTime(selectedReservation.due_at) }}</dd>
                      </div>
                      <div>
                        <dt>Adresse</dt>
                        <dd>{{ selectedReservation.site?.name ?? 'Non disponible' }}</dd>
                      </div>
                    </dl>

                    <p v-if="reservationManagementMessage" class="rental-message" :class="{ error: reservationManagementError }" role="status">{{ reservationManagementMessage }}</p>

                    <form v-if="selectedReservation.state === 'reserved' && hasPermission('rental.reservations.manage')" class="reservation-action-form" @submit.prevent="saveReservationSchedule">
                      <h5>Modifier la réservation</h5>
                      <div class="two-columns">
                        <label>
                          Départ prévu
                          <input v-model="reservationManagementForm.pickup_at" type="datetime-local" required :disabled="reservationDetailsBusy" />
                        </label>
                        <label>
                          Retour prévu
                          <input v-model="reservationManagementForm.due_at" type="datetime-local" required :disabled="reservationDetailsBusy" />
                        </label>
                      </div>
                      <label>
                        Véhicule
                        <select v-model="reservationManagementForm.vehicle_id" required :disabled="reservationDetailsBusy">
                          <option v-if="selectedReservation.vehicle" :value="selectedReservation.vehicle.id">{{ selectedReservation.vehicle.code }} · {{ vehicleDisplayName(selectedReservation.vehicle) }}</option>
                          <option v-for="vehicle in reservationManagementVehicles" :key="vehicle.id" :value="vehicle.id">
                            {{ vehicle.code }} · {{ vehicleDisplayName(vehicle) }} · {{ vehicleStatusLabels[vehicle.operational_status] }}
                          </option>
                        </select>
                      </label>
                      <p class="field-help">Le système vérifie le véhicule et la période au moment de l’enregistrement.</p>
                      <div class="reservation-action-buttons">
                        <button class="secondary-button" type="submit" :disabled="reservationDetailsBusy || apiStatus !== 'online'">Enregistrer les modifications</button>
                        <button class="primary-button" type="button" :disabled="reservationDetailsBusy || apiStatus !== 'online'" @click="requestReservationCheckOut">Mettre en circulation</button>
                      </div>
                      <div class="reservation-cancel-row">
                        <label>
                          Motif d’annulation
                          <select v-model="reservationManagementForm.cancellation_reason" :disabled="reservationDetailsBusy">
                            <option v-for="(label, reason) in reservationCancellationReasonLabels" :key="reason" :value="reason">{{ label }}</option>
                          </select>
                        </label>
                        <button class="danger-button" type="button" :disabled="reservationDetailsBusy || apiStatus !== 'online'" @click="requestReservationCancellation">Annuler la réservation</button>
                      </div>
                    </form>

                    <form v-else-if="selectedReservation.state === 'checked_out' && hasPermission('rental.reservations.manage')" class="reservation-action-form" @submit.prevent="extendReservation">
                      <h5>Location en circulation</h5>
                      <label>
                        Nouvelle date de retour
                        <input v-model="reservationManagementForm.extension_due_at" type="datetime-local" required :disabled="reservationDetailsBusy" />
                      </label>
                      <p class="field-help">En cas de conflit, la réservation suivante reste inchangée et aucune information client n’est affichée.</p>
                      <div class="reservation-action-buttons">
                        <button class="secondary-button" type="submit" :disabled="reservationDetailsBusy || apiStatus !== 'online'">Prolonger la location</button>
                        <button class="primary-button" type="button" :disabled="reservationDetailsBusy || apiStatus !== 'online'" @click="requestReservationReturn">Enregistrer le retour</button>
                      </div>
                      <p class="field-help">Un retour anticipé conserve le retour prévu, le tarif et les paiements existants. Aucun remboursement n’est créé automatiquement.</p>
                    </form>

                    <p v-else class="field-help">Aucune autre action n’est disponible pour cette réservation.</p>
                  </template>
                </section>
              </div>
            </section>
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
                    <select v-model="calendarForm.site_id" :disabled="calendarBusy" @change="loadCalendar">
                      <option value="">Toutes les adresses autorisées</option>
                      <option v-for="site in activeContext.sites" :key="site.id" :value="site.id">
                        {{ site.name }} · {{ site.address }}
                      </option>
                    </select>
                  </label>
                  <label>
                    Du
                    <input v-model="calendarForm.from" type="date" required :disabled="calendarBusy" @change="loadCalendar" />
                  </label>
                  <label>
                    Au
                    <input v-model="calendarForm.to" type="date" required :disabled="calendarBusy" @change="loadCalendar" />
                  </label>
                  <button class="primary-button planning-submit" type="submit" :disabled="calendarBusy || apiStatus !== 'online'">
                    {{ calendarBusy ? 'Actualisation…' : 'Actualiser' }}
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
                <p v-else class="field-help">Aucun véhicule actif n’est disponible pour les critères sélectionnés.</p>
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
              <section v-if="canManageVehicles" class="vehicle-reference-card" aria-labelledby="vehicle-reference-title">
                <div class="section-intro">
                  <p class="eyebrow">Références de flotte</p>
                  <h3 id="vehicle-reference-title">Véhicules identifiés dans les publications</h3>
                  <p>Adresse de la flotte : Pont Parois, Route Nationale 6. Ces fiches préremplissent uniquement les informations visibles. Elles ne créent pas de véhicule.</p>
                </div>

                <div class="vehicle-reference-grid">
                  <article v-for="candidate in clienteleFleetCatalog" :key="candidate.registrationNumber" class="vehicle-reference-item">
                    <img
                      v-if="candidate.referencePhoto"
                      class="vehicle-reference-image"
                      :src="candidate.referencePhoto.url"
                      :alt="candidate.referencePhoto.alt"
                    />
                    <div class="vehicle-reference-content">
                      <span class="vehicle-code">Plaque · {{ registrationStatusLabels[candidate.registrationStatus] }}</span>
                      <h4>{{ candidate.registrationNumber }}</h4>
                      <p>{{ candidate.make }} {{ candidate.model }} · {{ categoryLabels[candidate.category] }}</p>
                      <p v-if="candidate.requiresReview" class="vehicle-reference-warning">{{ candidate.reviewMessage }}</p>
                      <p v-else class="vehicle-reference-note">Plaque et modèle relevés dans la publication.</p>
                      <a class="vehicle-reference-source" :href="candidate.sourceUrl" target="_blank" rel="noreferrer noopener">Voir la publication source</a>
                    </div>
                    <button class="secondary-button vehicle-reference-action" type="button" :disabled="vehicleBusy" @click="prefillVehicleFromCatalog(candidate)">
                      Préremplir la fiche
                    </button>
                  </article>
                </div>
              </section>

              <section class="vehicle-list-card" aria-labelledby="vehicle-list-title">
                <div class="section-intro">
                  <p class="eyebrow">Flotte</p>
                  <h3 id="vehicle-list-title">Véhicules par adresse</h3>
                  <p>La liste est limitée aux adresses autorisées pour la société active.</p>
                </div>

                <form class="vehicle-filter-form" @submit.prevent="loadVehicles">
                  <label>
                    Adresse
                    <select v-model="vehicleFilters.site_id" :disabled="vehicleBusy" @change="loadVehicles">
                      <option value="">Toutes les adresses autorisées</option>
                      <option v-for="site in activeContext.sites" :key="site.id" :value="site.id">
                        {{ site.name }} · {{ site.address }}
                      </option>
                    </select>
                  </label>
                  <label>
                    État opérationnel
                    <select v-model="vehicleFilters.operational_status" :disabled="vehicleBusy" @change="loadVehicles">
                      <option value="">Tous les états</option>
                      <option v-for="(label, status) in vehicleStatusLabels" :key="status" :value="status">{{ label }}</option>
                    </select>
                  </label>
                  <button class="refresh-button vehicle-filter-submit" type="submit" :disabled="vehicleBusy || apiStatus !== 'online'">
                    {{ vehicleBusy ? 'Actualisation…' : 'Actualiser' }}
                  </button>
                </form>
                <p v-if="vehicleMessage" class="rental-message" :class="{ error: vehicleError }" role="status">
                  {{ vehicleMessage }}
                </p>

                <div v-if="managedVehicles.length" class="managed-vehicle-grid">
                  <article v-for="vehicle in managedVehicles" :key="vehicle.id" class="managed-vehicle-card" :class="{ selected: selectedVehicle?.id === vehicle.id }">
                    <img
                      v-if="vehicle.reference_photo"
                      class="managed-vehicle-photo"
                      :src="vehicle.reference_photo.url"
                      :alt="`${vehicle.reference_photo.label} — ${vehicle.registration_number ?? vehicle.code}`"
                    />
                    <div>
                      <span class="vehicle-code">Plaque · {{ registrationStatusLabels[vehicle.registration_status ?? 'normal'] }}</span>
                      <h4>{{ vehicle.registration_number ?? vehicle.code }}</h4>
                      <p>{{ vehicleDisplayName(vehicle) }}</p>
                      <p>{{ vehicle.site?.name ?? 'Adresse non disponible' }} · {{ categoryLabels[vehicle.category] }}</p>
                      <p v-if="vehicle.reference_photo" class="vehicle-reference-caption">{{ vehicle.reference_photo.label }}</p>
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
                    <dl class="vehicle-document-statuses">
                      <div>
                        <dt>Assurance OAVCT</dt>
                        <dd :class="`document-status-${vehicleDocumentStatusFor(vehicle, 'oavct_insurance')}`">{{ vehicleDocumentStatusLabels[vehicleDocumentStatusFor(vehicle, 'oavct_insurance')] }}</dd>
                      </div>
                      <div>
                        <dt>Vitres teintées</dt>
                        <dd :class="`document-status-${vehicleDocumentStatusFor(vehicle, 'tint_permit')}`">{{ vehicleDocumentStatusLabels[vehicleDocumentStatusFor(vehicle, 'tint_permit')] }}</dd>
                      </div>
                    </dl>
                    <button v-if="canManageVehicles" class="secondary-button vehicle-documents-button" type="button" :disabled="vehicleBusy || vehicleDocumentsBusy" @click="selectVehicle(vehicle)">
                      {{ selectedVehicle?.id === vehicle.id ? 'Papiers sélectionnés' : 'Gérer les papiers' }}
                    </button>
                  </article>
                </div>
                <p v-else class="field-help">Aucun véhicule ne correspond aux critères sélectionnés.</p>
              </section>

              <section v-if="canManageVehicles" ref="vehicleCreateSection" class="vehicle-create-card" aria-labelledby="vehicle-create-title">
                <div class="section-intro">
                  <p class="eyebrow">Véhicule</p>
                  <h3 id="vehicle-create-title">Ajouter un véhicule</h3>
                  <p>La plaque actuelle est l’identifiant du véhicule. Pour la flotte actuelle, utilisez Pont Parois, Route Nationale 6, puis renseignez le kilométrage relevé.</p>
                </div>

                <p v-if="fleetCatalogMessage" class="rental-message" role="status">{{ fleetCatalogMessage }}</p>

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
                      Plaque d’immatriculation en cours
                      <input v-model.trim="vehicleForm.registration_number" type="text" maxlength="32" autocapitalize="characters" required :disabled="vehicleBusy" />
                    </label>
                    <label>
                      Type de plaque
                      <select v-model="vehicleForm.registration_status" :disabled="vehicleBusy">
                        <option v-for="(label, status) in registrationStatusLabels" :key="status" :value="status">{{ label }}</option>
                      </select>
                    </label>
                  </div>
                  <p class="field-help">Utilisez la plaque affichée sur le véhicule. Aucun code interne distinct n’est demandé.</p>
                  <label>
                    Catégorie
                    <select v-model="vehicleForm.category" :disabled="vehicleBusy">
                      <option v-for="(label, category) in categoryLabels" :key="category" :value="category">{{ label }}</option>
                    </select>
                  </label>
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
                  <label>
                    VIN (facultatif)
                    <input v-model.trim="vehicleForm.vin" type="text" maxlength="64" autocapitalize="characters" :disabled="vehicleBusy" />
                  </label>
                  <button class="primary-button create-vehicle" type="submit" :disabled="vehicleBusy || apiStatus !== 'online'">
                    {{ vehicleBusy ? 'Enregistrement…' : 'Enregistrer le véhicule' }}
                  </button>
                </form>
              </section>

              <section v-if="selectedVehicle && canManageVehicles" class="vehicle-documents-card" aria-labelledby="vehicle-documents-title">
                <div class="section-intro">
                  <p class="eyebrow">Papiers du véhicule</p>
                  <h3 id="vehicle-documents-title">{{ selectedVehicle.registration_number ?? selectedVehicle.code }}</h3>
                  <p>Enregistrez la plaque en cours et les dates utiles. Les références restent limitées à cette société.</p>
                </div>

                <p v-if="vehicleDocumentsMessage" class="rental-message" :class="{ error: vehicleDocumentsError }" :role="vehicleDocumentsError ? 'alert' : 'status'">
                  {{ vehicleDocumentsMessage }}
                </p>

                <div class="vehicle-documents-workspace">
                  <form class="vehicle-documents-form" @submit.prevent="updateVehicleRegistration">
                    <h4>Plaque en cours</h4>
                    <div class="two-columns">
                      <label>
                        Plaque d’immatriculation
                        <input v-model.trim="vehicleRegistrationForm.registration_number" type="text" maxlength="32" autocapitalize="characters" required :disabled="vehicleDocumentsBusy" />
                      </label>
                      <label>
                        Type de plaque
                        <select v-model="vehicleRegistrationForm.registration_status" :disabled="vehicleDocumentsBusy">
                          <option v-for="(label, status) in registrationStatusLabels" :key="status" :value="status">{{ label }}</option>
                        </select>
                      </label>
                    </div>
                    <p class="field-help">Lors du remplacement d’une plaque « Démonstration », l’ancienne plaque est conservée dans l’historique.</p>
                    <button class="secondary-button" type="submit" :disabled="vehicleDocumentsBusy || apiStatus !== 'online'">
                      {{ vehicleDocumentsBusy ? 'Enregistrement…' : 'Enregistrer la plaque' }}
                    </button>
                  </form>

                  <form class="vehicle-documents-form" @submit.prevent="saveVehicleDocuments">
                    <h4>Documents et dates</h4>
                    <p class="field-help">La flotte est traitée comme équipée de vitres teintées. Renseignez le permis et son expiration.</p>
                    <div class="two-columns">
                      <label>
                        Référence d’immatriculation
                        <input v-model.trim="vehicleDocumentsForm.registration_document_number" type="text" maxlength="100" :disabled="vehicleDocumentsBusy" />
                      </label>
                      <label>
                        Date de délivrance
                        <input v-model="vehicleDocumentsForm.registration_issued_at" type="date" :disabled="vehicleDocumentsBusy" />
                      </label>
                    </div>
                    <div class="two-columns">
                      <label>
                        Référence d’assurance OAVCT
                        <input v-model.trim="vehicleDocumentsForm.oavct_document_number" type="text" maxlength="100" :disabled="vehicleDocumentsBusy" />
                      </label>
                      <label>
                        Expiration de l’assurance OAVCT
                        <input v-model="vehicleDocumentsForm.oavct_expires_at" type="date" :disabled="vehicleDocumentsBusy" />
                      </label>
                    </div>
                    <div class="two-columns">
                      <label>
                        Référence du permis de vitres teintées
                        <input v-model.trim="vehicleDocumentsForm.tint_document_number" type="text" maxlength="100" :disabled="vehicleDocumentsBusy" />
                      </label>
                      <label>
                        Expiration du permis de vitres teintées
                        <input v-model="vehicleDocumentsForm.tint_expires_at" type="date" :disabled="vehicleDocumentsBusy" />
                      </label>
                    </div>
                    <button class="primary-button" type="submit" :disabled="vehicleDocumentsBusy || apiStatus !== 'online'">
                      {{ vehicleDocumentsBusy ? 'Enregistrement…' : 'Enregistrer les documents' }}
                    </button>
                  </form>
                </div>
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

    <div v-if="confirmationRequest" class="confirmation-backdrop" role="presentation">
      <section class="confirmation-dialog" role="dialog" aria-modal="true" aria-labelledby="confirmation-title" aria-describedby="confirmation-message">
        <h2 id="confirmation-title">{{ confirmationRequest.title }}</h2>
        <p id="confirmation-message">{{ confirmationRequest.message }}</p>
        <div class="confirmation-actions">
          <button class="secondary-button" type="button" :disabled="confirmationBusy" @click="cancelConfirmation">Annuler</button>
          <button :class="confirmationRequest.danger ? 'danger-button' : 'primary-button'" type="button" :disabled="confirmationBusy" @click="confirmRequestedAction">
            {{ confirmationBusy ? 'Traitement…' : confirmationRequest.confirm_label }}
          </button>
        </div>
      </section>
    </div>

    <div v-if="deletionConfirmationOpen && deletionConfirmationUser" class="confirmation-backdrop" role="presentation">
      <section class="confirmation-dialog" role="dialog" aria-modal="true" aria-labelledby="deletion-confirmation-title" aria-describedby="deletion-confirmation-message">
        <h2 id="deletion-confirmation-title">Confirmer la suppression définitive</h2>
        <p id="deletion-confirmation-message">Saisissez le courriel de l’utilisateur pour confirmer la suppression de ce compte.</p>
        <label for="deletion-confirmation-email" class="dialog-field">
          Courriel à confirmer
          <input id="deletion-confirmation-email" v-model.trim="deletionConfirmationEmail" type="email" inputmode="email" autocomplete="off" :placeholder="deletionConfirmationUser.email" :disabled="configurationBusy" @keyup.enter="executeCompanyUserDeletion" />
        </label>
        <span v-if="companyUserManagementErrors.confirmation_email" class="field-error" role="alert">{{ companyUserManagementErrors.confirmation_email }}</span>
        <div class="confirmation-actions">
          <button class="secondary-button" type="button" :disabled="configurationBusy" @click="closeDeletionConfirmation">Annuler</button>
          <button class="danger-button" type="button" :disabled="configurationBusy || deletionConfirmationEmail.trim().toLowerCase() !== deletionConfirmationUser.email.toLowerCase()" @click="executeCompanyUserDeletion">
            {{ configurationBusy ? 'Suppression…' : 'Supprimer définitivement' }}
          </button>
        </div>
      </section>
    </div>

    <footer class="application-footer">
      <span>Clientèle Group ERP · {{ bootstrap?.application.version ?? '0.2.0-alpha.12' }}</span>
      <span>HTG · USD · Cap-Haïtien, Haïti</span>
    </footer>
  </main>
</template>
