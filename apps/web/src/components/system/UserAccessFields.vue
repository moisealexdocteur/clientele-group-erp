<script setup lang="ts">
import type { CarRentalUserRole } from '../../api/types'
import { carRentalUserRoleLabels } from '../../lib/labels'
import FormField from '../ui/FormField.vue'

/* Profil et adresses autorisées d'un utilisateur, communs à la création et à la modification. */
defineProps<{
  sites: Array<{ id: string; name: string; code: string }>
  errors: Record<string, string>
  disabled?: boolean
}>()

const role = defineModel<CarRentalUserRole>('role', { required: true })
const scope = defineModel<'all' | 'selected'>('scope', { required: true })
const siteIds = defineModel<string[]>('siteIds', { required: true })

function onScopeChange(): void {
  if (scope.value === 'all') siteIds.value = []
}
</script>

<template>
  <FormField label="Profil Car Rental" :error="errors.role_key" v-slot="field">
    <select v-model="role" v-bind="field.attrs" class="select" :disabled="disabled">
      <option v-for="(label, key) in carRentalUserRoleLabels" :key="key" :value="key">{{ label }}</option>
    </select>
  </FormField>

  <fieldset class="form-group" :disabled="disabled">
    <legend>Adresses autorisées</legend>
    <label class="check">
      <input v-model="scope" type="radio" value="all" @change="onScopeChange" />
      <span>Toutes les adresses actives</span>
    </label>
    <label class="check">
      <input v-model="scope" type="radio" value="selected" @change="onScopeChange" />
      <span>Adresses sélectionnées</span>
    </label>
    <div v-if="scope === 'selected'" class="site-list">
      <label v-for="site in sites" :key="site.id" class="check">
        <input v-model="siteIds" type="checkbox" :value="site.id" />
        <span>{{ site.name }} <span class="text-muted">({{ site.code }})</span></span>
      </label>
      <p v-if="!sites.length" class="field-help">Cette société n’a pas encore d’adresse.</p>
    </div>
    <span v-if="errors.site_scope" class="field-error" role="alert">{{ errors.site_scope }}</span>
    <span v-if="errors.site_ids" class="field-error" role="alert">{{ errors.site_ids }}</span>
  </fieldset>
</template>

<style scoped>
.site-list {
  display: grid;
  gap: 8px;
  padding-left: 12px;
  border-left: 3px solid var(--accent-soft);
}
</style>
