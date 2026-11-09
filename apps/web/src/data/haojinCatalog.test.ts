import { describe, expect, it } from 'vitest'
import { HAOJIN_HAITI_MODELS, missingHaojinModels, modelKey } from './haojinCatalog'
import { colorSuggestions } from './motoColors'

describe('catalogue Haojin', () => {
  it('compare les codes sans tiret ni casse', () => {
    expect(modelKey('HJ125-A')).toBe(modelKey('hj-125a'))
    expect(modelKey('HJ200GY-3')).toBe(modelKey('HJ-200GY-3'))
  })

  it('ne recrée pas un modèle déjà saisi, même écrit autrement ou sous un alias', () => {
    const missing = missingHaojinModels([
      { make: 'Haojin', model: 'HJ-110-5' },
      { make: 'Haojin', model: 'HJ125A-D' },
      { make: 'Haojin', model: 'HJ-200-CY-3' },
      { make: 'Autre', model: 'HJ-150-28' },
    ]).map((ref) => ref.model)
    expect(missing).not.toContain('HJ-110-5')
    expect(missing).not.toContain('HJ-125A-D')
    expect(missing).not.toContain('HJ-200GY-3')
    expect(missing).toContain('HJ-150-28')
    expect(missing).toContain('HJ-150ZH-6A')
  })

  it('a des codes uniques', () => {
    const keys = HAOJIN_HAITI_MODELS.flatMap((ref) => [ref.model, ...(ref.aliases ?? [])]).map(modelKey)
    expect(new Set(keys).size).toBe(keys.length)
  })
})

describe('couleurs', () => {
  it('ajoute les couleurs déjà utilisées sans doublon', () => {
    const list = colorSuggestions(['rouge sang', 'Turquoise', ''])
    expect(list.filter((color) => color.toLowerCase() === 'rouge sang')).toHaveLength(1)
    expect(list).toContain('Turquoise')
  })
})
