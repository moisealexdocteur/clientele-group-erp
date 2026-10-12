import { api } from './client'
import type { CashPermissions, CashRegisterState, CashSession, DailyCashReport } from './types'

/* Caisse commune. La société active est ajoutée par le client HTTP. */

const base = '/api/v1/cash'

export function fetchCashRegisters() {
  return api<{ data: CashRegisterState[]; permissions: CashPermissions }>(`${base}/registers`)
}

export function openCashSession(registerId: string, payload: { opening_usd: string; opening_htg: string }) {
  return api<{ data: CashSession }>(`${base}/registers/${registerId}/sessions`, { method: 'POST', body: payload })
}

export function fetchCashSession(sessionId: string) {
  return api<{ data: CashSession }>(`${base}/sessions/${sessionId}`)
}

export function closeCashSession(sessionId: string, payload: { declared_usd: string; declared_htg: string; variance_note?: string }) {
  return api<{ data: CashSession }>(`${base}/sessions/${sessionId}/close`, { method: 'POST', body: payload })
}

export function reviewCashSession(sessionId: string, note: string) {
  return api<{ data: CashSession }>(`${base}/sessions/${sessionId}/review`, { method: 'POST', body: { note } })
}

export function recordCashSessionPrint(sessionId: string) {
  return api<{ data: CashSession }>(`${base}/sessions/${sessionId}/prints`, { method: 'POST' })
}

export function fetchDailyCashReport(query: { date?: string; site_id?: string }) {
  return api<{ data: DailyCashReport }>(`${base}/reports/daily`, { query })
}

export type DailyReportFormat = 'pdf' | 'xlsx' | 'print_a4' | 'print_80mm'

export function recordDailyReportExport(payload: { date: string; site_id?: string; format: DailyReportFormat }) {
  return api<{ recorded: boolean }>(`${base}/reports/daily/exports`, { method: 'POST', body: payload })
}
