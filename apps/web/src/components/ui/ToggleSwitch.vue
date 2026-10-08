<script setup lang="ts">
/* Interrupteur Fluent : l'état est annoncé en toutes lettres à côté du contrôle. */
defineProps<{
  modelValue: boolean
  onLabel: string
  offLabel: string
  disabled?: boolean
}>()

const emit = defineEmits<{ 'update:modelValue': [value: boolean] }>()
</script>

<template>
  <button
    type="button"
    role="switch"
    class="switch"
    :aria-checked="modelValue"
    :disabled="disabled"
    @click="emit('update:modelValue', !modelValue)"
  >
    <span class="switch-track" aria-hidden="true"><span class="switch-thumb"></span></span>
    <span class="switch-label">{{ modelValue ? onLabel : offLabel }}</span>
  </button>
</template>

<style scoped>
.switch {
  display: inline-flex;
  align-items: center;
  gap: 10px;
  min-height: var(--tap);
  padding: 0 4px;
  border: 0;
  background: transparent;
  font-size: var(--text-sm);
  cursor: pointer;
}

.switch:disabled {
  cursor: not-allowed;
  opacity: 0.5;
}

.switch-track {
  position: relative;
  width: 40px;
  height: 20px;
  border: 1px solid var(--stroke-accessible);
  border-radius: 999px;
  background: var(--surface);
  transition: background-color 100ms ease;
}

.switch-thumb {
  position: absolute;
  top: 3px;
  left: 3px;
  width: 12px;
  height: 12px;
  border-radius: 50%;
  background: var(--ink-2);
  transition: transform 100ms ease;
}

.switch[aria-checked='true'] .switch-track {
  border-color: var(--accent);
  background: var(--accent);
}

.switch[aria-checked='true'] .switch-thumb {
  background: #fff;
  transform: translateX(20px);
}

.switch-label {
  font-weight: 600;
}
</style>
