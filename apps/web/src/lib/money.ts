import type { Currency } from '../api/types'

/*
 * Montants. L'API transmet les montants en chaînes décimales afin d'éviter
 * les erreurs d'arrondi. On les convertit seulement pour l'affichage et
 * pour des totaux indicatifs : le serveur reste la source de vérité.
 */

const formatters: Record<Currency, Intl.NumberFormat> = {
  USD: new Intl.NumberFormat('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }),
  HTG: new Intl.NumberFormat('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }),
}

export function toAmount(value: string | number | null | undefined): number | null {
  if (value === null || value === undefined || value === '') return null
  const amount = typeof value === 'number' ? value : Number(value)
  return Number.isFinite(amount) ? amount : null
}

/** « 1 250,00 USD » ou « À configurer » lorsque le montant manque. */
export function formatMoney(
  value: string | number | null | undefined,
  currency: Currency,
  missing = 'À configurer',
): string {
  const amount = toAmount(value)
  if (amount === null) return missing
  // Espace insécable fine entre milliers, puis code devise ISO.
  return `${formatters[currency].format(amount)} ${currency}`
}

export function sumAmounts(values: Array<string | number | null | undefined>): number {
  return Math.round(values.reduce<number>((total, value) => total + (toAmount(value) ?? 0), 0) * 100) / 100
}

/** « 1 USD = 130,50 HTG » : jusqu'à quatre décimales, sans zéros inutiles. */
export function formatRate(value: string | number | null | undefined): string {
  const amount = toAmount(value)
  if (amount === null) return 'Taux non défini'
  const text = new Intl.NumberFormat('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 4 }).format(amount)
  return `1 USD = ${text} HTG`
}

/**
 * Centimes entiers d'une saisie décimale (« 1500 », « 1250,5 », « 1 250.50 »).
 * Retourne null si la saisie n'est pas un montant à deux décimales au plus.
 */
export function toCents(value: string | number | null | undefined): number | null {
  if (value === null || value === undefined) return null
  const text = String(value).replace(/[\s  ]/g, '').replace(',', '.')
  const match = /^(\d{1,9})(?:\.(\d{1,2}))?$/.exec(text)
  if (!match) return null
  return Number(match[1]) * 100 + Number((match[2] ?? '').padEnd(2, '0'))
}

/** Chaîne décimale canonique « 1250.50 » à partir de centimes entiers. */
export function fromCents(cents: number): string {
  const sign = cents < 0 ? '-' : ''
  const absolute = Math.abs(Math.round(cents))
  return `${sign}${Math.floor(absolute / 100)}.${String(absolute % 100).padStart(2, '0')}`
}
