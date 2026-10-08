<script setup lang="ts">
import { nextTick, onBeforeUnmount, ref, watch } from 'vue'

/*
 * Panneau de tâche. Sur téléphone il monte du bas de l'écran, à portée du
 * pouce ; sur tablette et ordinateur il s'ouvre au centre. Repose sur
 * l'élément <dialog> natif : piège du focus, touche Échap et arrière-plan inerte.
 */
const props = defineProps<{
  open: boolean
  title: string
  description?: string
  /** Empêche la fermeture pendant une action serveur. */
  locked?: boolean
}>()

const emit = defineEmits<{ close: [] }>()
const dialog = ref<HTMLDialogElement | null>(null)

watch(
  () => props.open,
  async (open) => {
    await nextTick()
    const element = dialog.value
    if (!element) return
    if (open && !element.open) {
      element.showModal()
      document.documentElement.style.overflow = 'hidden'
    } else if (!open && element.open) {
      element.close()
      document.documentElement.style.overflow = ''
    }
  },
  { immediate: true },
)

onBeforeUnmount(() => {
  document.documentElement.style.overflow = ''
})

function requestClose(event?: Event): void {
  event?.preventDefault()
  if (!props.locked) emit('close')
}

function onBackdropClick(event: MouseEvent): void {
  if (event.target === dialog.value) requestClose()
}
</script>

<template>
  <dialog
    ref="dialog"
    class="sheet"
    :aria-label="title"
    @cancel="requestClose"
    @click="onBackdropClick"
  >
    <div class="sheet-body">
      <header class="sheet-header">
        <div class="sheet-heading">
          <h2 class="sheet-title">{{ title }}</h2>
          <p v-if="description" class="text-secondary">{{ description }}</p>
        </div>
        <button class="sheet-close" type="button" aria-label="Fermer" :disabled="locked" @click="requestClose()">
          <span aria-hidden="true"></span>
        </button>
      </header>
      <div class="sheet-content">
        <slot />
      </div>
      <footer v-if="$slots.footer" class="sheet-footer">
        <slot name="footer" />
      </footer>
    </div>
  </dialog>
</template>

<style scoped>
.sheet {
  width: 100%;
  max-width: 100%;
  max-height: 92dvh;
  margin: auto 0 0;
  padding: 0;
  border: 0;
  border-radius: 12px 12px 0 0;
  background: var(--surface);
  color: var(--ink);
  box-shadow: var(--shadow-64);
}

.sheet::backdrop {
  background: rgba(0, 0, 0, 0.4);
}

.sheet[open] {
  animation: sheet-in 220ms cubic-bezier(0.2, 0.8, 0.2, 1);
}

@keyframes sheet-in {
  from {
    transform: translateY(16px);
    opacity: 0;
  }

  to {
    transform: none;
    opacity: 1;
  }
}

.sheet-body {
  display: flex;
  flex-direction: column;
  max-height: 92dvh;
}

.sheet-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
  padding: 20px var(--gutter) 12px;
}

.sheet-heading {
  display: grid;
  gap: 4px;
}

.sheet-title {
  font-family: var(--font-display);
  font-size: var(--text-lg);
  font-weight: 600;
  line-height: 1.4;
}

.sheet-close {
  position: relative;
  flex: none;
  width: var(--tap);
  height: var(--tap);
  margin: -8px -8px 0 0;
  border: 0;
  border-radius: var(--radius-control);
  background: transparent;
  cursor: pointer;
}

.sheet-close:hover {
  background: var(--surface-hover);
}

.sheet-close span::before,
.sheet-close span::after {
  content: '';
  position: absolute;
  top: 50%;
  left: 50%;
  width: 14px;
  height: 1.5px;
  border-radius: 1px;
  background: var(--ink);
  transform: translate(-50%, -50%) rotate(45deg);
}

.sheet-close span::after {
  transform: translate(-50%, -50%) rotate(-45deg);
}

.sheet-content {
  display: grid;
  gap: 18px;
  padding: 4px var(--gutter) 20px;
  overflow-y: auto;
  overscroll-behavior: contain;
}

.sheet-footer {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  padding: 12px var(--gutter) calc(16px + env(safe-area-inset-bottom));
  border-top: 1px solid var(--line);
}

.sheet-footer > :deep(.btn) {
  flex: 1 1 160px;
}

@media (min-width: 720px) {
  .sheet {
    width: min(560px, calc(100% - 48px));
    margin: auto;
    border-radius: 8px;
  }

  /* Dialogue Fluent : 24 px de marge, actions alignées à droite, sans séparateur. */
  .sheet-header,
  .sheet-content,
  .sheet-footer {
    padding-left: 24px;
    padding-right: 24px;
  }

  .sheet-header {
    padding-top: 24px;
  }

  .sheet-footer {
    justify-content: flex-end;
    padding-bottom: 24px;
    border-top: 0;
  }

  .sheet-footer > :deep(.btn) {
    flex: 0 0 auto;
    min-width: 96px;
  }
}
</style>
