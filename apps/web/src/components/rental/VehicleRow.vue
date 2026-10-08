<script setup lang="ts">
import type { RentalVehicle } from '../../api/types'
import { categoryLabels, vehicleStatusLabels, vehicleStatusTones } from '../../lib/labels'
import { formatMoney } from '../../lib/money'
import { vehicleName, vehiclePlate } from '../../lib/text'
import StatusPill from '../ui/StatusPill.vue'
import VehicleThumb from './VehicleThumb.vue'

defineProps<{ vehicle: RentalVehicle }>()
</script>

<template>
  <RouterLink class="list-row vehicle-row" :to="{ name: 'rental.vehicle', params: { vehicleId: vehicle.id } }">
    <VehicleThumb :vehicle="vehicle" :alt="vehicleName(vehicle)" />
    <span class="vehicle-main">
      <span class="vehicle-line">
        <span class="plate">{{ vehiclePlate(vehicle) }}</span>
        <StatusPill :tone="vehicleStatusTones[vehicle.operational_status]" :label="vehicleStatusLabels[vehicle.operational_status]" />
        <StatusPill v-if="!vehicle.is_active" tone="neutral" label="Inactif" />
      </span>
      <strong>{{ vehicleName(vehicle) }}</strong>
      <span class="text-muted text-small">
        {{ categoryLabels[vehicle.category] }} - {{ formatMoney(vehicle.daily_rate_usd, 'USD') }} par jour - {{ vehicle.site?.name ?? 'Adresse non disponible' }}
      </span>
    </span>
    <span class="chevron" aria-hidden="true"></span>
  </RouterLink>
</template>

<style scoped>
.vehicle-row {
  grid-template-columns: 72px minmax(0, 1fr) auto;
  gap: 14px;
}

.vehicle-main {
  display: grid;
  gap: 4px;
  min-width: 0;
}

.vehicle-line {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 6px 8px;
}
</style>
