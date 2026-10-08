/*
 * Croquis des dommages : silhouette de véhicule vue de dessus, avant en
 * haut. Les mêmes tracés servent à l'écran (SVG) et dans les PDF, et les
 * marques sont enregistrées en coordonnées relatives (0 à 1).
 */

export type DamageKind = 'scratch' | 'dent' | 'chip' | 'broken' | 'other'

export interface DamageMark {
  x: number
  y: number
  kind: DamageKind
  note: string | null
}

export const SKETCH_WIDTH = 200
export const SKETCH_HEIGHT = 360

export const damageKindLabels: Record<DamageKind, string> = {
  scratch: 'Rayure',
  dent: 'Bosse',
  chip: 'Éclat',
  broken: 'Bris',
  other: 'Autre',
}

/** Contour de la carrosserie, puis vitres et roues. */
export const SKETCH_BODY = 'M60 18 Q100 6 140 18 Q168 26 170 70 L172 290 Q170 336 140 344 Q100 352 60 344 Q30 336 28 290 L30 70 Q32 26 60 18 Z'

export const SKETCH_DETAILS = [
  // Pare-brise
  'M52 96 Q100 80 148 96 L140 136 Q100 128 60 136 Z',
  // Toit
  'M60 140 L140 140 L140 248 L60 248 Z',
  // Lunette arrière
  'M60 252 Q100 260 140 252 L146 290 Q100 302 54 290 Z',
  // Capot
  'M58 30 Q100 22 142 30',
  // Rétroviseurs
  'M28 104 L16 100 L16 116 L28 116 Z',
  'M172 104 L184 100 L184 116 L172 116 Z',
]

export const SKETCH_WHEELS = [
  'M18 52 L30 52 L30 98 L18 98 Z',
  'M170 52 L182 52 L182 98 L170 98 Z',
  'M18 254 L30 254 L30 300 L18 300 Z',
  'M170 254 L182 254 L182 300 L170 300 Z',
]

export function clampMark(value: number): number {
  return Math.min(1, Math.max(0, Math.round(value * 10000) / 10000))
}
