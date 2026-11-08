import type { PartsPriceList, PartsProduct } from '../api/types'
import { toCents } from './money'

/*
 * Panier de la vente au comptoir. Les totaux sont calculés en centimes
 * entiers pour l'affichage ; le serveur recalcule tout à partir du catalogue.
 */

export interface CartLine {
  product: PartsProduct
  stock_site_id: string
  quantity: number
}

/** Prix unitaire selon la liste ; null si l'article n'a pas de prix de gros visible. */
export function unitPriceCents(product: PartsProduct, priceList: PartsPriceList): number | null {
  const price = priceList === 'wholesale' ? product.wholesale_price_htg : product.retail_price_htg
  return price === null ? null : toCents(price)
}

export function lineTotalCents(line: CartLine, priceList: PartsPriceList): number {
  return (unitPriceCents(line.product, priceList) ?? 0) * line.quantity
}

export function cartTotalCents(lines: CartLine[], priceList: PartsPriceList): number {
  return lines.reduce((total, line) => total + lineTotalCents(line, priceList), 0)
}

export function itemCount(lines: CartLine[]): number {
  return lines.reduce((total, line) => total + line.quantity, 0)
}

/** Quantité disponible à un emplacement, ou null si le rôle ne voit pas le stock. */
export function availableAt(product: PartsProduct, siteId: string): number | null {
  if (!product.stock) return null
  return product.stock.find((entry) => entry.site_id === siteId)?.quantity ?? 0
}

/**
 * Emplacement proposé : le magasin vendeur s'il a la pièce, sinon
 * l'emplacement qui en a le plus, sinon le magasin.
 */
export function defaultStockSite(product: PartsProduct, storeId: string): string {
  if (!product.stock) return storeId
  if ((availableAt(product, storeId) ?? 0) > 0) return storeId
  const best = [...product.stock].sort((left, right) => right.quantity - left.quantity)[0]
  return best && best.quantity > 0 ? best.site_id : storeId
}

/** Ajoute une pièce : une ligne existante au même emplacement est incrémentée. */
export function addToCart(lines: CartLine[], product: PartsProduct, storeId: string): CartLine[] {
  const siteId = defaultStockSite(product, storeId)
  const existing = lines.find((line) => line.product.id === product.id && line.stock_site_id === siteId)
  if (existing) {
    return lines.map((line) => (line === existing ? { ...line, quantity: line.quantity + 1 } : line))
  }
  return [...lines, { product, stock_site_id: siteId, quantity: 1 }]
}

/** Problèmes connus avant l'envoi : stock insuffisant ou prix de gros absent. */
export function lineProblem(line: CartLine, priceList: PartsPriceList): string | null {
  if (unitPriceCents(line.product, priceList) === null) return 'Pas de prix de gros pour cet article.'
  const available = availableAt(line.product, line.stock_site_id)
  if (available !== null && line.quantity > available) {
    return available === 0 ? 'Aucune pièce à cet emplacement.' : `${available} disponible${available > 1 ? 's' : ''} à cet emplacement.`
  }
  return null
}
