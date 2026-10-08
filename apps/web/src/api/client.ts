/*
 * Client HTTP unique de la PWA.
 *
 * - Ajoute le jeton de session et la société active à chaque demande.
 * - Transforme toute erreur serveur en ApiError avec un message lisible.
 * - Signale une session expirée (401) pour que l'application revienne à la connexion.
 */

export type ApiValidationErrors = Record<string, string[]>

export class ApiError extends Error {
  constructor(
    message: string,
    readonly status: number,
    readonly errors: ApiValidationErrors = {},
  ) {
    super(message)
    this.name = 'ApiError'
  }

  /** Premier message par champ, pour l'affichage sous chaque champ. */
  get fieldErrors(): Record<string, string> {
    return Object.fromEntries(
      Object.entries(this.errors)
        .filter(([, messages]) => messages.length > 0)
        .map(([field, messages]) => [field, messages[0]]),
    )
  }
}

interface ClientBindings {
  token: () => string
  companyId: () => string | null
  onUnauthorized: () => void
}

let bindings: ClientBindings = {
  token: () => '',
  companyId: () => null,
  onUnauthorized: () => undefined,
}

export function bindApiClient(next: ClientBindings): void {
  bindings = next
}

export interface RequestOptions {
  method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'
  body?: unknown
  query?: Record<string, string | number | boolean | null | undefined>
  /** Force une société précise, par exemple lors du choix de société. */
  companyId?: string
  /** N'envoie pas l'en-tête de société, pour les routes globales. */
  withoutCompany?: boolean
}

export async function api<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const headers = new Headers({ Accept: 'application/json' })
  const token = bindings.token()
  if (token) headers.set('Authorization', `Bearer ${token}`)

  const companyId = options.companyId ?? (options.withoutCompany ? null : bindings.companyId())
  if (companyId) headers.set('X-Clientele-Company-Id', companyId)

  let body: string | undefined
  if (options.body !== undefined) {
    headers.set('Content-Type', 'application/json')
    body = JSON.stringify(options.body)
  }

  const url = new URL(path, window.location.origin)
  for (const [key, value] of Object.entries(options.query ?? {})) {
    if (value !== undefined && value !== null && value !== '') {
      url.searchParams.set(key, String(value))
    }
  }

  let response: Response
  try {
    response = await fetch(url.pathname + url.search, {
      method: options.method ?? 'GET',
      headers,
      body,
    })
  } catch {
    throw new ApiError('La connexion au serveur a échoué. Vérifiez Internet puis réessayez.', 0)
  }

  const payload = (await response.json().catch(() => ({}))) as {
    message?: string
    errors?: ApiValidationErrors
  }

  if (!response.ok) {
    if (response.status === 401 && token) {
      bindings.onUnauthorized()
    }

    const errors = payload.errors ?? {}
    const firstValidationMessage = Object.values(errors).flat().find((message) => message.length > 0)
    throw new ApiError(
      readableMessage(firstValidationMessage ?? payload.message, response.status),
      response.status,
      errors,
    )
  }

  return payload as T
}

function readableMessage(message: string | undefined, status: number): string {
  // Une clé technique comme « validation.regex » ne doit jamais être montrée.
  if (message && !/^[a-z_]+\.[a-z_.]+$/.test(message)) {
    return message
  }

  if (status === 403) return 'Votre rôle ne permet pas cette action.'
  if (status === 404) return 'Cet élément n’existe pas ou n’est pas accessible avec vos droits.'
  if (status === 409) return 'Cet élément a été modifié entre-temps. Actualisez puis réessayez.'
  if (status === 422) return 'Vérifiez les champs signalés.'
  if (status === 429) return 'Trop de tentatives. Patientez une minute puis réessayez.'
  return 'La demande ne peut pas être traitée.'
}

export function errorMessage(error: unknown): string {
  if (error instanceof ApiError) return error.message
  return 'La connexion au serveur a échoué. Vérifiez Internet puis réessayez.'
}

export function fieldErrors(error: unknown): Record<string, string> {
  return error instanceof ApiError ? error.fieldErrors : {}
}
