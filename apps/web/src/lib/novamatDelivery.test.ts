import { describe, expect, it } from 'vitest'
import { matchModel, parseNovamatDelivery, productInfo } from './novamatDelivery'

// Extrait du bon Novamat Motors (texte tel que lu dans le PDF, colonnes mêlées).
const SAMPLE = `NOVAMAT MOTORS S.A 12 Bd du 15 Octobre, Port-au-Prince Haiti
Produit Commandé Lot/Serial Number Engine No Livré
[HJ-125A-D-BL] HAOJIN MOTORCYCLE HJ- 1.0 Unité(s) LZL12P1A2THG38820 HJ157FMI260738820 1,00
125A-D (bleu) Unité(s)
[HJ-125A-D-BL] HAOJIN MOTORCYCLE HJ- 1.0 Unité(s) LZL12P1A5THG38830 HJ157FMI260738830 1,00
125A-D (bleu) Unité(s)
[HJ-125A-D-BL] HAOJIN MOTORCYCLE HJ-125A-D (bleu) 1.0 Unité(s) LZL12P1AXTHG38807 HJ157FMI260738807 1,00 Unité(s)
[HJ-110-5-RD] HAOJIN MOTORCYCLE HJ-110-5 1.0 Unité(s) LZL11P1A1THG40001 HJ152FMH260740001 1,00 Unité(s)
[HJ-125A-D-BL] HAOJIN MOTORCYCLE HJ-125A-D (bleu) 1.0 Unité(s) LZL12P1A5THG38830 HJ157FMI260738830 1,00 Unité(s)
Page : 2 / 8`

describe('bon de livraison Novamat', () => {
  it('lit chaque moto : modèle, couleur, châssis et moteur', () => {
    const { lines, duplicates } = parseNovamatDelivery(SAMPLE)
    expect(lines).toHaveLength(4)
    expect(lines[0]).toEqual({ productCode: 'HJ-125A-D-BL', model: 'HJ-125A-D', color: 'Bleu', frame: 'LZL12P1A2THG38820', engine: 'HJ157FMI260738820' })
    expect(lines[2].frame).toBe('LZL12P1AXTHG38807')
    expect(lines[3]).toMatchObject({ model: 'HJ-110-5', color: 'Rouge', engine: 'HJ152FMH260740001' })
    expect(duplicates).toEqual(['LZL12P1A5THG38830'])
  })

  it('lit des lignes collées sans code produit', () => {
    const { lines } = parseNovamatDelivery('lzl12p1a9thg38846\thj157fmi260738846\nLZL12P1A3THG38843 HJ157FMI260738843')
    expect(lines.map((line) => line.frame)).toEqual(['LZL12P1A9THG38846', 'LZL12P1A3THG38843'])
    expect(lines[0].model).toBeNull()
  })

  it('sépare le modèle de la couleur et retrouve le modèle existant', () => {
    expect(productInfo('HJ-125A-D-BL', ' HAOJIN MOTORCYCLE HJ-125A-D (BLEU)')).toEqual({ model: 'HJ-125A-D', color: 'Bleu' })
    expect(productInfo('HJ-150-28')).toEqual({ model: 'HJ-150-28', color: null })
    const models = [{ id: 'a', model: 'HJ125A-D' }, { id: 'b', model: 'HJ-110-5' }]
    expect(matchModel('HJ-125A-D', models)?.id).toBe('a')
    expect(matchModel('HJ-999', models)).toBeNull()
  })
})
