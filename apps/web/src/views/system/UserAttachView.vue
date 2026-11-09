<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { attachExistingUser, fetchAttachableUsers, type AttachableUser } from '../../api/system'
import type { UserRoleKey } from '../../api/types'
import { useSystemStore } from '../../stores/system'
import { useAppStore } from '../../stores/app'
import { useUiStore } from '../../stores/ui'
import { useRequest } from '../../composables/useRequest'
import PageHeader from '../../components/ui/PageHeader.vue'
import FormField from '../../components/ui/FormField.vue'
import InlineAlert from '../../components/ui/InlineAlert.vue'
import UserAccessFields from '../../components/system/UserAccessFields.vue'

/*
 * Donne à un employé qui a déjà un compte (par exemple Car Rental) un accès à
 * cette société, avec un profil et des adresses propres à cette société. Son
 * mot de passe et ses autres accès ne changent pas.
 */
const props = defineProps<{ companyId: string }>()

const router = useRouter()
const system = useSystemStore()
const app = useAppStore()
const ui = useUiStore()
const loading = useRequest()
const request = useRequest()

const company = computed(() => system.company(props.companyId))
const roleOptions = computed(() => company.value?.role_options ?? [])
const users = ref<AttachableUser[]>([])
const query = ref('')

const form = reactive({
  user_id: '',
  role_key: 'car_rental_agent' as UserRoleKey,
  site_scope: 'all' as 'all' | 'selected',
  site_ids: [] as string[],
})

const filtered = computed(() => {
  const term = query.value.trim().toLowerCase()
  if (!term) return users.value
  return users.value.filter((user) => user.name.toLowerCase().includes(term) || user.email.toLowerCase().includes(term))
})

onMounted(async () => {
  void system.ensureLoaded().catch(() => undefined)
  const result = await loading.run(() => fetchAttachableUsers(props.companyId))
  users.value = result?.data ?? []
})

// Profil proposé : agent ou vendeur selon l'activité de la société.
watch(roleOptions, (options) => {
  if (options.some((option) => option.key === form.role_key)) return
  form.role_key = (company.value?.activity === 'auto_parts' ? 'auto_parts_seller' : 'car_rental_agent')
}, { immediate: true })

async function submit(): Promise<void> {
  if (!form.user_id) {
    request.fail('Vérifiez les champs signalés.', { user_id: 'Sélectionnez le compte à ajouter.' })
    return
  }
  if (form.site_scope === 'selected' && !form.site_ids.length) {
    request.fail('Vérifiez les champs signalés.', { site_ids: 'Sélectionnez au moins une adresse pour un accès limité.' })
    return
  }
  const result = await request.run(() => attachExistingUser(props.companyId, { ...form }))
  if (!result) return
  ui.toast(result.notification?.sent === false
    ? 'Accès ajouté. Le courriel d’information n’a pas pu être envoyé.'
    : 'Accès ajouté. L’employé a été informé par courriel.', result.notification?.sent === false ? 'danger' : 'success')
  await router.replace({ name: 'system.user', params: { companyId: props.companyId, accessId: result.data.id } })
}
</script>

<template>
  <PageHeader
    title="Ajouter un employé existant"
    :description="company ? `Accès à ${company.display_name} pour un compte du groupe. Son mot de passe et ses autres accès ne changent pas.` : undefined"
    :back="{ name: 'system.users', params: { companyId } }"
    back-label="Utilisateurs"
  />

  <InlineAlert :message="loading.error.value" />
  <div v-if="loading.busy.value && !users.length" class="skeleton" style="height: 240px"></div>

  <div v-else-if="!users.length" class="empty">
    <p>Tous les comptes du groupe ont déjà accès à {{ company?.display_name ?? 'cette société' }}.</p>
    <RouterLink class="btn btn-primary" :to="{ name: 'system.user.new', params: { companyId } }">Ajouter un utilisateur</RouterLink>
  </div>

  <form v-else class="panel form" novalidate @submit.prevent="submit">
    <fieldset class="form-group" :disabled="request.busy.value">
      <legend>Employé</legend>
      <input v-if="users.length > 6" v-model="query" class="input" type="search" placeholder="Nom ou courriel" aria-label="Rechercher un employé" autocomplete="off" />
      <div class="people" role="radiogroup" aria-label="Employé">
        <label v-for="user in filtered" :key="user.id" class="person" :class="{ 'is-selected': form.user_id === user.id }">
          <input v-model="form.user_id" type="radio" name="user_id" :value="user.id" @change="request.clearField('user_id')" />
          <span>
            <strong>{{ user.name }}</strong>
            <span class="text-small text-muted">{{ user.email }}</span>
          </span>
        </label>
        <p v-if="!filtered.length" class="text-small text-muted">Aucun compte ne correspond.</p>
      </div>
      <span v-if="request.fieldErrors.value.user_id" class="field-error" role="alert">{{ request.fieldErrors.value.user_id }}</span>
    </fieldset>

    <UserAccessFields
      v-model:role="form.role_key"
      v-model:scope="form.site_scope"
      v-model:site-ids="form.site_ids"
      :sites="company?.sites ?? []"
      :role-options="roleOptions"
      :errors="request.fieldErrors.value"
      :disabled="request.busy.value"
    />

    <InlineAlert :message="request.error.value" />
    <button class="btn btn-primary btn-block" type="submit" :disabled="request.busy.value || !app.canReachServer">
      {{ request.busy.value ? 'Enregistrement' : 'Donner l’accès' }}
    </button>
  </form>
</template>

<style scoped>
.people {
  display: grid;
  gap: 6px;
  margin-top: 8px;
}

.person {
  display: flex;
  align-items: center;
  gap: 12px;
  min-height: 56px;
  padding: 8px 12px;
  border: 1px solid var(--line);
  border-radius: var(--radius-control);
  cursor: pointer;
}

.person.is-selected {
  border-color: var(--accent);
  background: var(--accent-soft);
}

.person > span {
  display: grid;
  gap: 2px;
  min-width: 0;
}

.person .text-small {
  overflow-wrap: anywhere;
}
</style>
