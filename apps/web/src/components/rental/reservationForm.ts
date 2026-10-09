import type { CarRentalReservation, Currency, KilometerPlan, RentalLocation } from '../../api/types'
import type { NewReservationPayload, ReservationUpdatePayload } from '../../api/carRental'
import { defaultReservationSchedule, rentalDays, toDateTimeInput } from '../../lib/time'

/** Valeurs du formulaire de réservation, communes à la création et à la modification. */
export interface ReservationFormValues {
  site_id: string
  vehicle_id: string
  customer_type: 'individual' | 'institution'
  /** Fiche d'un client connu choisie à la création. */
  customer_profile_id: string
  /** Coordonnées masquées du client connu, pour affichage seulement. */
  customer_hint: string
  customer_name: string
  customer_email: string
  customer_phone: string
  pickup_at: string
  due_at: string
  pickup_location_type: RentalLocation
  pickup_location_detail: string
  dropoff_location_type: RentalLocation
  dropoff_location_detail: string
  apply_airport_pickup_fee: boolean
  apply_airport_dropoff_fee: boolean
  currency: Currency
  daily_rate: string
  kilometer_plan: KilometerPlan
  included_km: string
  additional_km_rate: string
  notify_customer: boolean
}

/** Valeurs proposées pour une nouvelle réservation : maintenant, retour demain, bureau actif. */
export function newReservationValues(siteId: string): ReservationFormValues {
  const schedule = defaultReservationSchedule()
  return {
    site_id: siteId,
    vehicle_id: '',
    customer_type: 'individual',
    customer_profile_id: '',
    customer_hint: '',
    customer_name: '',
    customer_email: '',
    customer_phone: '',
    pickup_at: schedule.pickup_at,
    due_at: schedule.due_at,
    pickup_location_type: 'site',
    pickup_location_detail: '',
    dropoff_location_type: 'cap_haitien_airport',
    dropoff_location_detail: '',
    apply_airport_pickup_fee: false,
    apply_airport_dropoff_fee: false,
    currency: 'USD',
    daily_rate: '',
    kilometer_plan: 'limited',
    included_km: String(rentalDays(schedule.pickup_at, schedule.due_at) * 100),
    additional_km_rate: '',
    notify_customer: false,
  }
}

/** Valeurs actuelles d'une réservation, pour la modifier. */
export function reservationValues(reservation: CarRentalReservation): ReservationFormValues {
  const pickupType = reservation.pickup_location?.type ?? 'site'
  const dropoffType = reservation.dropoff_location?.type ?? 'site'
  return {
    site_id: reservation.site_id,
    vehicle_id: reservation.vehicle?.id ?? '',
    customer_type: reservation.customer?.customer_type ?? 'individual',
    customer_profile_id: '',
    customer_hint: '',
    customer_name: reservation.customer?.display_name ?? '',
    customer_email: reservation.customer?.email ?? '',
    customer_phone: reservation.customer?.phone ?? '',
    pickup_at: toDateTimeInput(reservation.pickup_at),
    due_at: toDateTimeInput(reservation.due_at),
    pickup_location_type: pickupType,
    pickup_location_detail: pickupType === 'custom' ? (reservation.pickup_location?.detail ?? '') : '',
    dropoff_location_type: dropoffType,
    dropoff_location_detail: dropoffType === 'custom' ? (reservation.dropoff_location?.detail ?? '') : '',
    apply_airport_pickup_fee: Number(reservation.airport_pickup_fee_usd) > 0,
    apply_airport_dropoff_fee: Number(reservation.airport_dropoff_fee_usd) > 0,
    currency: reservation.currency,
    daily_rate: reservation.daily_rate,
    kilometer_plan: reservation.kilometer_plan,
    included_km: reservation.included_km === null ? '' : String(reservation.included_km),
    additional_km_rate: reservation.additional_km_rate ?? '',
    notify_customer: Boolean(reservation.customer?.email),
  }
}

function customerOf(values: ReservationFormValues) {
  return {
    customer_type: values.customer_type,
    display_name: values.customer_name.trim(),
    email: values.customer_email.trim() || undefined,
    phone: values.customer_phone.trim() || undefined,
  }
}

function conditionsOf(values: ReservationFormValues) {
  const limited = values.kilometer_plan === 'limited'
  return {
    pickup_location_type: values.pickup_location_type,
    pickup_location_detail: values.pickup_location_type === 'custom' ? values.pickup_location_detail.trim() : undefined,
    dropoff_location_type: values.dropoff_location_type,
    dropoff_location_detail: values.dropoff_location_type === 'custom' ? values.dropoff_location_detail.trim() : undefined,
    apply_airport_pickup_fee: values.pickup_location_type === 'cap_haitien_airport' && values.apply_airport_pickup_fee,
    apply_airport_dropoff_fee: values.dropoff_location_type === 'cap_haitien_airport' && values.apply_airport_dropoff_fee,
    currency: values.currency,
    daily_rate: values.daily_rate,
    kilometer_plan: values.kilometer_plan,
    included_km: limited ? Number(values.included_km) : undefined,
    additional_km_rate: limited && values.additional_km_rate !== '' ? values.additional_km_rate : undefined,
  }
}

export function toCreatePayload(values: ReservationFormValues, category: NewReservationPayload['category']): NewReservationPayload {
  return {
    site_id: values.site_id,
    vehicle_id: values.vehicle_id || undefined,
    category,
    ...(values.customer_profile_id
      ? { customer_profile_id: values.customer_profile_id }
      : { customer: { ...customerOf(values), group_contact_sharing_consent: false } }),
    pickup_at: values.pickup_at,
    due_at: values.due_at,
    ...conditionsOf(values),
  }
}

export function toUpdatePayload(values: ReservationFormValues): ReservationUpdatePayload {
  const customer = customerOf(values)
  return {
    vehicle_id: values.vehicle_id,
    pickup_at: values.pickup_at,
    due_at: values.due_at,
    customer: { ...customer, email: customer.email ?? null, phone: customer.phone ?? null },
    ...conditionsOf(values),
    additional_km_rate: values.kilometer_plan === 'limited' && values.additional_km_rate !== '' ? values.additional_km_rate : null,
    notify_customer: values.notify_customer,
  }
}

export type { Currency, KilometerPlan, RentalLocation }
