/*
 * Modèles Haojin vendus par Clientèle Auto Parts (fournisseur Novamat Motors
 * S.A., distributeur de Guangzhou Haojin Motorcycle Co., Ltd.), confirmés par
 * le propriétaire le 9 novembre 2026 à partir du catalogue Haojin.
 *
 * Les codes suivent l'écriture du certificat Novamat (« HJ-125A-D »). La
 * cylindrée n'est indiquée que si une source la donne ; le chiffre du nom
 * n'est pas une preuve. Les cylindrées se complètent dans la fiche du modèle.
 *
 * Prix par défaut décidé par le propriétaire : 200 000 HTG pour toutes les
 * motos, modifiable ensuite modèle par modèle.
 */
export const HAOJIN_DEFAULT_PRICE = { price: '200000.00', price_currency: 'HTG' as const }

export interface HaojinReference {
  model: string
  kind: 'Motocyclette' | 'Tricycle'
  displacement_cc: number | null
  cylinders: number
  /** Autres écritures du même modèle, pour ne pas le créer deux fois. */
  aliases?: string[]
}

export const HAOJIN_HAITI_MODELS: HaojinReference[] = [
  { model: 'HJ-110-5', kind: 'Motocyclette', displacement_cc: 110, cylinders: 1 },
  { model: 'HJ-125A', kind: 'Motocyclette', displacement_cc: null, cylinders: 1 },
  { model: 'HJ-125A-D', kind: 'Motocyclette', displacement_cc: null, cylinders: 1 },
  { model: 'HJ-150-28', kind: 'Motocyclette', displacement_cc: null, cylinders: 1 },
  { model: 'HJ-200GY-3', kind: 'Motocyclette', displacement_cc: null, cylinders: 1, aliases: ['HJ-200-CY-3'] },
  { model: 'HJ-150ZH-6A', kind: 'Tricycle', displacement_cc: null, cylinders: 1 },
  { model: 'HJ-150ZH-85', kind: 'Tricycle', displacement_cc: null, cylinders: 1 },
  { model: 'HJ-150ZH-175', kind: 'Tricycle', displacement_cc: null, cylinders: 1 },
  { model: 'HJ-150ZH-200A', kind: 'Tricycle', displacement_cc: null, cylinders: 1 },
  { model: 'HJ-250ZH-10', kind: 'Tricycle', displacement_cc: null, cylinders: 1 },
]

/** Code comparable : majuscules, sans tiret ni espace (« HJ125-A » = « HJ-125A »). */
export function modelKey(code: string): string {
  return code.toUpperCase().replace(/[^A-Z0-9]/g, '')
}

/** Modèles du catalogue absents de la liste existante (écritures et alias comparés). */
export function missingHaojinModels(existing: Array<{ make: string; model: string }>): HaojinReference[] {
  const known = new Set(existing.filter((item) => item.make.trim().toLowerCase() === 'haojin').map((item) => modelKey(item.model)))
  return HAOJIN_HAITI_MODELS.filter((ref) => ![ref.model, ...(ref.aliases ?? [])].some((code) => known.has(modelKey(code))))
}
