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
  /** Une session de caisse est ouverte : les espèces peuvent être reçues. */
  is_open?: boolean
}

/** Activité d'une société : elle choisit les écrans et les profils proposés. */
export type CompanyActivity = 'car_rental' | 'auto_parts'

/** office : bureau ; store : magasin qui vend ; warehouse : entrepôt. */
export type SiteKind = 'office' | 'store' | 'warehouse'

export interface ContextSite {
  id: string
  code: string
  name: string
  address: string
  kind?: SiteKind
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
    activity?: CompanyActivity
    /** Identité du loueur imprimée sur le contrat de location. */
    legal?: CompanyLegalIdentity
    /** Alignement de l'impression du certificat de vente moto, en millimètres. */
    moto_certificate_offset?: { x_mm: string; y_mm: string }
    /** Frais de service Car Rental en vigueur, réglés dans Configuration. */
    rental_fees?: { airport_usd: string; cleaning_usd: string }
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
  kind?: SiteKind
  is_active: boolean
  cash_registers: SystemCashRegister[]
}

export interface SystemCompany {
  id: string
  code: string
  legal_name: string
  display_name: string
  base_currency: Currency
  activity?: CompanyActivity
  moto_certificate_offset?: { x_mm: string; y_mm: string }
  /** Profils proposés pour cette activité. */
  role_options?: Array<{ key: string; label: string }>
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
export type AutoPartsUserRole = 'auto_parts_administrator' | 'auto_parts_seller' | 'auto_parts_storekeeper'
export type UserRoleKey = CarRentalUserRole | AutoPartsUserRole

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
  /** Réservation, paiement, caisse ou action métier : suppression impossible, désactivation seulement. */
  has_records: boolean
  can_delete_permanently: boolean
  sites: Array<{
    id: string
    code: string
    name: string
  }>
}

/* Caisse commune : sessions, mouvements d'espèces et rapport journalier. */

export type CashCurrencyTotals = Record<Currency, { opening: string; in: string; out: string; expected: string }>

export type CashMovementKind = 'rental_payment' | 'deposit_payment' | 'deposit_refund' | 'parts_sale'

export interface CashMovement {
  id: string
  kind: CashMovementKind
  label: string
  direction: 'in' | 'out'
  currency: Currency
  amount: string
  occurred_at: string | null
  receipt_number: string | null
  payment_id: string | null
  /** Vente Auto Parts encaissée par ce mouvement. */
  sale_id?: string | null
  reservation_id: string | null
  reservation_number: string | null
  recorded_by: string | null
}

export interface CashSession {
  id: string
  status: 'open' | 'closed'
  site: { id: string; name: string | null }
  cash_register: { id: string; code: string | null; name: string | null }
  opened_at: string | null
  opened_by: string | null
  closed_at: string | null
  closed_by: string | null
  totals: CashCurrencyTotals
  declared: Record<Currency, string> | null
  variance: Record<Currency, string> | null
  variance_note: string | null
  review_status: 'none' | 'pending' | 'approved'
  reviewed_at: string | null
  reviewed_by: string | null
  review_note: string | null
  report_print_count: number
  pending_cash_payments: number
  can_close: boolean
  can_review: boolean
  movements?: CashMovement[]
}

export interface CashRegisterState {
  id: string
  code: string
  name: string
  site: { id: string | null; name: string | null }
  session: CashSession | null
  suggested_opening: Record<Currency, string>
}

export interface CashPermissions {
  operate: boolean
  approve: boolean
  reports: boolean
}

export interface DailyCashReport {
  date: string
  company: { name: string; display_name: string; tax_identification_number: string | null }
  site: string | null
  generated_at: string
  generated_by: string | null
  sessions: CashSession[]
  totals: Record<Currency, { opening: string; in: string; out: string; expected: string; declared: string; variance: string }>
  movements_by_kind: Array<{ kind: CashMovementKind; label: string; direction: 'in' | 'out'; currency: Currency; count: number; amount: string }>
  /** Encaissements Car Rental approuvés le jour, tous modes ; espèces hors session comprises. */
  rental_payments: Array<{ method: string; currency: Currency; count: number; amount: string; outside_session_count: number; outside_session_amount: string }>
  /** Activité de la société : les sections propres à l'activité en dépendent. */
  activity?: CompanyActivity
  /** Ventes Auto Parts du jour par liste de prix. */
  parts_sales?: Array<{ price_list: PartsPriceList; label: string; count: number; item_count: number; currency: 'HTG'; amount: string }>
  open_sessions: number
  pending_reviews: number
  reprints: number
}

/* Auto Parts et motocyclettes */

export type PartsPriceList = 'retail' | 'wholesale'
/** Mouvement saisi depuis la fiche article. */
export type PartsStockAction = 'receipt' | 'transfer' | 'adjust'
export type PartsMovementKind = 'receipt' | 'adjustment' | 'transfer_out' | 'transfer_in' | 'sale'

export interface PartsFitment {
  id?: string
  make: string
  model: string | null
  year_from: number | null
  year_to: number | null
  label?: string
}

export interface PartsStockEntry {
  site_id: string
  site_name: string
  kind: SiteKind
  quantity: number
}

export interface PartsMovement {
  id: string
  kind: PartsMovementKind
  label: string
  site: { id: string; name: string | null }
  quantity: number
  balance_after: number
  unit_cost_htg: string | null
  reference: string | null
  note: string | null
  sale_id: string | null
  recorded_by: string | null
  occurred_at: string | null
  product?: { id: string; sku: string | null; name: string | null }
}

export interface PartsProduct {
  id: string
  sku: string
  barcode: string | null
  name: string
  category: string | null
  brand: string | null
  unit: string
  retail_price_htg: string
  /** Visible seulement par les rôles qui appliquent ou gèrent le prix de gros. */
  wholesale_price_htg: string | null
  has_wholesale_price: boolean
  reorder_level: number
  notes: string | null
  is_active: boolean
  photo_file_id: string | null
  photo_url: string | null
  fitments: PartsFitment[]
  /** Absent pour un rôle qui ne consulte pas le stock. */
  stock: PartsStockEntry[] | null
  total_stock: number | null
  is_low: boolean
  movements?: PartsMovement[]
}

export interface PartsProductInput {
  sku: string
  barcode: string | null
  name: string
  category: string | null
  brand: string | null
  unit: string
  retail_price_htg: string
  wholesale_price_htg: string | null
  reorder_level: number
  notes: string | null
}

export interface PartsSaleSummary {
  id: string
  number: string
  status: string
  price_list: PartsPriceList
  price_list_label: string
  site: { id: string; name: string | null }
  cash_register: { id: string; name: string | null }
  seller: string | null
  sold_at: string | null
  item_count: number
  currency: 'HTG'
  total: string
}

export interface PartsSaleLine {
  id: string
  product_id: string
  sku: string
  name: string
  quantity: number
  unit_price: string
  line_total: string
  stock_site: { id: string; name: string | null }
}

export interface PartsSale extends PartsSaleSummary {
  customer_name: string | null
  amount_received: string
  change_given: string
  cash_session_id: string
  print_count: number
  site_address: string | null
  verification_url: string | null
  company: {
    display_name: string
    name: string
    address: string | null
    phone_numbers: string | null
    tax_identification_number: string | null
  }
  lines: PartsSaleLine[]
}

export interface PartsSalesDayResponse {
  data: PartsSaleSummary[]
  meta: { date: string; count: number; item_count: number; total: string; currency: 'HTG' }
}

/* Vente de motocyclettes */

export type MotoOrderStatus = 'submitted' | 'paid' | 'assigned' | 'delivered' | 'cancelled'
export type MotoOrderFilter = MotoOrderStatus | 'customs_pending'

export interface MotoModel {
  id: string
  make: string
  model: string
  label: string
  displacement_cc: number | null
  cylinders: number
  price: string
  price_currency: Currency
  is_active: boolean
  /** Photo générique du modèle (fichier privé). */
  photo_url?: string | null
  /** Motos du modèle disponibles en stock. */
  available?: number
}

export interface MotoUnit {
  id: string
  status: 'in_stock' | 'reserved' | 'delivered'
  status_label: string
  model: MotoModel | null
  model_year: number
  frame_number: string
  engine_number: string
  color: string
  cylinders: number
  displacement_cc: number | null
  customs_document_available: boolean
  site: { id: string; name: string | null }
  order_id: string | null
  batch_id: string
}

export interface MotoOrder {
  id: string
  number: string
  status: MotoOrderStatus
  status_label: string
  site: { id: string; name: string | null }
  model: MotoModel | null
  preferred_color: string | null
  customer_name: string
  customer_phone: string
  price: string
  price_currency: Currency
  deposit_date: string | null
  submitted_at: string | null
  /** Rang dans la liste d'attente du modèle, pour une commande payée sans moto. */
  queue_position: number | null
  unit: MotoUnit | null
  called_at: string | null
  delivered_at: string | null
  customs_document_pending: boolean
}

export interface MotoOrderDetail extends MotoOrder {
  customer_email: string | null
  customer_address: string | null
  payment_amount: string
  payment_currency: Currency
  exchange_rate_htg_per_usd: string | null
  amount_in_price_currency: string
  bank_name: string
  deposit_reference: string
  deposit_proof_url: string | null
  verified_at: string | null
  assigned_at: string | null
  assignment_note: string | null
  id_document_type: string | null
  id_document_label: string | null
  id_document_number: string | null
  checklist: Array<{ key: string; label: string; ok: boolean | null }>
  accessories: Array<{ key: string; label: string; quantity: number; delivered: boolean | null }>
  customs_document_delivered: boolean | null
  customs_document_delivered_at: string | null
  receipt_number: string | null
  receipt_print_count: number
  certificate_print_count: number
  /** Code de vérification imprimé sur le reçu (« 1A2B-3C4D »). */
  receipt_security_code?: string | null
  verification_url: string | null
  cancellation_reason: string | null
  cancelled_at: string | null
  company: { display_name: string; name: string; address: string | null; phone_numbers: string | null; tax_identification_number: string | null }
}

/** Stock de motos par modèle. */
export interface MotoStockRow {
  model: MotoModel
  available: number
  reserved: number
  waiting: number
  sites: Array<{ name: string | null; count: number }>
  colors: Array<{ color: string; count: number }>
}

/** Résultat du contrôle d'un reçu de vente présenté au comptoir. */
export interface MotoReceiptCheck {
  valid: boolean
  message?: string
  order?: {
    id: string
    number: string
    receipt_number: string
    status: MotoOrderStatus
    status_label: string
    customer_name: string
    customer_phone: string
    model: string | null
    model_year: number | null
    color: string | null
    frame_number: string | null
    engine_number: string | null
    price: string
    price_currency: Currency
    deposit_reference: string
    delivered_at: string | null
    customs_document_pending: boolean
    site: string | null
  }
}

/** Client d'Auto Parts. */
export interface PartsCustomer {
  id: string
  name: string
  phone: string | null
  email: string | null
  address: string | null
  is_active: boolean
  created_at: string | null
  moto_order_count?: number
  sale_count?: number
}

export interface PartsCustomerDetail extends PartsCustomer {
  moto_orders: Array<{ id: string; number: string; status: MotoOrderStatus; status_label: string; model: string | null; color: string | null; price: string; price_currency: Currency; receipt_number: string | null; submitted_at: string | null; delivered_at: string | null }>
  sales: Array<{ id: string; number: string; total: string; currency: Currency; item_count: number; price_list: PartsPriceList; sold_at: string | null }>
}
