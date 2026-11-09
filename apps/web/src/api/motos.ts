import { api } from './client'
import type { Currency, MotoModel, MotoOrder, MotoOrderDetail, MotoOrderFilter, MotoUnit } from './types'

/* Vente de motocyclettes Auto Parts. La société active est ajoutée par le client HTTP. */

const base = '/api/v1/parts/motos'

export function fetchMotoModels() {
  return api<{ data: MotoModel[] }>(`${base}/models`)
}

export interface MotoModelPayload {
  make: string
  model: string
  displacement_cc: number | null
  cylinders: number
  price: string
  price_currency: Currency
  is_active?: boolean
}

export function saveMotoModel(payload: MotoModelPayload, modelId?: string) {
  return api<{ data: MotoModel }>(modelId ? `${base}/models/${modelId}` : `${base}/models`, { method: modelId ? 'PATCH' : 'POST', body: payload })
}

export function fetchMotoOrders(query: { status?: MotoOrderFilter; q?: string } = {}) {
  return api<{ data: MotoOrder[]; meta: { status: MotoOrderFilter; counts: Record<MotoOrderFilter, number> } }>(`${base}/orders`, { query })
}

export function fetchMotoOrder(orderId: string) {
  return api<{ data: MotoOrderDetail }>(`${base}/orders/${orderId}`)
}

export interface MotoOrderPayload {
  site_id: string
  model_id: string
  preferred_color: string | null
  customer_name: string
  customer_phone: string
  customer_email: string | null
  customer_address: string | null
  payment_currency: Currency
  payment_amount: string
  bank_name: string
  deposit_reference: string
  deposit_date: string
  deposit_proof_file_id: string | null
}

export function createMotoOrder(payload: MotoOrderPayload) {
  return api<{ data: MotoOrderDetail }>(`${base}/orders`, { method: 'POST', body: payload })
}

export function verifyMotoPayment(orderId: string) {
  return api<{ data: MotoOrderDetail }>(`${base}/orders/${orderId}/verify`, { method: 'POST', body: { confirmed: true } })
}

export function cancelMotoOrder(orderId: string, reason: string) {
  return api<{ data: MotoOrderDetail }>(`${base}/orders/${orderId}/cancel`, { method: 'POST', body: { reason } })
}

export function assignMotoUnit(orderId: string, unitId: string | null, reason: string) {
  return api<{ data: MotoOrderDetail }>(`${base}/orders/${orderId}/assign`, { method: 'POST', body: { unit_id: unitId, reason } })
}

export function markMotoOrderCalled(orderId: string) {
  return api<{ data: MotoOrderDetail }>(`${base}/orders/${orderId}/called`, { method: 'POST' })
}

export interface MotoDeliveryPayload {
  deposit_slip_presented: boolean
  id_document_type: string
  id_document_number: string
  customer_address: string | null
  checklist: Record<string, boolean>
  accessories: Record<string, boolean>
  customs_document_delivered: boolean
  final_sale_acknowledged: boolean
}

export function deliverMotoOrder(orderId: string, payload: MotoDeliveryPayload) {
  return api<{ data: MotoOrderDetail }>(`${base}/orders/${orderId}/deliver`, { method: 'POST', body: payload })
}

export function deliverCustomsDocument(orderId: string) {
  return api<{ data: MotoOrderDetail }>(`${base}/orders/${orderId}/customs-document`, { method: 'POST', body: { confirmed: true } })
}

export function recordMotoDocumentPrint(orderId: string, document: 'receipt' | 'certificate') {
  return api<{ data: MotoOrderDetail }>(`${base}/orders/${orderId}/prints`, { method: 'POST', body: { document } })
}

export function allocateMotos() {
  return api<{ data: { assigned: number } }>(`${base}/allocate`, { method: 'POST' })
}

export function fetchMotoUnits(query: { status?: MotoUnit['status']; model_id?: string } = {}) {
  return api<{ data: MotoUnit[] }>(`${base}/units`, { query })
}

export interface MotoBatchPayload {
  site_id: string
  reference: string
  note: string | null
  units: Array<{ model_id: string; model_year: number; frame_number: string; engine_number: string; color: string; customs_document_available: boolean }>
}

export function receiveMotoBatch(payload: MotoBatchPayload) {
  return api<{ data: { id: string; reference: string; units: MotoUnit[]; assigned: number } }>(`${base}/batches`, { method: 'POST', body: payload })
}
