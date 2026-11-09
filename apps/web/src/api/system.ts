import { api } from './client'
import type { CompanyActivity, Currency, SiteKind, SystemCashRegister, SystemCompany, SystemCompanyUser, SystemSite, UserRoleKey } from './types'

/* Configuration système, réservée au propriétaire. Ces routes n'utilisent pas de société active. */

const base = '/api/v1/system/configuration/companies'
const global = { withoutCompany: true } as const

export function fetchCompanies() {
  return api<{ data: SystemCompany[] }>(base, global)
}

export function createCompany(payload: { code: string; legal_name: string; display_name: string; base_currency: Currency; activity: CompanyActivity }) {
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
  roadside_assistance_phone?: string | null
  rental_airport_fee_usd?: string
  rental_cleaning_fee_usd?: string
}) {
  return api<{ data: SystemCompany }>(`${base}/${companyId}`, { ...global, method: 'PATCH', body: payload })
}

export function updateCompanyActivity(companyId: string, activity: CompanyActivity) {
  return api<{ data: { id: string; activity: CompanyActivity; role_options: Array<{ key: string; label: string }> } }>(`${base}/${companyId}/activity`, { ...global, method: 'PATCH', body: { activity } })
}

export function updateMotoCertificateOffset(companyId: string, xMm: string, yMm: string) {
  return api<{ data: { x_mm: string; y_mm: string } }>(`${base}/${companyId}/moto-certificate`, { ...global, method: 'PATCH', body: { x_mm: xMm, y_mm: yMm } })
}

export function updateSiteKind(companyId: string, siteId: string, kind: SiteKind) {
  return api<{ data: { id: string; kind: SiteKind } }>(`${base}/${companyId}/sites/${siteId}/kind`, { ...global, method: 'PATCH', body: { kind } })
}

export function createSite(companyId: string, payload: { code: string; name: string; address: string; kind?: SiteKind }) {
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
  role_key: UserRoleKey
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

export interface AttachableUser {
  id: string
  name: string
  email: string
}

export function fetchAttachableUsers(companyId: string) {
  return api<{ data: AttachableUser[] }>(`${base}/${companyId}/users/attachable`, global)
}

export function attachExistingUser(companyId: string, payload: { user_id: string; role_key: UserRoleKey; site_scope: 'all' | 'selected'; site_ids: string[] }) {
  return api<{ data: { id: string }; notification?: { sent: boolean } }>(`${base}/${companyId}/users/existing`, {
    ...global,
    method: 'POST',
    body: payload,
  })
}

export function updateCompanyUser(companyId: string, accessId: string, payload: CompanyUserPayload) {
  return api<{ data: SystemCompanyUser }>(`${base}/${companyId}/users/${accessId}`, { ...global, method: 'PATCH', body: payload })
}

/** Droit de saisir le taux HTG/USD du groupe, porté par la personne. */
export function updateExchangeRateAccess(companyId: string, accessId: string, allowed: boolean) {
  return api<{ data: SystemCompanyUser }>(`${base}/${companyId}/users/${accessId}/exchange-rate-access`, {
    ...global,
    method: 'PATCH',
    body: { allowed },
  })
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

/** Serveur de courriel en vigueur : réglé dans l'environnement du serveur, lecture seule ici. */
export interface MailSettings {
  mailer: string
  from_address: string | null
  from_name: string | null
  host: string | null
  port: number | null
  configured: boolean
  test_recipient: string | null
}

export function fetchMailSettings() {
  return api<{ data: MailSettings }>('/api/v1/system/configuration/mail', global)
}

export function sendTestMail() {
  return api<{ message: string; sent_at: string }>('/api/v1/system/configuration/mail/test', { ...global, method: 'POST' })
}
