import { describe, expect, it } from 'vitest'
import { isOutdated } from './appUpdate'

describe('mise à jour de la PWA', () => {
  it('détecte une version différente du serveur', () => {
    expect(isOutdated('0.9.0-alpha.1', '0.8.0-alpha.1')).toBe(true)
    expect(isOutdated('0.8.0-alpha.1', '0.8.0-alpha.1')).toBe(false)
    expect(isOutdated(null, '0.8.0-alpha.1')).toBe(false)
    expect(isOutdated('', '0.8.0-alpha.1')).toBe(false)
  })
})
