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
  /** Propriétaire ou personne désignée pour saisir le taux du groupe. */
  can_manage_exchange_rates?: boolean
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
    /** Identité du loueur imprimée sur le contrat de location. */
    legal?: CompanyLegalIdentity
  }
  access: {
    role_key: string
    site_scope: 'all' | 'selected'
    permissions: string[] | { allow?: string[] }
  }
  sites: ContextSite[]
  exchange_rate?: ExchangeRate | null
}

export interface CompanyLegalIdentity {
  name: string
  representative: string | null
  tax_identification_number: string | null
  address: string | null
  phone_numbers: string | null
  rental_contract_terms?: string | null
  /** Frais de service en vigueur, réglés dans Configuration. */
  rental_fees?: { airport_usd: string; cleaning_usd: string }
}

export type FuelType = 'gasoline' | 'diesel'
export type Transmission = 'manual' | 'automatic'

export interface StoredFileRef {
  id: string
  url: string
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
  /** Numéro de série, renvoyé seulement aux gestionnaires de flotte. */
  vin?: string | null
  reference_photo?: {
    key: string
    url: string
    label: string
    source_url: string
  } | null
  daily_rate_usd: string | null
  minimum_security_deposit_usd: string | null
  color?: string | null
  fuel_type?: FuelType | null
  transmission?: Transmission | null
  engine_displacement_cc?: number | null
  doors?: number | null
  /** Photo réelle téléversée ; prioritaire sur la photo de référence publique. */
  photo?: StoredFileRef | null
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
  /** Renvoyés seulement aux rôles qui gèrent la réservation. */
  email?: string | null
  phone?: string | null
}

export type PaymentMethod = 'cash' | 'bank_transfer' | 'credit'

export interface CarRentalPayment {
  id: string
  kind: 'rental' | 'security_deposit'
  method: PaymentMethod
  status: 'submitted' | 'approved' | 'rejected' | 'reversed'
  currency: Currency
  amount: string
  proof_file_url?: string | null
  submitted_at: string | null
  approved_at: string | null
  /** Taux appliqué quand la devise diffère de celle de la réservation. */
  exchange_rate_htg_per_usd?: string | null
  amount_in_reservation_currency?: string | null
  /** Numéro du reçu, attribué à l'approbation d'un encaissement. */
  receipt_number?: string | null
}

export interface ExchangeRate {
  id: string
  rate_htg_per_usd: string
  brh_reference_rate: string | null
  brh_reference_date: string | null
  below_brh: boolean
  note: string | null
  effective_at: string | null
  set_by: string | null
}

export interface PaymentReceipt {
  payment_id: string
  number: string
  issued_at: string | null
  print_count: number
  company: { name: string; display_name: string; address: string | null; tax_identification_number: string | null; phone_numbers: string | null }
  site: string | null
  cash_register: string | null
  cashier: string | null
  reservation_number: string
  customer: string | null
  vehicle: string
  kind: 'rental' | 'security_deposit'
  method: PaymentMethod
  currency: Currency
  amount: string
  reservation_currency: Currency
  exchange_rate_htg_per_usd: string | null
  amount_in_reservation_currency: string | null
  bank_reference: string | null
  verification_url: string | null
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
  /** Montant retenu au règlement du dépôt. */
  applied_amount?: string | null
  settlement_note?: string | null
}

export interface CheckoutRequirements {
  /** Conditions générales du contrat saisies par le propriétaire. */
  contract_terms_configured: boolean
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
  pickup_location?: { type: RentalLocation; detail: string | null }
  dropoff_location?: { type: RentalLocation; detail: string | null }
  airport_pickup_fee_usd: string
  airport_dropoff_fee_usd: string
  airport_fees_total_usd: string
  currency: Currency
  daily_rate: string
  rate_overridden?: boolean
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
  /** Renvoyé seulement aux rôles qui gèrent la réservation. */
  driver_license?: DriverLicense | null
  additional_driver?: { name: string; license_number: string | null } | null
  checkout_inspection?: CheckoutInspection | null
  return_inspection?: CheckoutInspection | null
  additional_charges?: AdditionalCharge[]
  invoice?: RentalInvoice | null
  contract?: ReservationContract
}

export interface AdditionalCharge {
  code: 'cleaning' | 'extra_km' | 'other'
  label: string
  amount: string
}

export interface InvoicePaymentLine {
  method: PaymentMethod
  currency: Currency
  amount: string
  date: string | null
  receipt_number?: string | null
  /** Paiement converti au taux enregistré lors de l'encaissement. */
  original_currency?: Currency
  original_amount?: string
  exchange_rate_htg_per_usd?: string
}

export interface InvoiceSnapshot {
  lessor: { name: string; tax_identification_number: string | null; address: string | null; phone_numbers: string | null }
  customer: { name: string | null; email: string | null; phone: string | null }
  reservation_number: string
  vehicle: string
  pickup_at: string | null
  due_at: string | null
  returned_at: string | null
  odometer_out_km: number | null
  odometer_in_km: number | null
  fuel_out_percent: number | null
  fuel_in_percent: number | null
  currency: Currency
  lines: Array<{ label: string; amount: string }>
  payments: InvoicePaymentLine[]
  other_currency_payments: InvoicePaymentLine[]
  totals: { total: string; paid: string; credit: string; deposit_applied: string; balance_due: string; overpaid: string }
  deposit: { retained_usd: string; released_usd: string }
  timezone: string
}

export interface RentalInvoice {
  id: string
  number: string
  issued_at: string | null
  currency: Currency
  total: string
  balance_due: string
  file_url: string | null
  snapshot: InvoiceSnapshot
}

import type { DamageMark } from '../lib/damageSketch'

export interface DriverLicense {
  country: string
  subdivision: string | null
  number: string | null
  expires_at: string | null
  /** Photos visibles seulement avec la permission des documents sensibles. */
  front_url: string | null
  back_url: string | null
}

export type FuelLevel = 10 | 25 | 50 | 75 | 100

export type RentalAccessory =
  | 'spare_tire'
  | 'jack'
  | 'wheel_wrench'
  | 'warning_triangle'
  | 'first_aid_kit'
  | 'fire_extinguisher'
  | 'vehicle_documents'
  | 'floor_mats'
  | 'radio'
  | 'phone_charger'

export interface CheckoutInspection {
  inspected_at: string | null
  odometer_km: number | null
  fuel_level_percent: number | null
  accessories: RentalAccessory[]
  damage_notes: string | null
  damage_marks: DamageMark[]
  photo_urls: string[]
  company_signer_name: string | null
  customer_signed_at: string | null
  company_signed_at: string | null
  customer_signature_url: string | null
  company_signature_url: string | null
}

export interface ContractSnapshot {
  lessor: {
    name: string
    display_name: string
    representative: string | null
    tax_identification_number: string | null
    address: string | null
    phone_numbers: string | null
  }
  terms: string
  terms_sha256: string
  vehicle: {
    make: string | null
    model: string | null
    model_year: number | null
    registration_number: string
    vin: string | null
    color: string | null
    fuel_type: FuelType | null
    transmission: Transmission | null
    engine_displacement_cc: number | null
    doors: number | null
    category: RentalCategory
  }
  timezone: string
}

export interface ReservationContract {
  issued_at: string | null
  file_url: string | null
  snapshot: ContractSnapshot | null
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
  legal_representative?: string | null
  tax_identification_number?: string | null
  legal_address?: string | null
  phone_numbers?: string | null
  rental_contract_terms?: string | null
  roadside_assistance_phone?: string | null
  rental_airport_fee_usd?: string
  rental_cleaning_fee_usd?: string
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
  can_manage_exchange_rates?: boolean
  can_edit_personal_profile: boolean
  can_delete_permanently: boolean
  sites: Array<{
    id: string
    code: string
    name: string
  }>
}
