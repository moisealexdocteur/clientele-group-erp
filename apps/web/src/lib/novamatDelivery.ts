import { modelKey } from '../data/haojinCatalog'

/*
 * Lecture du bon de livraison ou de la facture Novamat Motors (texte extrait
 * du PDF, ou lignes collées). Chaque moto est repérée par son numéro de
 * châssis (17 caractères, sans I, O ni Q) suivi de son numéro de moteur ; le
 * modèle et la couleur viennent du code produit qui précède
 * (« [HJ-125A-D-BL] HAOJIN MOTORCYCLE HJ-125A-D (bleu) »).
 * Le résultat est un aperçu : tout se vérifie avant l'enregistrement.
 */

export interface DeliveryLine {
  productCode: string | null
  model: string | null
  color: string | null
  frame: string
  engine: string
}

export interface DeliveryParse {
  lines: DeliveryLine[]
  duplicates: string[]
}

const COLOR_CODES: Record<string, string> = {
  BL: 'Bleu',
  BU: 'Bleu',
  RD: 'Rouge',
  RG: 'Rouge',
  RE: 'Rouge',
  BK: 'Noir',
  NR: 'Noir',
  NO: 'Noir',
  WH: 'Blanc',
  BC: 'Blanc',
  BA: 'Blanc',
  GR: 'Gris',
  GY: 'Gris',
  SV: 'Argent',
  AR: 'Argent',
  GN: 'Vert',
  VE: 'Vert',
  YL: 'Jaune',
  JA: 'Jaune',
  OR: 'Orange',
}

const VIN = /\b([A-HJ-NPR-Z0-9]{17})\b/g
const PRODUCT = /\[([A-Z0-9][A-Z0-9-]{3,40})\]([^[]*)/g

function capitalize(value: string): string {
  const text = value.trim().toLocaleLowerCase('fr')
  return text.charAt(0).toLocaleUpperCase('fr') + text.slice(1)
}

/** Code produit et description : modèle sans le suffixe de couleur, couleur en clair. */
export function productInfo(code: string, description = ''): { model: string; color: string | null } {
  const parenthesis = /\(([^)]{2,30})\)/.exec(description)?.[1] ?? null
  const suffix = /-([A-Z]{2,3})$/.exec(code)?.[1] ?? null
  const color = parenthesis ? capitalize(parenthesis) : suffix && COLOR_CODES[suffix] ? COLOR_CODES[suffix] : null
  const model = suffix && (parenthesis || COLOR_CODES[suffix]) ? code.slice(0, -(suffix.length + 1)) : code
  return { model, color }
}

/** Numéro de moteur : premier mot de 8 à 24 caractères, lettres et chiffres, après le châssis. */
function engineAfter(text: string, from: number): { value: string; end: number } | null {
  const pattern = /[A-Z0-9]+/g
  pattern.lastIndex = from
  let match: RegExpExecArray | null
  while ((match = pattern.exec(text)) && match.index < from + 120) {
    const value = match[0]
    if (value.length >= 8 && value.length <= 24 && /[A-Z]/.test(value) && /\d/.test(value)) return { value, end: match.index + value.length }
  }
  return null
}

export function parseNovamatDelivery(raw: string): DeliveryParse {
  const text = raw.toUpperCase().replace(/[ \t]+/g, ' ')
  const products: Array<{ at: number; code: string; description: string }> = []
  for (const match of text.matchAll(PRODUCT)) products.push({ at: match.index ?? 0, code: match[1], description: match[2] ?? '' })

  const lines: DeliveryLine[] = []
  const seen = new Set<string>()
  const duplicates: string[] = []
  // Fin du dernier numéro de moteur lu : un moteur de 17 caractères n'est pas pris pour un châssis.
  let consumed = 0
  for (const match of text.matchAll(VIN)) {
    const frame = match[1]
    const at = match.index ?? 0
    // Un VIN contient des lettres et des chiffres ; un mot de 17 lettres n'en est pas un.
    if (at < consumed || !/[A-Z]/.test(frame) || !/\d/.test(frame)) continue
    const found = engineAfter(text, at + frame.length)
    if (!found) continue
    consumed = found.end
    const engine = found.value
    if (seen.has(frame)) {
      duplicates.push(frame)
      continue
    }
    seen.add(frame)
    const product = [...products].reverse().find((item) => item.at < at) ?? null
    const info = product ? productInfo(product.code, product.description) : null
    lines.push({ productCode: product?.code ?? null, model: info?.model ?? null, color: info?.color ?? null, frame, engine })
  }
  return { lines, duplicates }
}

/** Modèle existant correspondant au code lu (sans tiret ni casse). */
export function matchModel<T extends { id: string; model: string }>(code: string | null, models: T[]): T | null {
  if (!code) return null
  const key = modelKey(code)
  return models.find((model) => modelKey(model.model) === key) ?? null
}

/** Texte d'un PDF, page par page, lu dans le navigateur (aucun envoi au serveur). */
export async function pdfText(file: File): Promise<string> {
  const pdfjs = await import('pdfjs-dist')
  const worker = await import('pdfjs-dist/build/pdf.worker.min.mjs?url')
  pdfjs.GlobalWorkerOptions.workerSrc = worker.default
  const document = await pdfjs.getDocument({ data: new Uint8Array(await file.arrayBuffer()) }).promise
  const pages: string[] = []
  for (let index = 1; index <= document.numPages; index += 1) {
    const page = await document.getPage(index)
    const content = await page.getTextContent()
    pages.push(content.items.map((item) => ('str' in item ? item.str + (item.hasEOL ? '\n' : ' ') : '')).join(''))
  }
  return pages.join('\n')
}
