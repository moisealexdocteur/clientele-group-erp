import { readFileSync, mkdirSync } from 'node:fs'
import type { APIRequestContext, Page } from '@playwright/test'

export const token = (): string => readFileSync('output/.token', 'utf8').trim()

let shot = 0
/** Capture pleine page numérotée, publiée en artefact. */
export async function capture(page: Page, name: string): Promise<void> {
  mkdirSync('output/captures', { recursive: true })
  shot += 1
  await page.waitForLoadState('networkidle').catch(() => undefined)
  await page.screenshot({ path: `output/captures/${String(shot).padStart(2, '0')}-${name}.png`, fullPage: true })
}

/** Appel API en propriétaire, dans le contexte d'une société si fournie. */
export async function apiCall<T = any>(request: APIRequestContext, method: 'GET' | 'POST' | 'PATCH', path: string, data?: unknown, companyId?: string): Promise<T> {
  const response = await request.fetch(`/api/v1${path}`, {
    method,
    data,
    headers: { Authorization: `Bearer ${token()}`, Accept: 'application/json', ...(companyId ? { 'X-Clientele-Company-Id': companyId } : {}) },
  })
  const body = await response.text()
  if (!response.ok()) throw new Error(`${method} ${path} : ${response.status()} ${body}`)
  return (body ? JSON.parse(body) : {}) as T
}

/** Session ouverte dans la PWA : jeton et société choisie. */
export async function signIn(page: Page, companyId: string): Promise<void> {
  await page.addInitScript(([value, company]) => {
    sessionStorage.setItem('clientele.erp.session', value)
    sessionStorage.setItem('clientele.erp.company', company)
  }, [token(), companyId])
}

/** Téléchargement d'un PDF déclenché par un bouton, enregistré en artefact. */
export async function savePdf(page: Page, button: string, name: string): Promise<string> {
  mkdirSync('output/pdf', { recursive: true })
  const [download] = await Promise.all([page.waitForEvent('download'), page.getByRole('button', { name: button, exact: true }).click()])
  const path = `output/pdf/${name}`
  await download.saveAs(path)
  return path
}
