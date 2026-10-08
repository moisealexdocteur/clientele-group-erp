<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useUiStore } from '../../stores/ui'
import SheetDialog from './SheetDialog.vue'
import FormField from './FormField.vue'

/* Dialogue de confirmation unique, piloté par ui.confirm(). */
const ui = useUiStore()
const typed = ref('')

watch(() => ui.confirmation, () => {
  typed.value = ''
})

const canConfirm = computed(() => {
  const expected = ui.confirmation?.typedConfirmation?.expected
  return !expected || typed.value.trim().toLowerCase() === expected.toLowerCase()
})
</script>

<template>
  <SheetDialog
    :open="Boolean(ui.confirmation)"
    :title="ui.confirmation?.title ?? ''"
    @close="ui.settleConfirmation(false)"
  >
    <p class="text-secondary">{{ ui.confirmation?.message }}</p>
    <FormField
      v-if="ui.confirmation?.typedConfirmation"
      :label="ui.confirmation.typedConfirmation.label"
      v-slot="field"
    >
      <input
        v-model="typed"
        v-bind="field.attrs"
        class="input"
        type="text"
        autocomplete="off"
        :placeholder="ui.confirmation.typedConfirmation.expected"
        @keyup.enter="canConfirm && ui.settleConfirmation(true)"
      />
    </FormField>
    <template #footer>
      <button class="btn btn-secondary" type="button" @click="ui.settleConfirmation(false)">
        {{ ui.confirmation?.cancelLabel ?? 'Annuler' }}
      </button>
      <button
        class="btn"
        :class="ui.confirmation?.danger ? 'btn-danger-solid' : 'btn-primary'"
        type="button"
        :disabled="!canConfirm"
        @click="ui.settleConfirmation(true)"
      >
        {{ ui.confirmation?.confirmLabel }}
      </button>
    </template>
  </SheetDialog>
</template>
