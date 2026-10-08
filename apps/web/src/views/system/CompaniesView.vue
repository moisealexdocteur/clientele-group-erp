<script setup lang="ts">
import { onMounted } from 'vue'
import { useSystemStore } from '../../stores/system'
import { useRequest } from '../../composables/useRequest'
import { plural } from '../../lib/text'
import PageHeader from '../../components/ui/PageHeader.vue'
import InlineAlert from '../../components/ui/InlineAlert.vue'
import StatusPill from '../../components/ui/StatusPill.vue'

const system = useSystemStore()
const request = useRequest()

onMounted(() => {
  void request.run(() => system.load())
})

function registerCount(sites: Array<{ cash_registers: unknown[] }>): number {
  return sites.reduce((total, site) => total + site.cash_registers.length, 0)
}
</script>

<template>
  <PageHeader title="Sociétés" description="Sociétés, adresses et caisses. Chaque modification est journalisée.">
    <template #actions>
      <RouterLink class="btn btn-primary" :to="{ name: 'system.company.new' }">Ajouter une société</RouterLink>
    </template>
  </PageHeader>

  <InlineAlert :message="request.error.value" />

  <div v-if="request.busy.value && !system.companies.length" class="skeleton" style="height: 220px"></div>

  <div v-else-if="system.companies.length" class="list">
    <RouterLink
      v-for="company in system.companies"
      :key="company.id"
      class="list-row"
      :to="{ name: 'system.company', params: { companyId: company.id } }"
    >
      <span class="stack" style="gap: 4px">
        <strong class="title-section">{{ company.display_name }}</strong>
        <span class="text-muted text-small">
          {{ company.code }} - {{ plural(company.sites.length, 'adresse') }} - {{ plural(registerCount(company.sites), 'caisse') }} - {{ company.base_currency }}
        </span>
      </span>
      <span class="row-end">
        <StatusPill v-if="!company.is_active" tone="neutral" label="Inactive" />
        <span class="chevron" aria-hidden="true"></span>
      </span>
    </RouterLink>
  </div>

  <div v-else-if="!request.busy.value" class="empty">
    <p>Aucune société n’est encore enregistrée.</p>
    <RouterLink class="btn btn-primary" :to="{ name: 'system.company.new' }">Ajouter une société</RouterLink>
  </div>
</template>

<style scoped>
.row-end {
  display: flex;
  align-items: center;
  gap: 12px;
}
</style>
