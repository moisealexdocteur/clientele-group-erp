import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { api, bindApiClient, clearPrivateFiles } from '../api/client'
import type { CompanyChoice, CompanyContext, ContextSite, SessionUser } from '../api/types'

const TOKEN_KEY = 'clientele.erp.session'
const COMPANY_KEY = 'clientele.erp.company'
const officeKey = (companyId: string) => `clientele.erp.car-rental.office.${companyId}`

function readStorage(storage: Storage, key: string): string {
  try {
    return storage.getItem(key) ?? ''
  } catch {
    return ''
  }
}

function writeStorage(storage: Storage, key: string, value: string | null): void {
  try {
    if (value === null) storage.removeItem(key)
    else storage.setItem(key, value)
  } catch {
    // Le navigateur peut bloquer le stockage : la session reste active en mémoire.
  }
}

function deviceLabel(): string {
  return `${navigator.platform || 'Poste'} - ${navigator.userAgent.slice(0, 64)}`
}

/**
 * Session de l'utilisateur : connexion en deux étapes, société active,
 * permissions et bureau actif. Le jeton reste dans sessionStorage, comme
 * dans les versions précédentes : il disparaît à la fermeture de l'onglet.
 */
export const useSessionStore = defineStore('session', () => {
  const token = ref(readStorage(sessionStorage, TOKEN_KEY))
  const user = ref<SessionUser | null>(null)
  const companies = ref<CompanyChoice[]>([])
  const context = ref<CompanyContext | null>(null)
  const officeSiteId = ref('')
  const restored = ref(false)

  // Étape de connexion en cours, conservée entre deux écrans.
  const pendingEmail = ref('')
  const challengeId = ref('')
  const flashMessage = ref('')

  const isAuthenticated = computed(() => Boolean(token.value && user.value))
  const isOwner = computed(() => user.value?.system_role === 'owner')
  /** Saisie du taux HTG/USD du groupe : propriétaire ou personne désignée. */
  const canManageRates = computed(() => isOwner.value || Boolean(user.value?.can_manage_exchange_rates))
  const sites = computed<ContextSite[]>(() => context.value?.sites ?? [])
  const officeSite = computed(() => sites.value.find((site) => site.id === officeSiteId.value) ?? null)

  const permissions = computed<string[]>(() => {
    const value = context.value?.access.permissions
    return Array.isArray(value) ? value : (value?.allow ?? [])
  })

  function can(permission: string): boolean {
    return permissions.value.includes('*') || permissions.value.includes(permission)
  }

  bindApiClient({
    token: () => token.value,
    companyId: () => context.value?.company.id ?? null,
    onUnauthorized: () => {
      clear()
      flashMessage.value = 'Votre session a expiré. Connectez-vous de nouveau.'
    },
  })

  async function login(email: string, password: string): Promise<void> {
    const result = await api<{ challenge_id: string }>('/api/v1/auth/login', {
      method: 'POST',
      body: { email, password, device_name: deviceLabel() },
    })
    pendingEmail.value = email
    challengeId.value = result.challenge_id
  }

  async function verifyLogin(code: string): Promise<void> {
    const result = await api<{ token: string; user: SessionUser }>('/api/v1/auth/login/verify', {
      method: 'POST',
      body: { challenge_id: challengeId.value, code, device_name: deviceLabel() },
    })
    token.value = result.token
    writeStorage(sessionStorage, TOKEN_KEY, result.token)
    user.value = result.user
    challengeId.value = ''
    await loadMe()
  }

  async function requestPasswordReset(email: string): Promise<string> {
    const result = await api<{ message: string; challenge_id: string }>('/api/v1/auth/password/forgot', {
      method: 'POST',
      body: { email },
    })
    pendingEmail.value = email
    challengeId.value = result.challenge_id
    return result.message
  }

  async function resetPassword(code: string, password: string, passwordConfirmation: string): Promise<string> {
    const result = await api<{ message: string }>('/api/v1/auth/password/reset', {
      method: 'POST',
      body: {
        challenge_id: challengeId.value,
        code,
        password,
        password_confirmation: passwordConfirmation,
      },
    })
    challengeId.value = ''
    return result.message
  }

  async function loadMe(): Promise<void> {
    const result = await api<{ user: SessionUser; companies: CompanyChoice[] }>('/api/v1/auth/me', {
      withoutCompany: true,
    })
    user.value = result.user
    companies.value = result.companies
  }

  async function selectCompany(companyId: string): Promise<void> {
    const result = await api<CompanyContext>('/api/v1/context', { companyId })
    context.value = result
    writeStorage(sessionStorage, COMPANY_KEY, companyId)
    const saved = readStorage(localStorage, officeKey(companyId))
    officeSiteId.value = result.sites.some((site) => site.id === saved) ? saved : (result.sites[0]?.id ?? '')
  }

  /**
   * Relit le contexte de la société active (identité légale, conditions du
   * contrat, droits) sans changer le bureau choisi. Utile quand la
   * configuration a été modifiée après la connexion.
   */
  async function refreshContext(): Promise<void> {
    if (!context.value) return
    context.value = await api<CompanyContext>('/api/v1/context', { companyId: context.value.company.id })
  }

  function leaveCompany(): void {
    context.value = null
    officeSiteId.value = ''
    writeStorage(sessionStorage, COMPANY_KEY, null)
  }

  function setOfficeSite(siteId: string): void {
    if (!context.value || !sites.value.some((site) => site.id === siteId)) return
    officeSiteId.value = siteId
    writeStorage(localStorage, officeKey(context.value.company.id), siteId)
  }

  /** Recharge la session après un rafraîchissement de la page. */
  async function restore(): Promise<void> {
    if (restored.value) return
    restored.value = true
    if (!token.value) return

    try {
      await loadMe()
      const savedCompany = readStorage(sessionStorage, COMPANY_KEY)
      const target = companies.value.find((company) => company.id === savedCompany)
        ?? (companies.value.length === 1 ? companies.value[0] : undefined)
      if (target) await selectCompany(target.id)
    } catch {
      clear()
      flashMessage.value = 'Votre session a expiré. Connectez-vous de nouveau.'
    }
  }

  async function logout(): Promise<void> {
    try {
      await api('/api/v1/auth/logout', { method: 'POST', withoutCompany: true })
    } catch {
      // La session locale est supprimée même si le réseau est coupé.
    }
    clear()
  }

  function clear(): void {
    clearPrivateFiles()
    token.value = ''
    user.value = null
    companies.value = []
    context.value = null
    officeSiteId.value = ''
    challengeId.value = ''
    writeStorage(sessionStorage, TOKEN_KEY, null)
    writeStorage(sessionStorage, COMPANY_KEY, null)
  }

  function setFlashMessage(message: string): void {
    flashMessage.value = message
  }

  function takeFlashMessage(): string {
    const message = flashMessage.value
    flashMessage.value = ''
    return message
  }

  return {
    canManageRates,
    refreshContext,
    token,
    user,
    companies,
    context,
    officeSiteId,
    pendingEmail,
    challengeId,
    isAuthenticated,
    isOwner,
    sites,
    officeSite,
    permissions,
    can,
    login,
    verifyLogin,
    requestPasswordReset,
    resetPassword,
    loadMe,
    selectCompany,
    leaveCompany,
    setOfficeSite,
    restore,
    logout,
    clear,
    setFlashMessage,
    takeFlashMessage,
  }
})
