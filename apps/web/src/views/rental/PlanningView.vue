<script setup lang="ts">
import { computed, nextTick, onMounted, ref } from 'vue'
import { fetchCalendar } from '../../api/carRental'
import type { CarRentalCalendarEntry, RentalVehicle } from '../../api/types'
import { useSessionStore } from '../../stores/session'
import { useAppStore } from '../../stores/app'
import { useRequest } from '../../composables/useRequest'
import { defaultMonthPeriod, formatDate, formatDateTime, toDateInput } from '../../lib/time'
import { reservationStateLabels, vehicleStatusLabels, vehicleStatusTones } from '../../lib/labels'
import { vehicleName, vehiclePlate } from '../../lib/text'
import PageHeader from '../../components/ui/PageHeader.vue'
import InlineAlert from '../../components/ui/InlineAlert.vue'
import StatusPill from '../../components/ui/StatusPill.vue'

/*
 * Planning global : une ligne par véhicule actif, une colonne par jour.
 * Les réservations sont des barres ouvrables. Aucune donnée client n'est
 * affichée dans ce planning.
 */
const session = useSessionStore()
const app = useAppStore()
const request = useRequest()

const initial = defaultMonthPeriod()
const from = ref(initial.from)
const to = ref(initial.to)
const siteId = ref('')
const entries = ref<CarRentalCalendarEntry[]>([])
const scroller = ref<HTMLElement | null>(null)
const vehicles = ref<RentalVehicle[]>([])

const DAY_MS = 24 * 60 * 60 * 1000

function dateFromInput(value: string): Date {
  const [year, month, day] = value.split('-').map(Number)
  return new Date(Date.UTC(year, month - 1, day))
}

function inputFromDate(date: Date): string {
  return date.toISOString().slice(0, 10)
}

const days = computed(() => {
  const start = dateFromInput(from.value)
  const end = dateFromInput(to.value)
  const result: Array<{ key: string; day: number; weekday: string; weekend: boolean }> = []
  for (let time = start.getTime(); time <= end.getTime() && result.length < 62; time += DAY_MS) {
    const date = new Date(time)
    result.push({
      key: inputFromDate(date),
      day: date.getUTCDate(),
      weekday: new Intl.DateTimeFormat('fr-FR', { timeZone: 'UTC', weekday: 'narrow' }).format(date),
      weekend: date.getUTCDay() === 0 || date.getUTCDay() === 6,
    })
  }
  return result
})

const todayKey = computed(() => toDateInput(app.now))
const periodLabel = computed(() => {
  const label = new Intl.DateTimeFormat('fr-FR', { timeZone: 'UTC', month: 'long', year: 'numeric' }).format(dateFromInput(from.value))
  return label.charAt(0).toUpperCase() + label.slice(1)
})

/** Position d'une réservation dans la grille, bornée à la période affichée. */
function placement(entry: CarRentalCalendarEntry): { start: number; end: number } | null {
  const first = days.value[0]?.key
  const last = days.value[days.value.length - 1]?.key
  if (!first || !last) return null
  const startKey = toDateInput(entry.pickup_at)
  const endKey = toDateInput(entry.due_at)
  if (endKey < first || startKey > last) return null
  const startIndex = Math.max(0, days.value.findIndex((day) => day.key === (startKey < first ? first : startKey)))
  const endIndex = days.value.findIndex((day) => day.key === (endKey > last ? last : endKey))
  return { start: startIndex + 1, end: (endIndex < 0 ? days.value.length - 1 : endIndex) + 2 }
}

const rows = computed(() =>
  vehicles.value.map((vehicle) => ({
    vehicle,
    bars: entries.value
      .filter((entry) => entry.vehicle?.id === vehicle.id)
      .map((entry) => ({ entry, place: placement(entry) }))
      .filter((bar): bar is { entry: CarRentalCalendarEntry; place: { start: number; end: number } } => bar.place !== null),
  })),
)

async function load(): Promise<void> {
  const result = await request.run(() => fetchCalendar({ from: from.value, to: to.value, site_id: siteId.value || undefined }))
  entries.value = result?.data ?? []
  vehicles.value = result?.vehicles ?? []
  await nextTick()
  scrollToToday()
}

/* Sur téléphone, la grille s'ouvre sur le jour courant plutôt que sur le 1er du mois. */
function scrollToToday(): void {
  const element = scroller.value
  const index = days.value.findIndex((day) => day.key === todayKey.value)
  if (!element || index < 0) return
  const cell = element.querySelector<HTMLElement>('.day-cell')
  const width = cell?.offsetWidth ?? 40
  element.scrollLeft = Math.max(0, (index - 1) * width)
}

function shiftMonth(offset: number): void {
  const start = dateFromInput(from.value)
  const first = new Date(Date.UTC(start.getUTCFullYear(), start.getUTCMonth() + offset, 1))
  const last = new Date(Date.UTC(first.getUTCFullYear(), first.getUTCMonth() + 1, 0))
  from.value = inputFromDate(first)
  to.value = inputFromDate(last)
  void load()
}

function thisMonth(): void {
  const period = defaultMonthPeriod()
  from.value = period.from
  to.value = period.to
  void load()
}

function barTitle(entry: CarRentalCalendarEntry): string {
  return `Réservation ${entry.number} - ${reservationStateLabels[entry.state]} - du ${formatDateTime(entry.pickup_at)} au ${formatDateTime(entry.due_at)}`
}

onMounted(load)
</script>

<template>
  <PageHeader title="Planning" description="Réservations actives et état de la flotte. Les informations client ne sont pas affichées ici." />

  <div class="stack">
    <div class="planning-controls">
      <div class="month-nav">
        <button class="btn btn-secondary nav-button" type="button" aria-label="Mois précédent" @click="shiftMonth(-1)"><span class="arrow left"></span></button>
        <strong class="display display-sm month-label">{{ periodLabel }}</strong>
        <button class="btn btn-secondary nav-button" type="button" aria-label="Mois suivant" @click="shiftMonth(1)"><span class="arrow right"></span></button>
        <button class="btn btn-ghost" type="button" @click="thisMonth">Aujourd’hui</button>
      </div>
      <select v-if="session.sites.length > 1" v-model="siteId" class="select site-select" aria-label="Bureau" @change="load">
        <option value="">Tous les bureaux autorisés</option>
        <option v-for="site in session.sites" :key="site.id" :value="site.id">{{ site.name }}</option>
      </select>
    </div>
    <p class="text-muted text-small">Du {{ formatDate(from) }} au {{ formatDate(to) }}</p>

    <InlineAlert :message="request.error.value" />
    <div v-if="request.busy.value && !vehicles.length" class="skeleton" style="height: 360px"></div>

    <div v-else-if="vehicles.length" class="timeline" :style="{ '--days': days.length }">
      <div ref="scroller" class="timeline-scroll">
        <div class="timeline-head">
          <div class="corner">Véhicule</div>
          <div class="day-cells">
            <span
              v-for="day in days"
              :key="day.key"
              class="day-cell"
              :class="{ weekend: day.weekend, today: day.key === todayKey }"
            >
              <span class="weekday">{{ day.weekday }}</span>
              <span class="day-number">{{ day.day }}</span>
            </span>
          </div>
        </div>

        <div v-for="row in rows" :key="row.vehicle.id" class="timeline-row">
          <RouterLink class="row-label" :to="{ name: 'rental.vehicle', params: { vehicleId: row.vehicle.id } }">
            <span class="plate">{{ vehiclePlate(row.vehicle) }}</span>
            <span class="row-name">{{ vehicleName(row.vehicle) }}</span>
            <StatusPill :tone="vehicleStatusTones[row.vehicle.operational_status]" :label="vehicleStatusLabels[row.vehicle.operational_status]" />
          </RouterLink>
          <div class="track">
            <span
              v-for="(day, index) in days"
              :key="day.key"
              class="track-cell"
              :class="{ weekend: day.weekend, today: day.key === todayKey }"
              :style="{ gridColumn: index + 1 }"
            ></span>
            <RouterLink
              v-for="bar in row.bars"
              :key="bar.entry.id"
              class="bar"
              :class="`bar-${bar.entry.state}`"
              :style="{ gridColumn: `${bar.place.start} / ${bar.place.end}` }"
              :to="{ name: 'rental.reservation', params: { reservationId: bar.entry.id } }"
              :title="barTitle(bar.entry)"
              :aria-label="barTitle(bar.entry)"
            >
              {{ bar.entry.number }}
            </RouterLink>
          </div>
        </div>
      </div>
    </div>

    <div v-else-if="!request.busy.value" class="empty">
      <p>Aucun véhicule actif pour ce bureau.</p>
    </div>

    <ul class="legend text-small">
      <li><span class="swatch bar-reserved"></span>Réservée</li>
      <li><span class="swatch bar-checked_out"></span>En circulation</li>
    </ul>
  </div>
</template>

<style scoped>
.planning-controls {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}

.month-nav {
  display: flex;
  align-items: center;
  gap: 8px;
}

.month-label {
  min-width: 10ch;
  font-size: var(--text-md);
  text-align: center;
  white-space: nowrap;
}

.nav-button {
  width: var(--tap);
  padding: 0;
}

.arrow {
  width: 10px;
  height: 10px;
  border-top: 2.5px solid currentColor;
  border-left: 2.5px solid currentColor;
}

.arrow.left {
  transform: rotate(-45deg);
  margin-left: 4px;
}

.arrow.right {
  transform: rotate(135deg);
  margin-right: 4px;
}

.site-select {
  width: auto;
  min-width: 220px;
}

/* Grille : colonne véhicule figée, jours défilables au doigt. */
.timeline {
  --label-w: 150px;
  --day-w: 40px;
  overflow: hidden;
  border-radius: var(--radius-panel);
  background: var(--surface);
}

.timeline-scroll {
  overflow-x: auto;
  overscroll-behavior-x: contain;
  -webkit-overflow-scrolling: touch;
}

.timeline-head,
.timeline-row {
  display: grid;
  grid-template-columns: var(--label-w) calc(var(--days) * var(--day-w));
  width: max-content;
}

.corner,
.row-label {
  position: sticky;
  left: 0;
  z-index: 2;
  background: var(--surface);
  border-right: 1px solid var(--line);
}

.corner {
  display: flex;
  align-items: flex-end;
  padding: 10px 12px;
  color: var(--ink-3);
  font-size: var(--text-xs);
  font-weight: 600;
}

.day-cells,
.track {
  display: grid;
  grid-template-columns: repeat(var(--days), var(--day-w));
}

.day-cell {
  display: grid;
  justify-items: center;
  gap: 2px;
  padding: 8px 0;
  color: var(--ink-2);
}

.weekday {
  font-size: 0.7rem;
  color: var(--ink-3);
  text-transform: capitalize;
}

.day-number {
  font-weight: 600;
  font-variant-numeric: tabular-nums;
}

.day-cell.today .day-number {
  display: grid;
  place-items: center;
  width: 28px;
  height: 28px;
  border-radius: 50%;
  color: #fff;
  background: var(--brand);
}

.timeline-row {
  border-top: 1px solid var(--line);
}

.row-label {
  display: grid;
  align-content: center;
  justify-items: start;
  gap: 4px;
  min-height: 76px;
  padding: 10px 12px;
  color: var(--ink);
  text-decoration: none;
}

.row-label:hover {
  background: #f8f9fb;
}

.row-name {
  max-width: 100%;
  overflow: hidden;
  font-size: var(--text-xs);
  color: var(--ink-2);
  text-overflow: ellipsis;
  white-space: nowrap;
}

.track {
  position: relative;
  grid-template-rows: 1fr;
}

.track-cell {
  grid-row: 1;
  border-left: 1px solid #eef0f4;
}

.track-cell.weekend,
.day-cell.weekend {
  background: #f7f8fa;
}

.track-cell.today {
  background: var(--brand-soft);
}

.bar {
  grid-row: 1;
  align-self: center;
  z-index: 1;
  display: flex;
  align-items: center;
  min-height: 36px;
  margin: 0 2px;
  padding: 0 10px;
  overflow: hidden;
  border-radius: 4px;
  color: #fff;
  font-size: var(--text-xs);
  font-weight: 600;
  font-variant-numeric: tabular-nums;
  text-decoration: none;
  white-space: nowrap;
}

.bar-reserved {
  background: var(--accent);
}

.bar-checked_out {
  background: var(--warning);
}

.legend {
  display: flex;
  flex-wrap: wrap;
  gap: 16px;
  color: var(--ink-2);
}

.legend li {
  display: flex;
  align-items: center;
  gap: 8px;
}

.swatch {
  width: 22px;
  height: 12px;
  border-radius: 4px;
}

@media (min-width: 720px) {
  .timeline {
    --label-w: 210px;
    --day-w: 46px;
  }
}
</style>
