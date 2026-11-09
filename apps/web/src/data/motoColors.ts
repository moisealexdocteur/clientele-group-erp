/*
 * Couleurs courantes des motos Haojin, proposées à la saisie. La liste reste
 * ouverte : une autre couleur se saisit librement et s'ajoute aux suggestions
 * dès qu'une moto de cette couleur est enregistrée.
 */
export const KNOWN_MOTO_COLORS = [
  'Rouge',
  'Rouge Sang',
  'Noir',
  'Blanc',
  'Bleu',
  'Bleu nuit',
  'Gris',
  'Argent',
  'Vert',
  'Vert militaire',
  'Jaune',
  'Orange',
  'Marron',
  'Rouge et blanc',
  'Rouge et noir',
  'Bleu et blanc',
  'Noir et blanc',
]

/** Couleurs connues puis couleurs déjà utilisées, sans doublon (casse ignorée). */
export function colorSuggestions(extra: Array<string | null | undefined> = []): string[] {
  const seen = new Set<string>()
  const result: string[] = []
  for (const color of [...KNOWN_MOTO_COLORS, ...extra]) {
    const value = (color ?? '').trim()
    const key = value.toLocaleLowerCase('fr')
    if (!value || seen.has(key)) continue
    seen.add(key)
    result.push(value)
  }
  return result
}
