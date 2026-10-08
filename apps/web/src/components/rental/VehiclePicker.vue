<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { fetchAvailability } from '../../api/carRental'
import type { RentalCategory, RentalVehicle } from '../../api/types'
import { useRequest } from '../../composables/useRequest'
import { categoryLabels } from '../../lib/labels'
import { formatMoney } from '../../lib/money'
import { plural, vehicleName, vehiclePlate } from '../../lib/text'
import InlineAlert from '../ui/InlineAlert.vue'
import VehicleThumb from './VehicleThumb.vue'

/*
 * Véhicules disponibles pour une période et un bureau, chargés
 * immédiatement puis actualisés à chaque changement de critère.
 * Le véhicule déjà attribué (modification) reste toujours proposé.
 */
const props = defineProps<{
  siteId: string
  pickupAt: string
  dueAt: string
  periodValid: boolean
  /** Véhicule actuellement attribué, proposé même s'il n'apparaît pas libre. */
  currentVehicle?: RentalVehicle | null
  error?: string
}>()

const selected = defineModel<string>({ required: true })
const emit = defineEmits<{ select: [vehicle: RentalVehicle | null] }>()

const category = ref<'' | RentalCategory>('')
const vehicles = ref<RentalVehicle[]>([])
const request = useRequest()

const categories: Array<{ value: '' | RentalCategory; label: string }> = [
  { value: '', label: 'Toutes' },
  ...(Object.keys(categoryLabels) as RentalCategory[]).map((value) => ({ value, label: categoryLabels[value] })),
]

const options = computed(() => {
  const list = [...vehicles.value]
  const current = props.currentVehicle
  if (current && !list.some((vehicle) => vehicle.id === current.id) && (!category.value || current.category === category.value)) {
    list.unshift(current)
  }
  return list
})

let timer: number | undefined
let token = 0

async function load(): Promise<void> {
  if (!props.siteId || !props.periodValid) {
    vehicles.value = []
    return
  }
  const current = ++token
  const result = await request.run(() => fetchAvailability({
    site_id: props.siteId,
    pickup_at: props.pickupAt,
    due_at: props.dueAt,
    category: category.value || undefined,
  }))
  if (current !== token) return
  vehicles.value = result?.data ?? []
  if (selected.value && !options.value.some((vehicle) => vehicle.id === selected.value)) {
    selected.value = ''
    emit('select', null)
  }
}

function schedule(): void {
  window.clearTimeout(timer)
  timer = window.setTimeout(() => void load(), 300)
}

watch(() => [props.siteId, props.pickupAt, props.dueAt, category.value], schedule)
onMounted(() => void load())
onBeforeUnmount(() => window.clearTimeout(timer))

function bookable(vehicle: RentalVehicle): boolean {
  return vehicle.daily_rate_usd !== null && vehicle.minimum_security_deposit_usd !== null
}

function choose(vehicle: RentalVehicle): void {
  if (!bookable(vehicle)) return
  selected.value = vehicle.id
  emit('select', vehicle)
}

defineExpose({ reload: load })
</script>

<template>
  <div class="picker">
    <div class="segmented" role="group" aria-label="Catégorie">
      <button v-for="item in categories" :key="item.value" type="button" :aria-pressed="category === item.value" @click="category = item.value">
        {{ item.label }}
      </button>
    </div>

    <p class="picker-status" aria-live="polite">
      <template v-if="request.busy.value">Recherche des véhicules disponibles</template>
      <template v-else-if="periodValid">{{ options.length ? `${plural(options.length, 'véhicule disponible', 'véhicules disponibles')} sur cette période` : 'Aucun véhicule disponible sur cette période' }}</template>
    </p>
    <InlineAlert :message="request.error.value" />

    <div v-if="request.busy.value && !options.length" class="skeleton" style="height: 152px"></div>

    <div v-else-if="options.length" class="options" role="radiogroup" aria-label="Véhicules disponibles">
      <button
        v-for="vehicle in options"
        :key="vehicle.id"
        type="button"
        role="radio"
        class="option"
        :aria-checked="selected === vehicle.id"
        :disabled="!bookable(vehicle)"
        @click="choose(vehicle)"
      >
        <VehicleThumb :vehicle="vehicle" :alt="vehicleName(vehicle)" />
        <span class="option-text">
          <strong>{{ vehicleName(vehicle) }}</strong>
          <span class="option-line">
            <span class="plate">{{ vehiclePlate(vehicle) }}</span>
            <span class="text-muted text-small">{{ categoryLabels[vehicle.category] }}</span>
          </span>
          <span v-if="bookable(vehicle)" class="text-small text-secondary">
            {{ formatMoney(vehicle.daily_rate_usd, 'USD') }} par jour - dépôt {{ formatMoney(vehicle.minimum_security_deposit_usd, 'USD') }}
          </span>
          <span v-else class="text-small field-error">Tarif ou dépôt à configurer dans la fiche</span>
        </span>
        <span class="radio-mark" aria-hidden="true"></span>
      </button>
    </div>

    <div v-else-if="periodValid && !request.busy.value && !request.error.value" class="empty">
      <p>Essayez une autre catégorie, une autre période ou un autre bureau.</p>
    </div>
    <span v-if="error" class="field-error" role="alert">{{ error }}</span>
  </div>
</template>

<style scoped>
.picker {
  display: grid;
  gap: 12px;
}

.picker-status {
  min-height: 1.4em;
  font-size: var(--text-sm);
  font-weight: 600;
  color: var(--ink-2);
}

.options {
  display: grid;
  gap: 8px;
}

.option {
  display: grid;
  grid-template-columns: 72px minmax(0, 1fr) 20px;
  align-items: center;
  gap: 12px;
  min-height: 76px;
  padding: 10px 12px;
  border: 1px solid var(--line-strong);
  border-radius: var(--radius-control);
  background: var(--surface);
  font-size: var(--text-sm);
  text-align: left;
  cursor: pointer;
}

.option:hover:not(:disabled) {
  background: var(--surface-hover);
}

.option[aria-checked='true'] {
  border: 2px solid var(--accent);
  padding: 9px 11px;
  background: var(--accent-soft);
}

.option:disabled {
  cursor: not-allowed;
  opacity: 0.6;
}

.option-text {
  display: grid;
  justify-items: start;
  gap: 2px;
  min-width: 0;
}

.option-line {
  display: flex;
  align-items: center;
  gap: 8px;
}

.radio-mark {
  width: 20px;
  height: 20px;
  border: 1px solid var(--stroke-accessible);
  border-radius: 50%;
  background: var(--surface);
}

.option[aria-checked='true'] .radio-mark {
  border-color: var(--accent);
  box-shadow: inset 0 0 0 4px var(--surface), inset 0 0 0 10px var(--accent);
}

@media (min-width: 640px) {
  .options {
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
  }
}
</style>
