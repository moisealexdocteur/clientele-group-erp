/*
 * Dates et heures métier.
 *
 * Toutes les dates affichées utilisent le fuseau America/Port-au-Prince,
 * présenté comme l'heure de Cap-Haïtien, au format demandé :
 * jour, mois, année, puis heure en AM ou PM.
 */

export const BUSINESS_TIMEZONE = 'America/Port-au-Prince'
export const BUSINESS_TIMEZONE_LABEL = 'heure de Cap-Haïtien'

interface DateParts {
  year: number
  month: number
  day: number
  hour: number
  minute: number
}

const partsFormatter = new Intl.DateTimeFormat('en-US', {
  timeZone: BUSINESS_TIMEZONE,
  year: 'numeric',
  month: '2-digit',
  day: '2-digit',
  hour: '2-digit',
  minute: '2-digit',
  hourCycle: 'h23',
})

const dateFormatter = new Intl.DateTimeFormat('fr-FR', {
  timeZone: BUSINESS_TIMEZONE,
  day: 'numeric',
  month: 'long',
  year: 'numeric',
})

const shortDateFormatter = new Intl.DateTimeFormat('fr-FR', {
  timeZone: BUSINESS_TIMEZONE,
  day: 'numeric',
  month: 'short',
})

const dayHeadingFormatter = new Intl.DateTimeFormat('fr-FR', {
  timeZone: BUSINESS_TIMEZONE,
  weekday: 'long',
  day: 'numeric',
  month: 'long',
})

const timeFormatter = new Intl.DateTimeFormat('en-US', {
  timeZone: BUSINESS_TIMEZONE,
  hour: 'numeric',
  minute: '2-digit',
  hour12: true,
})

function toDate(value: string | Date): Date {
  return value instanceof Date ? value : new Date(value)
}

export function businessParts(value: string | Date = new Date()): DateParts {
  const values = Object.fromEntries(
    partsFormatter
      .formatToParts(toDate(value))
      .filter((part) => part.type !== 'literal')
      .map((part) => [part.type, Number(part.value)]),
  ) as Record<string, number>

  return {
    year: values.year,
    month: values.month,
    day: values.day,
    hour: values.hour === 24 ? 0 : values.hour,
    minute: values.minute,
  }
}

function pad(value: number): string {
  return String(value).padStart(2, '0')
}

/** « 8 octobre 2026 » */
export function formatDate(value: string | Date): string {
  // Une date seule (AAAA-MM-JJ) est une date civile : on l'affiche sans conversion de fuseau.
  if (typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value)) {
    const [year, month, day] = value.split('-').map(Number)
    return new Intl.DateTimeFormat('fr-FR', { timeZone: 'UTC', day: 'numeric', month: 'long', year: 'numeric' })
      .format(new Date(Date.UTC(year, month - 1, day)))
  }

  return dateFormatter.format(toDate(value))
}

/** « 8 oct. » */
export function formatShortDate(value: string | Date): string {
  return shortDateFormatter.format(toDate(value))
}

/** « 11:30 AM » */
export function formatTime(value: string | Date): string {
  return timeFormatter.format(toDate(value))
}

/** « 8 octobre 2026, 11:30 AM » */
export function formatDateTime(value: string | Date): string {
  return `${formatDate(value)}, ${formatTime(value)}`
}

/** « jeudi 8 octobre » */
export function formatDayHeading(value: string | Date = new Date()): string {
  const text = dayHeadingFormatter.format(toDate(value))
  return text.charAt(0).toUpperCase() + text.slice(1)
}

/** Valeur pour un champ <input type="date"> : « 2026-10-08 ». */
export function toDateInput(value: string | Date = new Date()): string {
  const parts = businessParts(value)
  return `${parts.year}-${pad(parts.month)}-${pad(parts.day)}`
}

/** Valeur pour un champ <input type="datetime-local"> en heure de Cap-Haïtien. */
export function toDateTimeInput(value: string | Date = new Date()): string {
  const parts = businessParts(value)
  return `${toDateInput(value)}T${pad(parts.hour)}:${pad(parts.minute)}`
}

/** Prise en charge maintenant, retour le lendemain à la même heure. */
export function defaultReservationSchedule(now: Date = new Date()): { pickup_at: string; due_at: string } {
  const due = new Date(now.getTime() + 24 * 60 * 60 * 1000)
  return {
    pickup_at: toDateTimeInput(now),
    due_at: toDateTimeInput(due),
  }
}

/**
 * Mois courant. Pendant les sept derniers jours du mois,
 * la période inclut aussi la première semaine du mois suivant.
 */
export function defaultMonthPeriod(now: Date = new Date()): { from: string; to: string } {
  const today = businessParts(now)
  const lastDay = new Date(Date.UTC(today.year, today.month, 0)).getUTCDate()
  const from = `${today.year}-${pad(today.month)}-01`

  if (today.day < lastDay - 6) {
    return { from, to: `${today.year}-${pad(today.month)}-${pad(lastDay)}` }
  }

  const next = new Date(Date.UTC(today.year, today.month, 1))
  return { from, to: `${next.getUTCFullYear()}-${pad(next.getUTCMonth() + 1)}-07` }
}

/** Vrai si l'instant tombe le jour civil indiqué à Cap-Haïtien. */
export function isOnBusinessDay(value: string | Date, dateInput: string): boolean {
  return toDateInput(value) === dateInput
}

/** Nombre de jours facturables entre deux instants, arrondi au jour supérieur. */
export function rentalDays(pickupAt: string, dueAt: string): number {
  const milliseconds = new Date(dueAt).getTime() - new Date(pickupAt).getTime()
  if (!Number.isFinite(milliseconds) || milliseconds <= 0) return 0
  return Math.max(1, Math.ceil(milliseconds / (24 * 60 * 60 * 1000)))
}

/** Nombre de jours avant une date civile, négatif si elle est passée. */
export function daysUntil(dateInput: string, now: Date = new Date()): number {
  const [year, month, day] = dateInput.split('-').map(Number)
  const today = businessParts(now)
  const target = Date.UTC(year, month - 1, day)
  const current = Date.UTC(today.year, today.month - 1, today.day)
  return Math.round((target - current) / (24 * 60 * 60 * 1000))
}

/**
 * Affiche une valeur saisie dans un champ datetime-local (« 2026-10-08T14:30 »),
 * déjà exprimée en heure de Cap-Haïtien, sans la reconvertir depuis le fuseau du poste.
 */
export function formatBusinessInput(value: string): string {
  const match = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/.exec(value)
  if (!match) return value
  const [, year, month, day, hour, minute] = match.map(Number) as unknown as number[]
  const date = new Date(Date.UTC(year, month - 1, day, hour, minute))
  const datePart = new Intl.DateTimeFormat('fr-FR', { timeZone: 'UTC', day: 'numeric', month: 'long', year: 'numeric' }).format(date)
  const timePart = new Intl.DateTimeFormat('en-US', { timeZone: 'UTC', hour: 'numeric', minute: '2-digit', hour12: true }).format(date)
  return `${datePart}, ${timePart}`
}
