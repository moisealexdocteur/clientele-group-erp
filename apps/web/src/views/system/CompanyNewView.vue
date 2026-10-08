<script setup lang="ts">
import { reactive } from 'vue'
import { useRouter } from 'vue-router'
import { createCompany } from '../../api/system'
import { useSystemStore } from '../../stores/system'
import { useSessionStore } from '../../stores/session'
import { useAppStore } from '../../stores/app'
import { useUiStore } from '../../stores/ui'
import { useRequest } from '../../composables/useRequest'
import { normalizeCode } from '../../lib/text'
import type { Currency } from '../../api/types'
import PageHeader from '../../components/ui/PageHeader.vue'
import FormField from '../../components/ui/FormField.vue'
import InlineAlert from '../../components/ui/InlineAlert.vue'

const router = useRouter()
const system = useSystemStore()
const session = useSessionStore()
const app = useAppStore()
const ui = useUiStore()
const request = useRequest()

const form = reactive({
  code: '',
  legal_name: '',
  display_name: '',
  base_currency: 'HTG' as Currency,
})

async function submit(): Promise<void> {
  form.code = normalizeCode(form.code)
  const result = await request.run(() => createCompany({ ...form }))
  if (!result) return
  await Promise.all([system.load(), session.loadMe()])
  ui.toast('Société créée. Vous pouvez maintenant ajouter une adresse.')
  await router.replace({ name: 'system.company', params: { companyId: result.data.id } })
}
</script>

<template>
  <PageHeader title="Ajouter une société" :back="{ name: 'system.companies' }" back-label="Sociétés" />

  <form class="panel form" novalidate @submit.prevent="submit">
    <FormField label="Code interne" help="Vous pouvez saisir un nom. Les espaces et accents sont convertis automatiquement." :error="request.fieldErrors.value.code" v-slot="field">
      <input v-model.trim="form.code" v-bind="field.attrs" class="input" maxlength="32" autocapitalize="characters" placeholder="Ex. CLIENTELE-RENT-A-CAR" required :disabled="request.busy.value" @blur="form.code = normalizeCode(form.code)" @input="request.clearField('code')" />
    </FormField>
    <FormField label="Dénomination légale" :error="request.fieldErrors.value.legal_name" v-slot="field">
      <input v-model.trim="form.legal_name" v-bind="field.attrs" class="input" maxlength="255" required :disabled="request.busy.value" @input="request.clearField('legal_name')" />
    </FormField>
    <FormField label="Nom affiché" :error="request.fieldErrors.value.display_name" v-slot="field">
      <input v-model.trim="form.display_name" v-bind="field.attrs" class="input" maxlength="255" required :disabled="request.busy.value" @input="request.clearField('display_name')" />
    </FormField>
    <FormField label="Devise de base" :error="request.fieldErrors.value.base_currency" v-slot="field">
      <select v-model="form.base_currency" v-bind="field.attrs" class="select" :disabled="request.busy.value">
        <option value="HTG">HTG</option>
        <option value="USD">USD</option>
      </select>
    </FormField>
    <InlineAlert :message="request.error.value" />
    <button class="btn btn-primary btn-block" type="submit" :disabled="request.busy.value || !app.canReachServer">
      {{ request.busy.value ? 'Enregistrement' : 'Créer la société' }}
    </button>
  </form>
</template>
