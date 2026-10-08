import { ref } from 'vue'
import { errorMessage, fieldErrors as extractFieldErrors } from '../api/client'

/**
 * État d'une action serveur : chargement, message d'erreur général
 * et erreurs par champ. Remplace les dizaines de variables
 * « busy / message / error » de l'ancienne interface.
 */
export function useRequest() {
  const busy = ref(false)
  const error = ref('')
  const fieldErrors = ref<Record<string, string>>({})

  async function run<T>(action: () => Promise<T>): Promise<T | undefined> {
    busy.value = true
    error.value = ''
    fieldErrors.value = {}

    try {
      return await action()
    } catch (caught) {
      fieldErrors.value = extractFieldErrors(caught)
      error.value = Object.keys(fieldErrors.value).length > 0
        ? 'Vérifiez les champs signalés.'
        : errorMessage(caught)
      return undefined
    } finally {
      busy.value = false
    }
  }

  function fail(message: string, fields: Record<string, string> = {}): void {
    error.value = message
    fieldErrors.value = fields
  }

  function clearField(field: string): void {
    if (!(field in fieldErrors.value)) return
    const next = { ...fieldErrors.value }
    delete next[field]
    fieldErrors.value = next
  }

  function reset(): void {
    error.value = ''
    fieldErrors.value = {}
  }

  return { busy, error, fieldErrors, run, fail, clearField, reset }
}
