<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { fetchReservations, fetchVehicles } from '../../api/carRental'
import type { CarRentalReservationListEntry, RentalVehicle, VehicleOperationalStatus } from '../../api/types'
import { useSessionStore } from '../../stores/session'
import { useAppStore } from '../../stores/app'
import { useRequest } from '../../composables/useRequest'
import { defaultMonthPeriod, formatDayHeading, isOnBusinessDay, toDateInput } from '../../lib/time'
import { vehicleStatusLabels } from '../../lib/labels'
import { plural } from '../../lib/text'
import InlineAlert from '../../components/ui/InlineAlert.vue'
import AppIcon from '../../components/ui/AppIcon.vue'
import ReservationRow from '../../components/rental/ReservationRow.vue'
import OfficePicker from '../../components/rental/OfficePicker.vue'

/*
 * Écran d'accueil du comptoir : ce qui part, ce qui revient et ce qui est en
 * retard aujourd'hui, pour le bureau actif. Aucune action n'est nécessaire
 * pour voir ces données.
 */
const session = useSessionStore()
const app = useAppStore()
const request = useRequest()

const reservations = ref<CarRentalReservationListEntry[]>([])
const vehicles = ref<RentalVehicle[]>([])

const canReadReservations = computed(() => session.can('rental.reservations.read'))
const canCreate = computed(() => session.can('rental.reservations.create'))
const canReadVehicles = computed(() => session.can('rental.vehicles.read'))

const today = computed(() => toDateInput(app.now))
const dayHeading = computed(() => formatDayHeading(app.now))

const departures = computed(() =>
  reservations.value
    .filter((entry) => entry.state === 'reserved' && isOnBusinessDay(entry.pickup_at, today.value))
    .sort((left, right) => left.pickup_at.localeCompare(right.pickup_at)),
)

const overdue = computed(() =>
  reservations.value
    .filter((entry) => entry.state === 'checked_out' && new Date(entry.due_at) < app.now)
    .sort((left, right) => left.due_at.localeCompare(right.due_at)),
)

const returns = computed(() =>
  reservations.value
    .filter((entry) => entry.state === 'checked_out' && isOnBusinessDay(entry.due_at, today.value) && new Date(entry.due_at) >= app.now)
    .sort((left, right) => left.due_at.localeCompare(right.due_at)),
)

const lateDepartures = computed(() =>
  reservations.value.filter((entry) => entry.state === 'reserved' && toDateInput(entry.pickup_at) < today.value),
)

const fleetCounts = computed(() => {
  const counts = new Map<VehicleOperationalStatus, number>()
  for (const vehicle of vehicles.value.filter((item) => item.is_active)) {
    counts.set(vehicle.operational_status, (counts.get(vehicle.operational_status) ?? 0) + 1)
  }
  return (Object.keys(vehicleStatusLabels) as VehicleOperationalStatus[])
    .map((status) => ({ status, label: vehicleStatusLabels[status], count: counts.get(status) ?? 0 }))
})

async function load(): Promise<void> {
  await request.run(async () => {
    const period = defaultMonthPeriod(app.now)
    // On remonte d'un mois pour voir les locations en cours commencées le mois précédent.
    const from = new Date(`${period.from}T12:00:00Z`)
    from.setUTCMonth(from.getUTCMonth() - 1)
    const [list, fleet] = await Promise.all([
      canReadReservations.value
        ? fetchReservations({ from: toDateInput(from), to: period.to, site_id: session.officeSiteId || undefined })
        : Promise.resolve({ data: [] }),
      canReadVehicles.value
        ? fetchVehicles({ site_id: session.officeSiteId || undefined })
        : Promise.resolve({ data: [] }),
    ])
    reservations.value = list.data
    vehicles.value = fleet.data
  })
}

onMounted(load)
</script>

<template>
  <div class="today stack-lg">
    <header class="today-header">
      <h1 class="display display-xl today-day">{{ dayHeading }}</h1>
      <OfficePicker @change="load" />
      <div class="today-actions">
        <RouterLink v-if="canCreate" class="btn btn-primary" :to="{ name: 'rental.reservation.new' }">
          <AppIcon name="plus" />Nouvelle réservation
        </RouterLink>
        <RouterLink v-if="canReadReservations" class="btn btn-secondary" :to="{ name: 'rental.reservations' }">
          <AppIcon name="search" />Rechercher
        </RouterLink>
      </div>
    </header>

    <InlineAlert :message="request.error.value" />

    <template v-if="canReadReservations">
      <div v-if="request.busy.value && !reservations.length" class="skeleton" style="height: 260px"></div>

      <template v-else>
        <section v-if="overdue.length" class="stack" aria-labelledby="overdue-title">
          <h2 id="overdue-title" class="section-title danger">
            <span class="display display-md">{{ overdue.length }}</span> {{ overdue.length > 1 ? 'retours en retard' : 'retour en retard' }}
          </h2>
          <div class="list">
            <ReservationRow v-for="entry in overdue" :key="entry.id" :entry="entry" focus="due" overdue />
          </div>
        </section>

        <section class="stack" aria-labelledby="departures-title">
          <h2 id="departures-title" class="section-title">
            <span class="display display-md">{{ departures.length }}</span> {{ departures.length > 1 ? 'départs aujourd’hui' : 'départ aujourd’hui' }}
          </h2>
          <div v-if="departures.length" class="list">
            <ReservationRow v-for="entry in departures" :key="entry.id" :entry="entry" focus="pickup" />
          </div>
          <p v-else class="text-muted">Aucun départ prévu aujourd’hui pour ce bureau.</p>
        </section>

        <section class="stack" aria-labelledby="returns-title">
          <h2 id="returns-title" class="section-title">
            <span class="display display-md">{{ returns.length }}</span> {{ returns.length > 1 ? 'retours attendus' : 'retour attendu' }}
          </h2>
          <div v-if="returns.length" class="list">
            <ReservationRow v-for="entry in returns" :key="entry.id" :entry="entry" focus="due" />
          </div>
          <p v-else class="text-muted">Aucun autre retour attendu aujourd’hui.</p>
        </section>

        <InlineAlert
          v-if="lateDepartures.length"
          tone="warning"
          :message="`${plural(lateDepartures.length, 'réservation')} non ${lateDepartures.length > 1 ? 'remises' : 'remise'} après la date de départ. Ouvrez-les pour mettre en circulation ou annuler.`"
        />
      </template>
    </template>

    <section v-if="canReadVehicles && vehicles.length" class="stack" aria-labelledby="fleet-title">
      <div class="panel-header">
        <h2 id="fleet-title" class="title-section">Flotte du bureau</h2>
        <RouterLink class="btn btn-ghost" :to="{ name: 'rental.vehicles' }">Voir les véhicules</RouterLink>
      </div>
      <ul class="fleet-strip">
        <li v-for="item in fleetCounts" :key="item.status" :class="{ zero: item.count === 0 }">
          <RouterLink :to="{ name: 'rental.vehicles', query: { etat: item.status } }">
            <span class="display display-lg">{{ item.count }}</span>
            <span class="text-small">{{ item.label }}</span>
          </RouterLink>
        </li>
      </ul>
    </section>
  </div>
</template>

<style scoped>
.today-header {
  display: grid;
  gap: 16px;
}

.today-day {
  max-width: 14ch;
}

.today-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
}

.today-actions .btn {
  flex: 1 1 180px;
}

.section-title {
  display: flex;
  align-items: baseline;
  gap: 10px;
  font-size: var(--text-lg);
  font-weight: 700;
}

.section-title.danger {
  color: var(--danger);
}

.fleet-strip {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(118px, 1fr));
  gap: 1px;
  overflow: hidden;
  border-radius: var(--radius-panel);
  background: var(--line);
}

.fleet-strip a {
  display: grid;
  gap: 8px;
  min-height: 96px;
  padding: 16px;
  color: var(--ink);
  background: var(--surface);
  text-decoration: none;
}

.fleet-strip li.zero a {
  color: var(--ink-3);
}

@media (min-width: 720px) {
  .today-actions .btn {
    flex: 0 0 auto;
  }
}
</style>
