import type { RentalVehicle } from '../api/types'

/** Convertit une saisie lisible en code interne : « Cap 01 » devient « CAP-01 ». */
export function normalizeCode(value: string): string {
  return value
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .trim()
    .replace(/[^a-zA-Z0-9_-]+/g, '-')
    .replace(/[-_]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .toUpperCase()
}

/** Comparaison souple d'adresses, sans accents ni ponctuation. */
export function normalizeForSearch(value: string): string {
  return value
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, ' ')
    .trim()
}

export function vehicleName(vehicle: Pick<RentalVehicle, 'make' | 'model'> | null | undefined): string {
  if (!vehicle) return 'Véhicule non renseigné'
  return [vehicle.make, vehicle.model].filter(Boolean).join(' ') || 'Modèle non renseigné'
}

/** La plaque en cours est l'identifiant visible du véhicule. */
export function vehiclePlate(vehicle: Pick<RentalVehicle, 'registration_number' | 'code'> | null | undefined): string {
  if (!vehicle) return ''
  return vehicle.registration_number ?? vehicle.code
}

export function plural(count: number, singular: string, pluralForm = `${singular}s`): string {
  return `${count} ${count > 1 ? pluralForm : singular}`
}

export function isStrongPassword(value: string): boolean {
  return value.length >= 12
    && /[a-z]/.test(value)
    && /[A-Z]/.test(value)
    && /\d/.test(value)
    && /[^A-Za-z0-9]/.test(value)
}

export const PASSWORD_RULE = 'Au moins 12 caractères, avec majuscule, minuscule, chiffre et symbole.'

export function initials(name: string | null | undefined): string {
  if (!name) return '?'
  const parts = name.trim().split(/\s+/).filter(Boolean)
  const first = parts[0]?.[0] ?? ''
  const last = parts.length > 1 ? parts[parts.length - 1][0] : ''
  return (first + last).toUpperCase() || '?'
}
