<script setup lang="ts">
import { useUiStore } from '../../stores/ui'

const ui = useUiStore()
</script>

<template>
  <div class="toasts" aria-live="polite">
    <div v-for="toast in ui.toasts" :key="toast.id" class="toast" :class="`toast-${toast.tone}`" role="status">
      <span>{{ toast.message }}</span>
      <button type="button" class="toast-close" aria-label="Masquer le message" @click="ui.dismissToast(toast.id)">OK</button>
    </div>
  </div>
</template>

<style scoped>
.toasts {
  position: fixed;
  z-index: 40;
  left: var(--gutter);
  right: var(--gutter);
  bottom: calc(var(--tabbar-h) + 12px + env(safe-area-inset-bottom));
  display: grid;
  gap: 8px;
  pointer-events: none;
}

.toast {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  max-width: 560px;
  margin: 0 auto;
  width: 100%;
  padding: 10px 10px 10px 16px;
  border-radius: 14px;
  color: #fff;
  background: var(--ink);
  box-shadow: 0 8px 24px rgba(23, 24, 29, 0.24);
  font-weight: 550;
  pointer-events: auto;
}

.toast-danger {
  background: var(--danger);
}

.toast-close {
  flex: none;
  min-width: var(--tap);
  min-height: 40px;
  border: 0;
  border-radius: 10px;
  color: inherit;
  background: rgba(255, 255, 255, 0.16);
  font-weight: 700;
  cursor: pointer;
}

@media (min-width: 960px) {
  .toasts {
    left: calc(var(--rail-w) + 24px);
    bottom: 24px;
  }
}
</style>
