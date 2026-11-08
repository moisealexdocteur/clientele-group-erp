import { api } from './client'
import type {
  PartsFitment,
  PartsMovement,
  PartsMovementKind,
  PartsPriceList,
  PartsProduct,
  PartsProductInput,
  PartsSale,
  PartsSalesDayResponse,
} from './types'

/* Appels de l'API Auto Parts. La société active est ajoutée par le client HTTP. */

const base = '/api/v1/parts'

export function fetchProducts(query: { q?: string; include_inactive?: boolean; low_stock?: boolean } = {}) {
  return api<{ data: PartsProduct[]; meta: { total: number; limit: number } }>(`${base}/products`, {
    query: {
      q: query.q || undefined,
      include_inactive: query.include_inactive ? '1' : undefined,
      low_stock: query.low_stock ? '1' : undefined,
    },
  })
}

export function fetchProduct(productId: string) {
  return api<{ data: PartsProduct }>(`${base}/products/${productId}`)
}

export function createProduct(payload: PartsProductInput & { initial_site_id?: string | null; initial_quantity?: number | null; initial_unit_cost_htg?: string | null }) {
  return api<{ data: PartsProduct }>(`${base}/products`, { method: 'POST', body: payload })
}

export function updateProduct(productId: string, payload: PartsProductInput) {
  return api<{ data: PartsProduct }>(`${base}/products/${productId}`, { method: 'PATCH', body: payload })
}

export function updateProductActive(productId: string, isActive: boolean) {
  return api<{ data: PartsProduct }>(`${base}/products/${productId}/active`, { method: 'PATCH', body: { is_active: isActive } })
}

export function updateProductPhoto(productId: string, fileId: string | null) {
  return api<{ data: PartsProduct }>(`${base}/products/${productId}/photo`, { method: 'PUT', body: { file_id: fileId } })
}

export function saveProductFitments(productId: string, fitments: PartsFitment[]) {
  return api<{ data: PartsProduct }>(`${base}/products/${productId}/fitments`, { method: 'PUT', body: { fitments } })
}

export function receiveStock(payload: { site_id: string; reference: string; note?: string | null; lines: Array<{ product_id: string; quantity: number; unit_cost_htg?: string | null }> }) {
  return api<{ data: PartsMovement[] }>(`${base}/stock/receipts`, { method: 'POST', body: payload })
}

export function adjustStock(payload: { product_id: string; site_id: string; counted_quantity: number; reason: string }) {
  return api<{ data: PartsMovement }>(`${base}/stock/adjustments`, { method: 'POST', body: payload })
}

export function transferStock(payload: { product_id: string; from_site_id: string; to_site_id: string; quantity: number; note?: string | null }) {
  return api<{ data: PartsMovement[] }>(`${base}/stock/transfers`, { method: 'POST', body: payload })
}

export function fetchStockMovements(query: { site_id?: string; kind?: PartsMovementKind; from?: string; to?: string } = {}) {
  return api<{ data: PartsMovement[]; meta: { from: string; to: string } }>(`${base}/stock/movements`, { query })
}

export interface SalePayload {
  id: string
  site_id: string
  cash_register_id: string
  price_list: PartsPriceList
  customer_name?: string | null
  amount_received: string
  lines: Array<{ product_id: string; stock_site_id: string; quantity: number }>
}

export function createSale(payload: SalePayload) {
  return api<{ data: PartsSale }>(`${base}/sales`, { method: 'POST', body: payload })
}

export function fetchSales(query: { date?: string; site_id?: string } = {}) {
  return api<PartsSalesDayResponse>(`${base}/sales`, { query })
}

export function fetchSale(saleId: string) {
  return api<{ data: PartsSale }>(`${base}/sales/${saleId}`)
}

export function recordSaleReceiptPrint(saleId: string, copy: 'client' | 'administration') {
  return api<{ data: PartsSale }>(`${base}/sales/${saleId}/receipt/prints`, { method: 'POST', body: { copy } })
}
