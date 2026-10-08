/*
 * Types partagés avec l'API Laravel. Ils reprennent exactement les charges
 * renvoyées par les contrôleurs existants. Toute évolution de l'API doit
 * être reportée ici avant d'être utilisée dans une vue.
 */

export type Currency = 'HTG' | 'USD'

export interface BootstrapResponse {
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

export interface SessionUser {
  id: string
  name: string
  email: string
  system_role: string
  two_factor_email_verified: boolean
}

export interface CompanyChoice {
  id: string
  code: string
  name: string
  role_key: string
}

export interface ContextCashRegister {
  id: string
  code: string
  name: string
  is_active: boolean
}

export interface ContextSite {
  id: string
  code: string
  name: string
  address: string
  cash_registers: ContextCashRegister[]
}

export interface CompanyContext {
  company: {
    id: string
    code: string
    name: string
    timezone: string
    timezone_label: string
    base_currency: Currency
  }
  access: {
    role_key: string
    site_scope: 'all' | 'selected'
    permissions: string[] | { allow?: string[] }
  }
  sites: ContextSite[]
}

export type RentalCategory = 'suv' | 'mid_suv' | 'pickup'
export type RentalLocation = 'site' | 'cap_haitien_airport' | 'custom'
export type KilometerPlan = 'limited' | 'unlimited'
export type VehicleOperationalStatus = 'available' | 'preparation' | 'washing' | 'garage' | 'in_circulation'
export type VehicleRegistrationStatus = 'demonstration' | 'location' | 'normal'
export type VehicleDocumentType = 'registration' | 'oavct_insurance' | 'tint_permit'
export type VehicleDocumentStatus = 'not_recorded' | 'not_applicable' | 'expired' | 'expiring_soon' | 'current'
export type ReservationState = 'draft' | 'reserved' | 'checked_out' | 'completed' | 'cancelled'
export type ReservationCancellationReason = 'customer_request' | 'vehicle_unavailable' | 'business_decision' | 'other'

export interface RentalSite {
  id: string
  code: string
  name: string
}

export interface RentalVehicle {
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
  daily_rate_usd: string | null
  minimum_security_deposit_usd: string | null
  document_statuses?: Array<{
    type: VehicleDocumentType
    status: VehicleDocumentStatus
    expires_at: string | null
  }>
}

export interface RentalVehicleDocument {
  id: string
  type: VehicleDocumentType
  document_number: string | null
  issued_at: string | null
  expires_at: string | null
  status: VehicleDocumentStatus
}

export interface ReservationCustomer {
  id: string
  display_name: string
  customer_type: 'individual' | 'institution'
}

export interface CarRentalPayment {
  id: string
  kind: 'rental' | 'security_deposit'
  method: 'cash' | 'bank_transfer'
  status: 'submitted' | 'approved' | 'rejected' | 'reversed'
  currency: Currency
  amount: string
  submitted_at: string | null
  approved_at: string | null
}

export interface CarRentalSecurityDeposit {
  id: string
  payment_id: string | null
  method: 'cash' | 'bank_transfer' | 'passport_hold'
  status: 'required' | 'held' | 'partially_applied' | 'released' | 'forfeited'
  currency: Currency | null
  amount: string | null
  held_at: string | null
  released_at: string | null
}

export interface CheckoutRequirements {
  driver_license_verified: boolean
  approved_rental_payment: boolean
  minimum_security_deposit_configured: boolean
  minimum_security_deposit_usd: string
  held_security_deposit_usd: string
  security_deposit_satisfied: boolean
}

export interface CarRentalReservation {
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
  currency: Currency
  daily_rate: string
  minimum_security_deposit_usd: string | null
  kilometer_plan: KilometerPlan
  included_km: number | null
  additional_km_rate: string | null
  driver_full_name: string | null
  driver_license_expires_at: string | null
  driver_license_verified: boolean
  vehicle: RentalVehicle | null
  customer: ReservationCustomer | null
  payments: CarRentalPayment[] | null
  security_deposits: CarRentalSecurityDeposit[] | null
  checkout_requirements: CheckoutRequirements
}

export interface CarRentalReservationListEntry {
  id: string
  number: string
  state: ReservationState
  pickup_at: string
  due_at: string
  site: RentalSite | null
  vehicle: RentalVehicle | null
  customer: ReservationCustomer | null
}

export interface Period {
  from: string
  to: string
  timezone: string
  timezone_label: string
}

export interface CarRentalReservationListResponse {
  data: CarRentalReservationListEntry[]
  period: Period
}

export interface CarRentalAvailabilityResponse {
  data: RentalVehicle[]
  period: {
    pickup_at: string
    due_at: string
    timezone: string
    timezone_label: string
  }
}

export interface CarRentalCalendarEntry {
  id: string
  number: string
  state: 'reserved' | 'checked_out'
  pickup_at: string
  due_at: string
  site: RentalSite | null
  vehicle: RentalVehicle | null
}

export interface CarRentalCalendarResponse {
  data: CarRentalCalendarEntry[]
  vehicles: RentalVehicle[]
  period: Period
}

export interface SystemCashRegister {
  id: string
  site_id: string
  code: string
  name: string
  automatic_print_enabled: boolean
  customer_display_enabled: boolean
  is_active: boolean
}

export interface SystemSite {
  id: string
  code: string
  name: string
  address: string
  is_active: boolean
  cash_registers: SystemCashRegister[]
}

export interface SystemCompany {
  id: string
  code: string
  legal_name: string
  display_name: string
  base_currency: Currency
  timezone: string
  timezone_label: string
  is_active: boolean
  sites: SystemSite[]
}

export type CarRentalUserRole = 'car_rental_administrator' | 'car_rental_agent' | 'car_rental_fleet'

export interface SystemCompanyUser {
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
