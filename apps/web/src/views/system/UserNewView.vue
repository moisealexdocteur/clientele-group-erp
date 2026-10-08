<script setup lang="ts">
import { computed, onMounted, reactive } from 'vue'
import { useRouter } from 'vue-router'
import { createCompanyUser } from '../../api/system'
import type { CarRentalUserRole } from '../../api/types'
import { useSystemStore } from '../../stores/system'
import { useAppStore } from '../../stores/app'
import { useUiStore } from '../../stores/ui'
import { useRequest } from '../../composables/useRequest'
import { isStrongPassword, PASSWORD_RULE } from '../../lib/text'
import PageHeader from '../../components/ui/PageHeader.vue'
import FormField from '../../components/ui/FormField.vue'
import InlineAlert from '../../components/ui/InlineAlert.vue'
import UserAccessFields from '../../components/system/UserAccessFields.vue'

const props = defineProps<{ companyId: string }>()

const router = useRouter()
const system = useSystemStore()
const app = useAppStore()
const ui = useUiStore()
const request = useRequest()

const company = computed(() => system.company(props.companyId))

const form = reactive({
  name: '',
  email: '',
  role_key: 'car_rental_agent' as CarRentalUserRole,
  site_scope: 'all' as 'all' | 'selected',
  site_ids: [] as string[],
  password: '',
  password_confirmation: '',
})

onMounted(() => {
  void system.ensureLoaded().catch(() => undefined)
})

async function submit(): Promise<void> {
  if (!isStrongPassword(form.password)) {
    request.fail('Vérifiez les champs signalés.', { password: `Utilisez ${PASSWORD_RULE.charAt(0).toLowerCase()}${PASSWORD_RULE.slice(1)}` })
    return
  }
  if (form.password !== form.password_confirmation) {
    request.fail('Vérifiez les champs signalés.', { password_confirmation: 'Les deux mots de passe ne correspondent pas.' })
    return
  }
  if (form.site_scope === 'selected' && !form.site_ids.length) {
    request.fail('Vérifiez les champs signalés.', { site_ids: 'Sélectionnez au moins une adresse pour un accès limité.' })
    return
  }

  const result = await request.run(() => createCompanyUser(props.companyId, {
    ...form,
    name: form.name.trim(),
    email: form.email.trim().toLowerCase(),
  }))
  form.password = ''
  form.password_confirmation = ''
  if (!result) return

  ui.toast(result.notification?.sent === false
    ? 'Utilisateur créé. Le courriel de création n’a pas pu être envoyé : vérifiez l’adresse.'
    : 'Utilisateur créé. Un courriel de création a été envoyé.', result.notification?.sent === false ? 'danger' : 'success')
  await router.replace({ name: 'system.user', params: { companyId: props.companyId, accessId: result.data.id } })
}
</script>

<template>
  <PageHeader
    title="Ajouter un utilisateur"
    :description="company ? `Accès à ${company.display_name}. Un code de sécurité sera demandé à chaque connexion.` : undefined"
    :back="{ name: 'system.users', params: { companyId } }"
    back-label="Utilisateurs"
  />

  <form class="panel form" novalidate @submit.prevent="submit">
    <div class="grid-2">
      <FormField label="Nom complet" :error="request.fieldErrors.value.name" v-slot="field">
        <input v-model.trim="form.name" v-bind="field.attrs" class="input" maxlength="255" autocomplete="off" required :disabled="request.busy.value" @input="request.clearField('name')" />
      </FormField>
      <FormField label="Courriel personnel" :error="request.fieldErrors.value.email" v-slot="field">
        <input v-model.trim="form.email" v-bind="field.attrs" class="input" type="email" inputmode="email" maxlength="254" autocomplete="off" required :disabled="request.busy.value" @input="request.clearField('email')" />
      </FormField>
    </div>

    <UserAccessFields
      v-model:role="form.role_key"
      v-model:scope="form.site_scope"
      v-model:site-ids="form.site_ids"
      :sites="company?.sites ?? []"
      :errors="request.fieldErrors.value"
      :disabled="request.busy.value"
    />

    <p class="alert alert-info">Mot de passe initial : {{ PASSWORD_RULE.charAt(0).toLowerCase() + PASSWORD_RULE.slice(1) }}</p>
    <div class="grid-2">
      <FormField label="Mot de passe initial" :error="request.fieldErrors.value.password" v-slot="field">
        <input v-model="form.password" v-bind="field.attrs" class="input" type="password" minlength="12" autocomplete="new-password" required :disabled="request.busy.value" @input="request.clearField('password')" />
      </FormField>
      <FormField label="Confirmer le mot de passe" :error="request.fieldErrors.value.password_confirmation" v-slot="field">
        <input v-model="form.password_confirmation" v-bind="field.attrs" class="input" type="password" minlength="12" autocomplete="new-password" required :disabled="request.busy.value" @input="request.clearField('password_confirmation')" />
      </FormField>
    </div>

    <InlineAlert :message="request.error.value" />
    <button class="btn btn-primary btn-block" type="submit" :disabled="request.busy.value || !app.canReachServer">
      {{ request.busy.value ? 'Enregistrement' : 'Créer l’utilisateur' }}
    </button>
  </form>
</template>
