import { describe, expect, it } from 'vitest'
import {
  businessParts,
  daysUntil,
  defaultMonthPeriod,
  defaultReservationSchedule,
  formatBusinessInput,
  formatDate,
  formatDateTime,
  formatTime,
  rentalDays,
  toDateTimeInput,
} from './time'

/*
 * Les dates métier sont toujours exprimées à Cap-Haïtien (America/Port-au-Prince),
 * quel que soit le fuseau du poste. Haïti applique l'heure d'été : UTC-4 en octobre,
 * UTC-5 en janvier.
 */
describe('heure de Cap-Haïtien', () => {
  it('convertit un instant UTC en heure locale de Cap-Haïtien', () => {
    expect(businessParts('2026-10-08T15:30:00Z')).toEqual({ year: 2026, month: 10, day: 8, hour: 11, minute: 30 })
    expect(businessParts('2026-01-15T15:30:00Z')).toEqual({ year: 2026, month: 1, day: 15, hour: 10, minute: 30 })
  })

  it('affiche jour, mois, année puis heure AM ou PM', () => {
    expect(formatDate('2026-10-08T15:30:00Z')).toBe('8 octobre 2026')
    expect(formatTime('2026-10-08T17:05:00Z')).toBe('1:05 PM')
    expect(formatDateTime('2026-10-08T15:30:00Z')).toBe('8 octobre 2026, 11:30 AM')
  })

  it('affiche une date civile sans décalage de fuseau', () => {
    expect(formatDate('2027-03-31')).toBe('31 mars 2027')
  })

  it('change de jour à minuit de Cap-Haïtien, pas à minuit UTC', () => {
    // 2 h 30 UTC le 9 = 22 h 30 le 8 à Cap-Haïtien.
    expect(toDateTimeInput('2026-10-09T02:30:00Z')).toBe('2026-10-08T22:30')
  })

  it('affiche une saisie datetime-local sans la reconvertir', () => {
    expect(formatBusinessInput('2026-10-08T14:30')).toBe('8 octobre 2026, 2:30 PM')
  })
})

describe('valeurs par défaut', () => {
  it('propose une prise en charge maintenant et un retour le lendemain à la même heure', () => {
    const schedule = defaultReservationSchedule(new Date('2026-10-08T15:30:00Z'))
    expect(schedule).toEqual({ pickup_at: '2026-10-08T11:30', due_at: '2026-10-09T11:30' })
  })

  it('charge le mois courant', () => {
    expect(defaultMonthPeriod(new Date('2026-10-08T15:00:00Z'))).toEqual({ from: '2026-10-01', to: '2026-10-31' })
  })

  it('inclut la première semaine du mois suivant pendant les sept derniers jours', () => {
    expect(defaultMonthPeriod(new Date('2026-10-27T15:00:00Z'))).toEqual({ from: '2026-10-01', to: '2026-11-07' })
    expect(defaultMonthPeriod(new Date('2026-12-30T15:00:00Z'))).toEqual({ from: '2026-12-01', to: '2027-01-07' })
  })
})

describe('durées', () => {
  it('compte les jours facturables au jour supérieur', () => {
    expect(rentalDays('2026-10-08T10:00', '2026-10-09T10:00')).toBe(1)
    expect(rentalDays('2026-10-08T10:00', '2026-10-09T11:00')).toBe(2)
    expect(rentalDays('2026-10-08T10:00', '2026-10-08T09:00')).toBe(0)
  })

  it('calcule les jours avant une échéance', () => {
    expect(daysUntil('2026-10-20', new Date('2026-10-08T15:00:00Z'))).toBe(12)
    expect(daysUntil('2026-10-01', new Date('2026-10-08T15:00:00Z'))).toBe(-7)
  })
})
