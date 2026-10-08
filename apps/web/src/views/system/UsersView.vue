<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { fetchCompanyUsers } from '../../api/system'
import type { SystemCompanyUser } from '../../api/types'
import { useSystemStore } from '../../stores/system'
import { useRequest } from '../../composables/useRequest'
import { roleLabel } from '../../lib/labels'
import { initials } from '../../lib/text'
import PageHeader from '../../components/ui/PageHeader.vue'
import InlineAlert from '../../components/ui/InlineAlert.vue'
import StatusPill from '../../components/ui/StatusPill.vue'

const props = defineProps<{ companyId: string }>()

const router = useRouter()
const system = useSystemStore()
const request = useRequest()
const users = ref<SystemCompanyUser[]>([])

const company = computed(() => system.company(props.companyId))

async function load(): Promise<void> {
  system.remember(props.companyId)
  const result = await request.run(async () => {
    await system.ensureLoaded()
    return fetchCompanyUsers(props.companyId)
  })
  users.value = (result?.data ?? []).slice().sort((left, right) => (left.name ?? '').localeCompare(right.name ?? '', 'fr'))
}

onMounted(load)
watch(() => props.companyId, load)

function changeCompany(event: Event): void {
  const companyId = (event.target as HTMLSelectElement).value
  void router.replace({ name: 'system.users', params: { companyId } })
}

function scopeText(user: SystemCompanyUser): string {
  return user.site_scope === 'all' ? 'Toutes les adresses' : user.sites.map((site) => site.name).join(', ')
}
</script>

<template>
  <PageHeader title="Utilisateurs" description="Création, modification, désactivation et suppression. Chaque action est journalisée.">
    <template #actions>
      <RouterLink class="btn btn-primary" :to="{ name: 'system.user.new', params: { companyId } }">Ajouter un utilisateur</RouterLink>
    </template>
  </PageHeader>

  <div class="stack">
    <label class="field">
      <span class="field-label">Société</span>
      <select class="select" :value="companyId" @change="changeCompany">
        <option v-for="item in system.companies" :key="item.id" :value="item.id">{{ item.display_name }}</option>
      </select>
    </label>

    <InlineAlert :message="request.error.value" />
    <div v-if="request.busy.value && !users.length" class="skeleton" style="height: 220px"></div>

    <div v-else-if="users.length" class="list">
      <component
        :is="user.is_system_owner ? 'div' : 'RouterLink'"
        v-for="user in users"
        :key="user.id"
        class="list-row user-row"
        :class="{ static: user.is_system_owner }"
        v-bind="user.is_system_owner ? {} : { to: { name: 'system.user', params: { companyId, accessId: user.id } } }"
      >
        <span class="user-main">
          <span class="user-avatar" aria-hidden="true">{{ initials(user.name) }}</span>
          <span class="stack" style="gap: 2px; min-width: 0">
            <strong>{{ user.name }}</strong>
            <span class="text-muted text-small ellipsis">{{ user.email }}</span>
            <span class="text-secondary text-small">{{ roleLabel(user.role_key) }} - {{ scopeText(user) }}</span>
          </span>
        </span>
        <span class="row-end">
          <StatusPill v-if="!user.is_active" tone="neutral" label="Désactivé" />
          <span v-if="!user.is_system_owner" class="chevron" aria-hidden="true"></span>
        </span>
      </component>
    </div>

    <div v-else-if="!request.busy.value" class="empty">
      <p>Aucun utilisateur n’est encore attribué à {{ company?.display_name ?? 'cette société' }}.</p>
      <RouterLink class="btn btn-primary" :to="{ name: 'system.user.new', params: { companyId } }">Ajouter un utilisateur</RouterLink>
    </div>

    <p class="field-help">Le compte propriétaire se gère depuis la sécurité de son propre compte.</p>
  </div>
</template>

<style scoped>
.user-main {
  display: flex;
  align-items: center;
  gap: 14px;
  min-width: 0;
}

.user-avatar {
  display: grid;
  flex: none;
  place-items: center;
  width: 44px;
  height: 44px;
  border-radius: 50%;
  background: var(--surface-sunken);
  font-weight: 750;
}

.ellipsis {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.row-end {
  display: flex;
  align-items: center;
  gap: 12px;
}

.user-row.static {
  cursor: default;
}
</style>
