<script setup lang="ts">
import { computed, useId } from 'vue'

/*
 * Champ de formulaire accessible : libellé associé, aide et erreur reliées
 * par aria-describedby. Le contrôle est fourni par le slot :
 *   <FormField label="Nom" v-slot="field"><input class="input" v-bind="field.attrs" /></FormField>
 */
const props = defineProps<{
  label: string
  help?: string
  error?: string
  /** Affiche l'astérisque des champs obligatoires. */
  required?: boolean
}>()

const id = useId()
const helpId = `${id}-help`
const errorId = `${id}-error`

const attrs = computed(() => ({
  id,
  'aria-invalid': props.error ? (true as const) : undefined,
  'aria-describedby': [props.help ? helpId : '', props.error ? errorId : ''].filter(Boolean).join(' ') || undefined,
}))
</script>

<template>
  <div class="field">
    <label class="field-label" :for="id">{{ label }}<span v-if="required" class="required" aria-hidden="true">*</span></label>
    <slot :attrs="attrs" />
    <span v-if="help" :id="helpId" class="field-help">{{ help }}</span>
    <span v-if="error" :id="errorId" class="field-error" role="alert">{{ error }}</span>
  </div>
</template>
