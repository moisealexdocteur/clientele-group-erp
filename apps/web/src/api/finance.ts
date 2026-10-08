import { api } from './client'
import type { ExchangeRate, PaymentReceipt } from './types'

/* Taux HTG/USD et reçus de caisse. La société active est ajoutée par le client HTTP. */

export function fetchExchangeRates() {
  return api<{ current: ExchangeRate | null; history: ExchangeRate[] }>('/api/v1/exchange-rates')
}

export function setExchangeRate(payload: {
  rate_htg_per_usd: string
  brh_reference_rate?: string
  brh_reference_date?: string
  confirm_below_brh?: boolean
  note?: string
}) {
  return api<{ data: ExchangeRate }>('/api/v1/exchange-rates', { method: 'POST', body: payload })
}

export function fetchReceipt(paymentId: string) {
  return api<{ data: PaymentReceipt }>(`/api/v1/car-rental/payments/${paymentId}/receipt`)
}

export function recordReceiptPrint(paymentId: string, copy: 'client' | 'administration') {
  return api<{ data: PaymentReceipt }>(`/api/v1/car-rental/payments/${paymentId}/receipt/prints`, {
    method: 'POST',
    body: { copy },
  })
}

export interface ReceiptVerification {
  valid: boolean
  message?: string
  company?: string
  number?: string
  issued_at?: string | null
  amount?: string
  currency?: 'HTG' | 'USD'
  status?: string
}

/** Vérification publique, sans session. */
export async function verifyReceipt(companyCode: string, number: string, signature: string): Promise<ReceiptVerification> {
  const response = await fetch(
    `/api/v1/public/receipts/${encodeURIComponent(companyCode)}/${encodeURIComponent(number)}?s=${encodeURIComponent(signature)}`,
    { headers: { Accept: 'application/json' } },
  )
  const payload = (await response.json().catch(() => ({}))) as ReceiptVerification
  if (!response.ok) return { valid: false, message: payload.message ?? 'La vérification n’est pas disponible pour le moment.' }
  return payload
}
