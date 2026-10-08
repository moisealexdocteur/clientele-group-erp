<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import {
  deleteCompanyUser,
  fetchCompanyUsers,
  resetCompanyUserPassword,
  updateCompanyUser,
  updateCompanyUserStatus,
} from '../../api/system'
import type { CarRentalUserRole, SystemCompanyUser } from '../../api/types'
import { useSystemStore } from '../../stores/system'
import { useAppStore } from '../../stores/app'
import { useUiStore } from '../../stores/ui'
import { useRequest } from '../../composables/useRequest'
import { roleLabel } from '../../lib/labels'
import { isStrongPassword, PASSWORD_RULE } from '../../lib/text'
import PageHeader from '../../components/ui/PageHeader.vue'
import FormField from '../../components/ui/FormField.vue'
import InlineAlert from '../../components/ui/InlineAlert.vue'
import StatusPill from '../../components/ui/StatusPill.vue'
import SheetDialog from '../../components/ui/SheetDialog.vue'
import UserAccessFields from '../../components/system/UserAccessFields.vue'

const props = defineProps<{ companyId: string; accessId: string }>()

const router = useRouter()
const system = useSystemStore()
const app = useAppStore()
const ui = useUiStore()
const loading = useRequest()
const saving = useRequest()
const statusRequest = useRequest()
const passwordRequest = useRequest()

const user = ref<SystemCompanyUser | null>(null)
const passwordOpen = ref(false)
const company = computed(() => system.company(props.companyId))

const form = reactive({
  name: '',
  email: '',
  role_key: 'car_rental_agent' as CarRentalUserRole,
  site_scope: 'all' as 'all' | 'selected',
  site_ids: [] as string[],
})
const passwordForm = reactive({ password: '', password_confirmation: '' })

function apply(next: SystemCompanyUser): void {
  user.value = next
  Object.assign(form, {
    name: next.name ?? '',
    email: next.email ?? '',
    role_key: next.role_key as CarRentalUserRole,
    site_scope: next.site_scope,
    site_ids: next.sites.map((site) => site.id),
  })
}

onMounted(async () => {
  system.remember(props.companyId)
  const result = await loading.run(async () => {
    await system.ensureLoaded()
    return fetchCompanyUsers(props.companyId)
  })
  const found = result?.data.find((item) => item.id === props.accessId)
  if (found) apply(found)
  else if (result) loading.fail('Cet utilisateur n’existe pas ou n’a plus accès à cette société.')
})

async function save(): Promise<void> {
  if (form.site_scope === 'selected' && !form.site_ids.length) {
    saving.fail('Vérifiez les champs signalés.', { site_ids: 'Sélectionnez au moins une adresse pour un accès limité.' })
    return
  }
  const result = await saving.run(() => updateCompanyUser(props.companyId, props.accessId, {
    ...form,
    name: form.name.trim(),
    email: form.email.trim().toLowerCase(),
  }))
  if (!result) return
  apply(result.data)
  ui.toast('Modifications enregistrées.')
}

async function toggleStatus(): Promise<void> {
  if (!user.value) return
  const activate = !user.value.is_active
  const confirmed = await ui.confirm({
    title: activate ? 'Réactiver l’accès' : 'Désactiver l’accès',
    message: activate
      ? `L’accès de ${user.value.name} sera rétabli pour cette société.`
      : `L’accès de ${user.value.name} sera supprimé pour cette société. Les données et le journal restent conservés.`,
    confirmLabel: activate ? 'Réactiver' : 'Désactiver',
    danger: !activate,
  })
  if (!confirmed) return
  const result = await statusRequest.run(() => updateCompanyUserStatus(props.companyId, props.accessId, activate))
  if (!result) return
  apply(result.data)
  ui.toast(activate ? 'Accès réactivé.' : 'Accès désactivé pour cette société.')
}

function openPassword(): void {
  Object.assign(passwordForm, { password: '', password_confirmation: '' })
  passwordRequest.reset()
  passwordOpen.value = true
}

async function resetPassword(): Promise<void> {
  if (!isStrongPassword(passwordForm.password)) {
    passwordRequest.fail('Vérifiez les champs signalés.', { password: PASSWORD_RULE })
    return
  }
  if (passwordForm.password !== passwordForm.password_confirmation) {
    passwordRequest.fail('Vérifiez les champs signalés.', { password_confirmation: 'Les deux mots de passe ne correspondent pas.' })
    return
  }
  const result = await passwordRequest.run(() =>
    resetCompanyUserPassword(props.companyId, props.accessId, passwordForm.password, passwordForm.password_confirmation),
  )
  Object.assign(passwordForm, { password: '', password_confirmation: '' })
  if (!result) return
  passwordOpen.value = false
  ui.toast(result.message)
}

async function remove(): Promise<void> {
  if (!user.value) return
  const target = user.value
  const confirmed = await ui.confirm({
    title: 'Supprimer définitivement l’utilisateur',
    message: `Le compte de ${target.name} sera supprimé définitivement. Les transactions et le journal d’audit restent conservés. Saisissez son courriel pour confirmer.`,
    confirmLabel: 'Supprimer définitivement',
    danger: true,
    typedConfirmation: { label: 'Courriel à confirmer', expected: target.email },
  })
  if (!confirmed) return
  const result = await statusRequest.run(async () => {
    await deleteCompanyUser(props.companyId, props.accessId, target.email.toLowerCase())
    return true
  })
  if (!result) return
  ui.toast('Utilisateur supprimé définitivement.')
  await router.replace({ name: 'system.users', params: { companyId: props.companyId } })
}
</script>

<template>
  <PageHeader
    :title="user?.name ?? 'Utilisateur'"
    :description="user ? `${roleLabel(user.role_key)} - ${company?.display_name ?? ''}` : undefined"
    :back="{ name: 'system.users', params: { companyId } }"
    back-label="Utilisateurs"
  >
    <template v-if="user" #before-title>
      <StatusPill :tone="user.is_active ? 'success' : 'neutral'" :label="user.is_active ? 'Accès actif' : 'Accès désactivé'" />
    </template>
  </PageHeader>

  <InlineAlert :message="loading.error.value" />
  <div v-if="loading.busy.value" class="skeleton" style="height: 320px"></div>

  <div v-else-if="user" class="detail-grid">
    <form class="panel form" novalidate @submit.prevent="save">
      <h2 class="title-section">Profil et accès</h2>
      <p v-if="!user.can_edit_personal_profile" class="alert alert-info">
        Ce compte est actif dans une autre société : le nom, le courriel et le mot de passe sont gérés par l’utilisateur.
      </p>
      <div class="grid-2">
        <FormField label="Nom complet" :error="saving.fieldErrors.value.name" v-slot="field">
          <input v-model.trim="form.name" v-bind="field.attrs" class="input" maxlength="255" required :disabled="saving.busy.value || !user.can_edit_personal_profile" />
        </FormField>
        <FormField label="Courriel personnel" :error="saving.fieldErrors.value.email" v-slot="field">
          <input v-model.trim="form.email" v-bind="field.attrs" class="input" type="email" maxlength="254" required :disabled="saving.busy.value || !user.can_edit_personal_profile" />
        </FormField>
      </div>
      <UserAccessFields
        v-model:role="form.role_key"
        v-model:scope="form.site_scope"
        v-model:site-ids="form.site_ids"
        :sites="company?.sites ?? []"
        :errors="saving.fieldErrors.value"
        :disabled="saving.busy.value"
      />
      <InlineAlert :message="saving.error.value" />
      <button class="btn btn-primary btn-block" type="submit" :disabled="saving.busy.value || !app.canReachServer">
        {{ saving.busy.value ? 'Enregistrement' : 'Enregistrer les modifications' }}
      </button>
    </form>

    <div class="stack">
      <InlineAlert :message="statusRequest.error.value" />
      <section class="panel">
        <h2 class="title-section">Accès à la société</h2>
        <p class="text-secondary">{{ user.is_active ? 'L’utilisateur peut accéder à cette société.' : 'L’accès à cette société est désactivé.' }}</p>
        <button class="btn" :class="user.is_active ? 'btn-secondary' : 'btn-primary'" type="button" :disabled="statusRequest.busy.value || !app.canReachServer" @click="toggleStatus">
          {{ user.is_active ? 'Désactiver l’accès' : 'Réactiver l’accès' }}
        </button>
      </section>

      <section v-if="user.can_edit_personal_profile" class="panel">
        <h2 class="title-section">Mot de passe</h2>
        <p class="text-secondary">Définissez un nouveau mot de passe si l’utilisateur ne peut pas le réinitialiser lui-même.</p>
        <button class="btn btn-secondary" type="button" :disabled="!app.canReachServer" @click="openPassword">Réinitialiser le mot de passe</button>
      </section>

      <section class="panel danger-zone">
        <h2 class="title-section">Supprimer définitivement</h2>
        <p class="text-secondary">Le compte sera supprimé. Les transactions et le journal d’audit restent conservés.</p>
        <button class="btn btn-danger" type="button" :disabled="statusRequest.busy.value || !user.can_delete_permanently || !app.canReachServer" @click="remove">
          Supprimer l’utilisateur
        </button>
        <p v-if="!user.can_delete_permanently" class="field-help">Indisponible pour un compte rattaché à une autre société.</p>
      </section>
    </div>
  </div>

  <SheetDialog :open="passwordOpen" title="Réinitialiser le mot de passe" :description="PASSWORD_RULE" :locked="passwordRequest.busy.value" @close="passwordOpen = false">
    <form id="password-form" class="form" novalidate @submit.prevent="resetPassword">
      <FormField label="Nouveau mot de passe" :error="passwordRequest.fieldErrors.value.password" v-slot="field">
        <input v-model="passwordForm.password" v-bind="field.attrs" class="input" type="password" minlength="12" autocomplete="new-password" required />
      </FormField>
      <FormField label="Confirmer le nouveau mot de passe" :error="passwordRequest.fieldErrors.value.password_confirmation" v-slot="field">
        <input v-model="passwordForm.password_confirmation" v-bind="field.attrs" class="input" type="password" minlength="12" autocomplete="new-password" required />
      </FormField>
      <InlineAlert :message="passwordRequest.error.value" />
    </form>
    <template #footer>
      <button class="btn btn-secondary" type="button" :disabled="passwordRequest.busy.value" @click="passwordOpen = false">Annuler</button>
      <button class="btn btn-primary" type="submit" form="password-form" :disabled="passwordRequest.busy.value">Réinitialiser</button>
    </template>
  </SheetDialog>
</template>

<style scoped>
.detail-grid {
  display: grid;
  gap: 20px;
  align-items: start;
}

.danger-zone {
  box-shadow: inset 0 0 0 1.5px var(--danger-soft);
}

@media (min-width: 1100px) {
  .detail-grid {
    grid-template-columns: minmax(0, 1.4fr) minmax(300px, 1fr);
  }
}
</style>
