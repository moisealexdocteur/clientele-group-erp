import { describe, expect, it } from 'vitest'
import { formatMoney, sumAmounts, toAmount } from './money'
import { initials, isStrongPassword, normalizeCode, vehicleName, vehiclePlate } from './text'

describe('montants', () => {
  it('formate en français avec le code devise', () => {
    // Intl utilise une espace fine insécable comme séparateur de milliers.
    expect(formatMoney('1250', 'USD').replace(/\s/g, ' ')).toBe('1 250,00 USD')
    expect(formatMoney(15000.5, 'HTG').replace(/\s/g, ' ')).toBe('15 000,50 HTG')
  })

  it('signale un montant non configuré', () => {
    expect(formatMoney(null, 'USD')).toBe('À configurer')
    expect(toAmount('abc')).toBeNull()
  })

  it('additionne des montants décimaux sans erreur d’arrondi', () => {
    expect(sumAmounts(['0.10', '0.20', null])).toBe(0.3)
  })
})

describe('textes', () => {
  it('normalise un code interne', () => {
    expect(normalizeCode('  Clientèle rent a car ')).toBe('CLIENTELE-RENT-A-CAR')
    expect(normalizeCode('cap 01')).toBe('CAP-01')
  })

  it('applique la règle de mot de passe', () => {
    expect(isStrongPassword('Clientele2026!')).toBe(true)
    expect(isStrongPassword('clientele2026!')).toBe(false)
    expect(isStrongPassword('Court1!')).toBe(false)
  })

  it('utilise la plaque comme identifiant visible', () => {
    expect(vehiclePlate({ registration_number: 'LO-01727', code: 'X' })).toBe('LO-01727')
    expect(vehicleName({ make: 'Suzuki', model: 'Jimny' })).toBe('Suzuki Jimny')
    expect(vehicleName(null)).toBe('Véhicule non renseigné')
  })

  it('calcule les initiales', () => {
    expect(initials('Moïse Alex')).toBe('MA')
    expect(initials('')).toBe('?')
  })
})
