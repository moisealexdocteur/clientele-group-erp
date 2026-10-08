import { api, apiUpload } from './client'
import type {
  CarRentalAvailabilityResponse,
  CarRentalCalendarResponse,
  CarRentalPayment,
  CarRentalReservation,
  CarRentalReservationListResponse,
  Currency,
  FuelType,
  KilometerPlan,
  PaymentMethod,
  RentalCategory,
  RentalLocation,
  RentalVehicle,
  RentalVehicleDocument,
  ReservationCancellationReason,
  ReservationState,
  Transmission,
  VehicleDocumentType,
  VehicleOperationalStatus,
  VehicleRegistrationStatus,
} from './types'

/* Appels de l'API Car Rental. La société active est ajoutée par le client HTTP. */

const base = '/api/v1/car-rental'

export function fetchAvailability(query: {
  site_id: string
  pickup_at: string
  due_at: string
  category?: RentalCategory
}) {
  return api<CarRentalAvailabilityResponse>(`${base}/availability`, { query })
}

export function fetchVehicles(query: { site_id?: string; operational_status?: string } = {}) {
  return api<{ data: RentalVehicle[] }>(`${base}/vehicles`, { query })
}

/** L'API n'expose pas encore de lecture unitaire : on filtre la liste autorisée. */
export async function fetchVehicle(vehicleId: string): Promise<RentalVehicle | null> {
  const result = await fetchVehicles()
  return result.data.find((vehicle) => vehicle.id === vehicleId) ?? null
}

/* Champs repris dans le contrat. null efface la valeur enregistrée. */
export interface VehicleContractFields {
  color?: string | null
  fuel_type?: FuelType | null
  transmission?: Transmission | null
  engine_displacement_cc?: number | null
  doors?: number | null
}

export interface NewVehiclePayload extends VehicleContractFields {
  site_id: string
  category: RentalCategory
  operational_status: VehicleOperationalStatus
  make?: string
  model?: string
  model_year?: number
  registration_number: string
  registration_status: VehicleRegistrationStatus
  reference_photo_key?: string
  vin?: string
  latest_odometer_km: number
  daily_rate_usd: number
  minimum_security_deposit_usd: number
}

export function createVehicle(payload: NewVehiclePayload) {
  return api<{ data: RentalVehicle }>(`${base}/vehicles`, { method: 'POST', body: payload })
}

export function updateVehicleDetails(vehicleId: string, payload: VehicleContractFields & {
  category: RentalCategory
  make?: string
  model?: string
  model_year?: number
  vin?: string
  latest_odometer_km: number
}) {
  return api<{ data: RentalVehicle }>(`${base}/vehicles/${vehicleId}/details`, { method: 'PATCH', body: payload })
}

export function updateVehicleActive(vehicleId: string, isActive: boolean) {
  return api<{ data: RentalVehicle }>(`${base}/vehicles/${vehicleId}/active`, { method: 'PATCH', body: { is_active: isActive } })
}

export function updateVehiclePhoto(vehicleId: string, fileId: string | null) {
  return api<{ data: RentalVehicle }>(`${base}/vehicles/${vehicleId}/photo`, { method: 'PUT', body: { file_id: fileId } })
}

export type FilePurpose =
  | 'payment_proof'
  | 'vehicle_photo'
  | 'driver_license_front'
  | 'driver_license_back'
  | 'inspection_photo'
  | 'signature'
  | 'rental_contract'

export interface UploadedFileRef {
  id: string
  purpose: FilePurpose
  mime_type: string
  size_bytes: number
  sha256: string
  url: string
}

export function uploadFile(purpose: FilePurpose, siteId: string, file: Blob, fileName = 'document') {
  const named = file instanceof File ? file : new File([file], fileName, { type: file.type })
  return apiUpload<{ data: UploadedFileRef }>(`${base}/files`, { purpose, site_id: siteId, file: named })
}

export function updateVehicleStatus(vehicleId: string, status: VehicleOperationalStatus) {
  return api<{ data: RentalVehicle }>(`${base}/vehicles/${vehicleId}/operational-status`, {
    method: 'PATCH',
    body: { operational_status: status },
  })
}

export function updateVehicleRegistration(vehicleId: string, payload: {
  registration_number: string
  registration_status: VehicleRegistrationStatus
}) {
  return api<{ data: RentalVehicle }>(`${base}/vehicles/${vehicleId}/registration`, { method: 'PATCH', body: payload })
}

export function updateVehicleCommercialTerms(vehicleId: string, payload: {
  daily_rate_usd: number
  minimum_security_deposit_usd: number
}) {
  return api<{ data: RentalVehicle }>(`${base}/vehicles/${vehicleId}/commercial-terms`, { method: 'PATCH', body: payload })
}

export function fetchVehicleDocuments(vehicleId: string) {
  return api<{ data: RentalVehicleDocument[] }>(`${base}/vehicles/${vehicleId}/documents`)
}

export function saveVehicleDocuments(vehicleId: string, documents: Array<{
  type: VehicleDocumentType
  document_number?: string
  issued_at?: string
  expires_at?: string
}>) {
  return api<{ data: RentalVehicleDocument[] }>(`${base}/vehicles/${vehicleId}/documents`, {
    method: 'PUT',
    body: { documents },
  })
}

export function fetchCalendar(query: { from: string; to: string; site_id?: string }) {
  return api<CarRentalCalendarResponse>(`${base}/calendar`, { query })
}

export function fetchReservations(query: {
  from: string
  to: string
  site_id?: string
  query?: string
  state?: ReservationState | ''
}) {
  return api<CarRentalReservationListResponse>(`${base}/reservations`, { query })
}

export function fetchReservation(reservationId: string) {
  return api<{ data: CarRentalReservation }>(`${base}/reservations/${reservationId}`)
}

export interface NewReservationPayload {
  site_id: string
  vehicle_id?: string
  category: RentalCategory
  customer: {
    customer_type: 'individual' | 'institution'
    display_name: string
    email?: string
    phone?: string
    group_contact_sharing_consent: boolean
  }
  pickup_at: string
  due_at: string
  pickup_location_type: RentalLocation
  pickup_location_detail?: string
  dropoff_location_type: RentalLocation
  dropoff_location_detail?: string
  apply_airport_pickup_fee: boolean
  apply_airport_dropoff_fee: boolean
  currency: Currency
  daily_rate: string
  kilometer_plan: KilometerPlan
  included_km?: number
  additional_km_rate?: string | null
}

export function createReservation(payload: NewReservationPayload) {
  return api<{ data: CarRentalReservation; customer_notification_sent?: boolean }>(`${base}/reservations`, {
    method: 'POST',
    body: payload,
  })
}

export interface ReservationUpdatePayload {
  vehicle_id: string
  pickup_at: string
  due_at: string
  customer?: {
    customer_type: 'individual' | 'institution'
    display_name: string
    email?: string | null
    phone?: string | null
  }
  pickup_location_type?: RentalLocation
  pickup_location_detail?: string
  dropoff_location_type?: RentalLocation
  dropoff_location_detail?: string
  apply_airport_pickup_fee?: boolean
  apply_airport_dropoff_fee?: boolean
  currency?: Currency
  daily_rate?: string
  kilometer_plan?: KilometerPlan
  included_km?: number
  additional_km_rate?: string | null
  notify_customer?: boolean
}

export function updateReservation(reservation: CarRentalReservation, payload: ReservationUpdatePayload) {
  return api<{ data: CarRentalReservation; customer_notification_sent?: boolean }>(`${base}/reservations/${reservation.id}`, {
    method: 'PATCH',
    body: { ...payload, expected_lock_version: reservation.lock_version },
  })
}

export function notifyReservation(reservationId: string) {
  return api<{ customer_notification_sent: boolean; message: string }>(`${base}/reservations/${reservationId}/notify`, {
    method: 'POST',
  })
}

export function checkOutReservation(reservation: CarRentalReservation, payload: {
  driver_full_name: string
  driver_license_number: string
  driver_license_expires_at: string
  driver_license_verified: boolean
}) {
  return api<{ data: CarRentalReservation; customer_notification_sent?: boolean }>(
    `${base}/reservations/${reservation.id}/check-out`,
    { method: 'POST', body: { ...payload, expected_lock_version: reservation.lock_version } },
  )
}

export function extendReservation(reservation: CarRentalReservation, dueAt: string) {
  return api<{ data: CarRentalReservation; customer_notification_sent?: boolean }>(
    `${base}/reservations/${reservation.id}/extend`,
    { method: 'POST', body: { due_at: dueAt, expected_lock_version: reservation.lock_version } },
  )
}

export function returnReservation(reservation: CarRentalReservation) {
  return api<{ data: CarRentalReservation; customer_notification_sent?: boolean }>(
    `${base}/reservations/${reservation.id}/return`,
    { method: 'POST', body: { expected_lock_version: reservation.lock_version } },
  )
}

export function cancelReservation(reservation: CarRentalReservation, reason: ReservationCancellationReason) {
  return api<{ data: CarRentalReservation }>(`${base}/reservations/${reservation.id}/cancel`, {
    method: 'POST',
    body: { reason_code: reason, expected_lock_version: reservation.lock_version },
  })
}

export interface NewPaymentPayload {
  payment_kind: 'rental' | 'security_deposit'
  method: PaymentMethod
  currency: Currency
  amount: string
  cash_register_id?: string
  bank_reference?: string
  proof_file_id?: string
}

export function submitPayment(reservationId: string, payload: NewPaymentPayload) {
  return api<{ data: CarRentalPayment }>(`${base}/reservations/${reservationId}/payments`, {
    method: 'POST',
    body: payload,
  })
}

export function approvePayment(reservationId: string, paymentId: string) {
  return api<{ data: CarRentalPayment }>(`${base}/reservations/${reservationId}/payments/${paymentId}/approve`, {
    method: 'POST',
  })
}
