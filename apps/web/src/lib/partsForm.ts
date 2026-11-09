import type { PartsProduct, PartsProductInput } from '../api/types'

/** Formulaire vide d'un nouvel article : unité « pièce », sans seuil. */
export function emptyProductInput(): PartsProductInput {
  return {
    sku: '',
    barcode: null,
    name: '',
    category: null,
    brand: null,
    unit: 'pièce',
    retail_price_htg: '',
    wholesale_price_htg: null,
    reorder_level: 0,
    notes: null,
  }
}

export function productInputFrom(product: PartsProduct): PartsProductInput {
  return {
    sku: product.sku,
    barcode: product.barcode,
    name: product.name,
    category: product.category,
    brand: product.brand,
    unit: product.unit,
    retail_price_htg: product.retail_price_htg,
    wholesale_price_htg: product.wholesale_price_htg,
    reorder_level: product.reorder_level,
    notes: product.notes,
  }
}

/** Champs vides envoyés comme null, montants avec point décimal. */
export function cleanProductInput(input: PartsProductInput): PartsProductInput {
  const text = (value: string | null) => (value === null || value.trim() === '' ? null : value.trim())
  const amount = (value: string | null) => {
    const cleaned = text(value)
    return cleaned === null ? null : cleaned.replace(/\s/g, '').replace(',', '.')
  }
  return {
    sku: input.sku.trim(),
    barcode: text(input.barcode),
    name: input.name.trim(),
    category: text(input.category),
    brand: text(input.brand),
    unit: input.unit.trim() || 'pièce',
    retail_price_htg: amount(input.retail_price_htg) ?? '',
    wholesale_price_htg: amount(input.wholesale_price_htg),
    reorder_level: Number.isFinite(input.reorder_level) ? Math.max(0, Math.trunc(input.reorder_level)) : 0,
    notes: text(input.notes),
  }
}
