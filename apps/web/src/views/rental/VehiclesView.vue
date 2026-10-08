<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { fetchVehicles } from '../../api/carRental'
import type { RentalVehicle, VehicleOperationalStatus } from '../../api/types'
import { useSessionStore } from '../../stores/session'
import { useRequest } from '../../composables/useRequest'
import { vehicleStatusLabels } from '../../lib/labels'
import { normalizeForSearch, plural, vehicleName, vehiclePlate } from '../../lib/text'
import PageHeader from '../../components/ui/PageHeader.vue'
import InlineAlert from '../../components/ui/InlineAlert.vue'
import AppIcon from '../../components/ui/AppIcon.vue'
import VehicleRow from '../../components/rental/VehicleRow.vue'

const session = useSessionStore()
const route = useRoute()
const router = useRouter()
const request = useRequest()

const vehicles = ref<RentalVehicle[]>([])
const filters = reactive({
  status: (typeof route.query.etat === 'string' ? route.query.etat : '') as '' | VehicleOperationalStatus,
  site_id: typeof route.query.bureau === 'string' ? route.query.bureau : '',
  query: '',
})

const canManage = computed(() => session.can('rental.vehicles.manage'))

const statuses = computed(() => [
  { value: '' as const, label: 'Tous', count: vehicles.value.length },
  ...(Object.keys(vehicleStatusLabels) as VehicleOperationalStatus[]).map((value) => ({
    value,
    label: vehicleStatusLabels[value],
    count: vehicles.value.filter((vehicle) => vehicle.operational_status === value).length,
  })),
])

const visible = computed(() => {
  const term = normalizeForSearch(filters.query)
  return vehicles.value
    .filter((vehicle) => !filters.status || vehicle.operational_status === filters.status)
    .filter((vehicle) => !term || normalizeForSearch(`${vehiclePlate(vehicle)} ${vehicleName(vehicle)}`).includes(term))
    .sort((left, right) => Number(right.is_active) - Number(left.is_active) || vehiclePlate(left).localeCompare(vehiclePlate(right), 'fr'))
})

async function load(): Promise<void> {
  const result = await request.run(() => fetchVehicles({ site_id: filters.site_id || undefined }))
  vehicles.value = result?.data ?? []
}

function setStatus(status: '' | VehicleOperationalStatus): void {
  filters.status = status
  void router.replace({ query: { ...route.query, etat: status || undefined } })
}

function setSite(): void {
  void router.replace({ query: { ...route.query, bureau: filters.site_id || undefined } })
  void load()
}

onMounted(load)
</script>

<template>
  <PageHeader title="Véhicules">
    <template #actions>
      <RouterLink v-if="canManage" class="btn btn-primary" :to="{ name: 'rental.vehicle.new' }">
        <AppIcon name="plus" />Ajouter un véhicule
      </RouterLink>
    </template>
  </PageHeader>

  <div class="stack">
    <div class="filters">
      <input v-model.trim="filters.query" class="input" type="search" inputmode="search" placeholder="Plaque ou modèle" aria-label="Plaque ou modèle" />
      <select v-if="session.sites.length > 1" v-model="filters.site_id" class="select" aria-label="Bureau" @change="setSite">
        <option value="">Tous les bureaux autorisés</option>
        <option v-for="site in session.sites" :key="site.id" :value="site.id">{{ site.name }}</option>
      </select>
    </div>

    <div class="segmented" role="group" aria-label="État opérationnel">
      <button v-for="item in statuses" :key="item.value" type="button" :aria-pressed="filters.status === item.value" @click="setStatus(item.value)">
        {{ item.label }} <span class="count">{{ item.count }}</span>
      </button>
    </div>

    <InlineAlert :message="request.error.value" />
    <p class="text-muted text-small">{{ request.busy.value ? 'Chargement' : plural(visible.length, 'véhicule') }}</p>

    <div v-if="request.busy.value && !vehicles.length" class="skeleton" style="height: 320px"></div>
    <div v-else-if="visible.length" class="list">
      <VehicleRow v-for="vehicle in visible" :key="vehicle.id" :vehicle="vehicle" />
    </div>
    <div v-else-if="!request.busy.value" class="empty">
      <p>{{ vehicles.length ? 'Aucun véhicule ne correspond à ces critères.' : 'Aucun véhicule n’est encore enregistré.' }}</p>
      <RouterLink v-if="canManage && !vehicles.length" class="btn btn-primary" :to="{ name: 'rental.vehicle.new' }">Ajouter un véhicule</RouterLink>
    </div>
  </div>
</template>

<style scoped>
.filters {
  display: grid;
  gap: 10px;
}

.count {
  margin-left: 4px;
  color: var(--ink-3);
  font-variant-numeric: tabular-nums;
}

@media (min-width: 720px) {
  .filters {
    grid-template-columns: minmax(0, 1fr) 280px;
  }
}
</style>
