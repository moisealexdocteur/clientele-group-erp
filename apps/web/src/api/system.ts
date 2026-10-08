import { api } from './client'
import type { CarRentalUserRole, Currency, SystemCashRegister, SystemCompany, SystemCompanyUser, SystemSite } from './types'

/* Configuration système, réservée au propriétaire. Ces routes n'utilisent pas de société active. */

const base = '/api/v1/system/configuration/companies'
const global = { withoutCompany: true } as const

export function fetchCompanies() {
  return api<{ data: SystemCompany[] }>(base, global)
}

export function createCompany(payload: { code: string; legal_name: string; display_name: string; base_currency: Currency }) {
  return api<{ data: SystemCompany }>(base, { ...global, method: 'POST', body: payload })
}

export function updateCompany(companyId: string, payload: {
  legal_name: string
  display_name: string
  legal_representative?: string | null
  tax_identification_number?: string | null
  legal_address?: string | null
  phone_numbers?: string | null
  rental_contract_terms?: string | null
}) {
  return api<{ data: SystemCompany }>(`${base}/${companyId}`, { ...global, method: 'PATCH', body: payload })
}

export function createSite(companyId: string, payload: { code: string; name: string; address: string }) {
  return api<{ data: SystemSite }>(`${base}/${companyId}/sites`, { ...global, method: 'POST', body: payload })
}

export function createCashRegister(companyId: string, payload: { site_id: string; code: string; name: string }) {
  return api<{ data: SystemCashRegister }>(`${base}/${companyId}/cash-registers`, { ...global, method: 'POST', body: payload })
}

export function fetchCompanyUsers(companyId: string) {
  return api<{ data: SystemCompanyUser[] }>(`${base}/${companyId}/users`, global)
}

export interface CompanyUserPayload {
  name: string
  email: string
  role_key: CarRentalUserRole
  site_scope: 'all' | 'selected'
  site_ids: string[]
}

export function createCompanyUser(companyId: string, payload: CompanyUserPayload & { password: string; password_confirmation: string }) {
  return api<{ data: SystemCompanyUser; notification?: { sent: boolean } }>(`${base}/${companyId}/users`, {
    ...global,
    method: 'POST',
    body: payload,
  })
}

export function updateCompanyUser(companyId: string, accessId: string, payload: CompanyUserPayload) {
  return api<{ data: SystemCompanyUser }>(`${base}/${companyId}/users/${accessId}`, { ...global, method: 'PATCH', body: payload })
}

export function updateCompanyUserStatus(companyId: string, accessId: string, isActive: boolean) {
  return api<{ data: SystemCompanyUser }>(`${base}/${companyId}/users/${accessId}/status`, {
    ...global,
    method: 'PATCH',
    body: { is_active: isActive },
  })
}

export function resetCompanyUserPassword(companyId: string, accessId: string, password: string, confirmation: string) {
  return api<{ message: string }>(`${base}/${companyId}/users/${accessId}/reset-password`, {
    ...global,
    method: 'POST',
    body: { password, password_confirmation: confirmation },
  })
}

export function deleteCompanyUser(companyId: string, accessId: string, confirmationEmail: string) {
  return api<unknown>(`${base}/${companyId}/users/${accessId}`, {
    ...global,
    method: 'DELETE',
    body: { confirmation_email: confirmationEmail },
  })
}
