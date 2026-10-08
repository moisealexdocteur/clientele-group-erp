<script setup lang="ts">
import { computed } from 'vue'
import type { CarRentalReservationListEntry } from '../../api/types'
import { reservationStateLabels, reservationStateTones } from '../../lib/labels'
import { formatShortDate, formatTime } from '../../lib/time'
import { vehicleName, vehiclePlate } from '../../lib/text'
import StatusPill from '../ui/StatusPill.vue'

/*
 * Ligne de réservation ouvrable depuis toutes les listes.
 * L'heure affichée dépend du moment utile : départ pour une réservation,
 * retour pour une location en cours.
 */
const props = withDefaults(defineProps<{
  entry: CarRentalReservationListEntry
  /** Heure à mettre en avant. */
  focus?: 'pickup' | 'due'
  overdue?: boolean
}>(), {
  focus: 'pickup',
  overdue: false,
})

const instant = computed(() => (props.focus === 'due' ? props.entry.due_at : props.entry.pickup_at))
</script>

<template>
  <RouterLink class="list-row reservation-row" :to="{ name: 'rental.reservation', params: { reservationId: entry.id } }">
    <span class="reservation-when" :class="{ overdue }">
      <span class="display display-sm">{{ formatTime(instant) }}</span>
      <span class="text-small">{{ formatShortDate(instant) }}</span>
    </span>
    <span class="reservation-main">
      <span class="reservation-line">
        <strong class="reservation-number">{{ entry.number }}</strong>
        <StatusPill v-if="overdue" tone="danger" label="Retour en retard" />
        <StatusPill v-else :tone="reservationStateTones[entry.state]" :label="reservationStateLabels[entry.state]" />
      </span>
      <span class="text-secondary">{{ vehicleName(entry.vehicle) }}<template v-if="entry.vehicle"> - {{ vehiclePlate(entry.vehicle) }}</template></span>
      <span class="text-muted text-small">{{ entry.customer?.display_name ?? 'Client non disponible' }}</span>
    </span>
    <span class="chevron" aria-hidden="true"></span>
  </RouterLink>
</template>

<style scoped>
.reservation-row {
  grid-template-columns: 96px minmax(0, 1fr) auto;
  gap: 14px;
}

.reservation-when {
  display: grid;
  gap: 4px;
  white-space: nowrap;
  color: var(--ink);
}

.reservation-when .display {
  font-size: 1.0625rem;
}

.reservation-when .text-small {
  color: var(--ink-3);
}

.reservation-when.overdue {
  color: var(--danger);
}

.reservation-main {
  display: grid;
  gap: 3px;
  min-width: 0;
}

.reservation-line {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 6px 10px;
}

.reservation-number {
  font-weight: 800;
  font-stretch: 112%;
  font-variant-numeric: tabular-nums;
}
</style>
