<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { fetchReservations } from '../../api/carRental'
import type { CarRentalReservationListEntry, ReservationState } from '../../api/types'
import { useSessionStore } from '../../stores/session'
import { useRequest } from '../../composables/useRequest'
import { defaultMonthPeriod, formatDate } from '../../lib/time'
import { reservationStateLabels } from '../../lib/labels'
import { plural } from '../../lib/text'
import PageHeader from '../../components/ui/PageHeader.vue'
import InlineAlert from '../../components/ui/InlineAlert.vue'
import AppIcon from '../../components/ui/AppIcon.vue'
import ReservationRow from '../../components/rental/ReservationRow.vue'

/*
 * Liste des réservations. Le mois courant du bureau actif est chargé à
 * l'ouverture ; les filtres sont conservés dans l'adresse de la page pour
 * que le bouton Retour ramène exactement à la même liste.
 */
const session = useSessionStore()
const route = useRoute()
const router = useRouter()
const request = useRequest()

const defaults = defaultMonthPeriod()
const states: Array<{ value: '' | ReservationState; label: string }> = [
  { value: '', label: 'Toutes' },
  { value: 'reserved', label: reservationStateLabels.reserved },
  { value: 'checked_out', label: reservationStateLabels.checked_out },
  { value: 'completed', label: reservationStateLabels.completed },
  { value: 'cancelled', label: reservationStateLabels.cancelled },
]

function queryString(key: string): string {
  const value = route.query[key]
  return typeof value === 'string' ? value : ''
}

const filters = reactive({
  state: queryString('etat') as '' | ReservationState,
  query: queryString('q'),
  from: queryString('du') || defaults.from,
  to: queryString('au') || defaults.to,
  site_id: queryString('bureau') || session.officeSiteId,
})

const periodOpen = ref(false)
const results = ref<CarRentalReservationListEntry[]>([])
const canCreate = computed(() => session.can('rental.reservations.create'))
const periodLabel = computed(() => `Du ${formatDate(filters.from)} au ${formatDate(filters.to)}`)

let searchTimer: number | undefined

async function load(): Promise<void> {
  void router.replace({
    query: {
      etat: filters.state || undefined,
      q: filters.query || undefined,
      du: filters.from !== defaults.from ? filters.from : undefined,
      au: filters.to !== defaults.to ? filters.to : undefined,
      bureau: filters.site_id && filters.site_id !== session.officeSiteId ? filters.site_id : undefined,
    },
  })
  const result = await request.run(() => fetchReservations({
    from: filters.from,
    to: filters.to,
    site_id: filters.site_id || undefined,
    state: filters.state,
    query: filters.query.trim() || undefined,
  }))
  results.value = result?.data ?? []
}

function setState(state: '' | ReservationState): void {
  filters.state = state
  void load()
}

watch(() => filters.query, () => {
  window.clearTimeout(searchTimer)
  searchTimer = window.setTimeout(() => void load(), 350)
})

function resetPeriod(): void {
  filters.from = defaults.from
  filters.to = defaults.to
  periodOpen.value = false
  void load()
}

onMounted(load)
</script>

<template>
  <PageHeader title="Réservations">
    <template #actions>
      <RouterLink v-if="canCreate" class="btn btn-primary" :to="{ name: 'rental.reservation.new' }">
        <AppIcon name="plus" />Nouvelle réservation
      </RouterLink>
    </template>
  </PageHeader>

  <div class="stack">
    <div class="filters">
      <label class="search">
        <span class="visually-hidden">Référence ou plaque</span>
        <AppIcon name="search" />
        <input v-model.trim="filters.query" class="input" type="search" inputmode="search" maxlength="32" placeholder="Référence ou plaque" />
      </label>

      <div class="segmented" role="group" aria-label="État">
        <button v-for="item in states" :key="item.value" type="button" :aria-pressed="filters.state === item.value" @click="setState(item.value)">
          {{ item.label }}
        </button>
      </div>

      <div class="period-line">
        <button class="btn btn-ghost" type="button" :aria-expanded="periodOpen" @click="periodOpen = !periodOpen">{{ periodLabel }}</button>
        <select v-if="session.sites.length > 1" v-model="filters.site_id" class="select site-select" aria-label="Bureau" @change="load">
          <option value="">Tous les bureaux autorisés</option>
          <option v-for="site in session.sites" :key="site.id" :value="site.id">{{ site.name }}</option>
        </select>
      </div>

      <div v-if="periodOpen" class="period-fields grid-2">
        <label class="field">
          <span class="field-label">Du</span>
          <input v-model="filters.from" class="input" type="date" required @change="load" />
        </label>
        <label class="field">
          <span class="field-label">Au</span>
          <input v-model="filters.to" class="input" type="date" required @change="load" />
        </label>
        <button class="btn btn-secondary" type="button" @click="resetPeriod">Revenir au mois en cours</button>
      </div>
    </div>

    <InlineAlert :message="request.error.value" />

    <p class="text-muted text-small" aria-live="polite">
      {{ request.busy.value ? 'Chargement' : plural(results.length, 'réservation') }}
      <template v-if="results.length === 50"> (les 50 plus récentes : affinez la recherche)</template>
    </p>

    <div v-if="request.busy.value && !results.length" class="skeleton" style="height: 320px"></div>

    <div v-else-if="results.length" class="list">
      <ReservationRow
        v-for="entry in results"
        :key="entry.id"
        :entry="entry"
        :focus="entry.state === 'checked_out' ? 'due' : 'pickup'"
      />
    </div>

    <div v-else-if="!request.busy.value" class="empty">
      <p>Aucune réservation ne correspond à ces critères.</p>
      <RouterLink v-if="canCreate" class="btn btn-primary" :to="{ name: 'rental.reservation.new' }">Nouvelle réservation</RouterLink>
    </div>
  </div>
</template>

<style scoped>
.filters {
  display: grid;
  gap: 12px;
}

.search {
  position: relative;
  display: block;
}

.search :deep(.icon) {
  position: absolute;
  top: 50%;
  left: 14px;
  color: var(--ink-3);
  transform: translateY(-50%);
  pointer-events: none;
}

.search .input {
  padding-left: 46px;
  min-height: 52px;
  font-size: var(--text-lg);
}

.period-line {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}

.period-line .btn-ghost {
  margin-left: -12px;
}

.site-select {
  width: auto;
  min-width: 220px;
}

.period-fields {
  align-items: end;
}
</style>
