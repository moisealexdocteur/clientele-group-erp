import { defineStore } from 'pinia'
import { ref } from 'vue'

export interface ConfirmRequest {
  title: string
  message: string
  confirmLabel: string
  cancelLabel?: string
  danger?: boolean
  /** Texte que l'utilisateur doit recopier pour confirmer (suppression définitive). */
  typedConfirmation?: { label: string; expected: string }
}

export interface Toast {
  id: number
  message: string
  tone: 'success' | 'danger' | 'info'
}

/** Dialogue de confirmation global et messages brefs après une action. */
export const useUiStore = defineStore('ui', () => {
  const confirmation = ref<(ConfirmRequest & { resolve: (value: boolean) => void }) | null>(null)
  const toasts = ref<Toast[]>([])
  let nextToastId = 1

  function confirm(request: ConfirmRequest): Promise<boolean> {
    confirmation.value?.resolve(false)
    return new Promise((resolve) => {
      confirmation.value = { ...request, resolve }
    })
  }

  function settleConfirmation(value: boolean): void {
    confirmation.value?.resolve(value)
    confirmation.value = null
  }

  function toast(message: string, tone: Toast['tone'] = 'success'): void {
    const id = nextToastId++
    toasts.value = [...toasts.value, { id, message, tone }]
    window.setTimeout(() => dismissToast(id), tone === 'danger' ? 8000 : 5000)
  }

  function dismissToast(id: number): void {
    toasts.value = toasts.value.filter((item) => item.id !== id)
  }

  return { confirmation, toasts, confirm, settleConfirmation, toast, dismissToast }
})
