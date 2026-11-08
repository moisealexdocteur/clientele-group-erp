import { describe, expect, it } from 'vitest'
import type { PartsProduct } from '../api/types'
import { addToCart, cartTotalCents, defaultStockSite, itemCount, lineProblem } from './partsCart'

function product(stock: Array<[string, number]> | null, wholesale: string | null = '1250.50'): PartsProduct {
  return {
    id: 'p1',
    sku: 'HJ-CHAIN',
    barcode: null,
    name: 'Chaîne',
    category: null,
    brand: null,
    unit: 'pièce',
    retail_price_htg: '1500.00',
    wholesale_price_htg: wholesale,
    has_wholesale_price: wholesale !== null,
    reorder_level: 0,
    notes: null,
    is_active: true,
    photo_file_id: null,
    photo_url: null,
    fitments: [],
    stock: stock === null ? null : stock.map(([site_id, quantity]) => ({ site_id, site_name: site_id, kind: 'store' as const, quantity })),
    total_stock: null,
    is_low: false,
  }
}

describe('panier de vente', () => {
  it('propose le magasin s’il a la pièce, sinon l’emplacement le mieux fourni', () => {
    expect(defaultStockSite(product([['mag', 2], ['ent', 9]]), 'mag')).toBe('mag')
    expect(defaultStockSite(product([['mag', 0], ['ent', 9]]), 'mag')).toBe('ent')
    expect(defaultStockSite(product([['mag', 0]]), 'mag')).toBe('mag')
    expect(defaultStockSite(product(null), 'mag')).toBe('mag')
  })

  it('regroupe les ajouts et calcule le total en centimes', () => {
    const item = product([['mag', 5]])
    const lines = addToCart(addToCart([], item, 'mag'), item, 'mag')
    expect(lines).toHaveLength(1)
    expect(itemCount(lines)).toBe(2)
    expect(cartTotalCents(lines, 'retail')).toBe(300000)
    expect(cartTotalCents(lines, 'wholesale')).toBe(250100)
  })

  it('signale le stock insuffisant et le prix de gros absent', () => {
    expect(lineProblem({ product: product([['mag', 1]]), stock_site_id: 'mag', quantity: 2 }, 'retail')).toBe('1 disponible à cet emplacement.')
    expect(lineProblem({ product: product([['mag', 3]], null), stock_site_id: 'mag', quantity: 1 }, 'wholesale')).toBe('Pas de prix de gros pour cet article.')
    expect(lineProblem({ product: product([['mag', 3]]), stock_site_id: 'mag', quantity: 3 }, 'retail')).toBeNull()
  })
})
