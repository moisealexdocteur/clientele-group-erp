import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { fetchCompanies } from '../api/system'
import type { SystemCompany } from '../api/types'

/** Sociétés connues de la configuration système, partagées entre les écrans. */
export const useSystemStore = defineStore('system', () => {
  const companies = ref<SystemCompany[]>([])
  const loaded = ref(false)
  const lastCompanyId = ref('')

  const lastCompany = computed(() =>
    companies.value.find((company) => company.id === lastCompanyId.value) ?? companies.value[0] ?? null,
  )

  async function load(): Promise<void> {
    const result = await fetchCompanies()
    companies.value = result.data
    loaded.value = true
  }

  async function ensureLoaded(): Promise<void> {
    if (!loaded.value) await load()
  }

  function company(companyId: string): SystemCompany | null {
    return companies.value.find((item) => item.id === companyId) ?? null
  }

  function remember(companyId: string): void {
    lastCompanyId.value = companyId
  }

  return { companies, loaded, lastCompany, load, ensureLoaded, company, remember }
})
