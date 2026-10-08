import type {
  CarRentalUserRole,
  FuelType,
  RentalCategory,
  RentalLocation,
  ReservationCancellationReason,
  ReservationState,
  Transmission,
  VehicleDocumentStatus,
  VehicleDocumentType,
  VehicleOperationalStatus,
  VehicleRegistrationStatus,
} from '../api/types'

export const categoryLabels: Record<RentalCategory, string> = {
  suv: 'SUV',
  mid_suv: 'Mid SUV',
  pickup: 'Pick-up',
}

export const vehicleStatusLabels: Record<VehicleOperationalStatus, string> = {
  available: 'Disponible',
  preparation: 'Préparation',
  washing: 'Lavage',
  garage: 'Garage',
  in_circulation: 'En circulation',
}

export const registrationStatusLabels: Record<VehicleRegistrationStatus, string> = {
  demonstration: 'Démonstration',
  location: 'Location',
  normal: 'Normale',
}

export const vehicleDocumentTypeLabels: Record<VehicleDocumentType, string> = {
  registration: 'Immatriculation',
  oavct_insurance: 'Assurance OAVCT',
  tint_permit: 'Permis de vitres teintées',
}

export const vehicleDocumentStatusLabels: Record<VehicleDocumentStatus, string> = {
  not_recorded: 'Non renseigné',
  not_applicable: 'Sans expiration',
  expired: 'Expiré',
  expiring_soon: 'Expire bientôt',
  current: 'À jour',
}

export const reservationStateLabels: Record<ReservationState, string> = {
  draft: 'Brouillon',
  reserved: 'Réservée',
  checked_out: 'En circulation',
  completed: 'Terminée',
  cancelled: 'Annulée',
}

export const cancellationReasonLabels: Record<ReservationCancellationReason, string> = {
  customer_request: 'Demande du client',
  vehicle_unavailable: 'Véhicule indisponible',
  business_decision: 'Décision interne',
  other: 'Autre motif',
}

export const locationLabels: Record<RentalLocation, string> = {
  site: 'Bureau sélectionné',
  cap_haitien_airport: 'Aéroport International du Cap-Haïtien',
  custom: 'Autre lieu précisé',
}

export const carRentalUserRoleLabels: Record<CarRentalUserRole, string> = {
  car_rental_administrator: 'Administrateur Car Rental',
  car_rental_agent: 'Agent de location',
  car_rental_fleet: 'Gestionnaire de flotte',
}

export function roleLabel(roleKey: string): string {
  if (roleKey === 'owner') return 'Propriétaire du système'
  return carRentalUserRoleLabels[roleKey as CarRentalUserRole] ?? roleKey
}

/** Tonalité visuelle d'un état, utilisée par les pastilles. */
export type Tone = 'neutral' | 'info' | 'success' | 'warning' | 'danger'

export const reservationStateTones: Record<ReservationState, Tone> = {
  draft: 'neutral',
  reserved: 'info',
  checked_out: 'warning',
  completed: 'success',
  cancelled: 'neutral',
}

export const vehicleStatusTones: Record<VehicleOperationalStatus, Tone> = {
  available: 'success',
  preparation: 'info',
  washing: 'info',
  garage: 'danger',
  in_circulation: 'warning',
}

export const documentStatusTones: Record<VehicleDocumentStatus, Tone> = {
  not_recorded: 'neutral',
  not_applicable: 'neutral',
  expired: 'danger',
  expiring_soon: 'warning',
  current: 'success',
}

export const fuelTypeLabels: Record<FuelType, string> = {
  gasoline: 'Essence',
  diesel: 'Diesel',
}

export const transmissionLabels: Record<Transmission, string> = {
  manual: 'Manuelle',
  automatic: 'Automatique',
}
