<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { createReservation, fetchAvailability } from '../../api/carRental'
import type { CarRentalReservation, Currency, KilometerPlan, RentalCategory, RentalLocation, RentalVehicle } from '../../api/types'
import { useSessionStore } from '../../stores/session'
import { useAppStore } from '../../stores/app'
import { useRequest } from '../../composables/useRequest'
import { categoryLabels, locationLabels } from '../../lib/labels'
import { formatMoney, toAmount } from '../../lib/money'
import { defaultReservationSchedule, formatBusinessInput, formatDateTime, rentalDays } from '../../lib/time'
import { plural, vehicleName, vehiclePlate } from '../../lib/text'
import PageHeader from '../../components/ui/PageHeader.vue'
import FormField from '../../components/ui/FormField.vue'
import InlineAlert from '../../components/ui/InlineAlert.vue'
import SheetDialog from '../../components/ui/SheetDialog.vue'
import VehicleThumb from '../../components/rental/VehicleThumb.vue'

/*
 * Nouvelle réservation.
 *
 * Correctifs P0 de l'alpha.14 :
 * - les véhicules disponibles sont chargés dès l'ouverture avec les valeurs
 *   par défaut (maintenant, retour demain, bureau actif, toutes catégories) ;
 * - ils s'affichent dans le même panneau que les dates et sont actualisés
 *   automatiquement à chaque changement, sans bouton de recherche ;
 * - le résumé et le bouton d'enregistrement restent visibles au pouce sur téléphone.
 */
const AIRPORT_FEE_USD = 20

const session = useSessionStore()
const app = useAppStore()
const router = useRouter()
const availabilityRequest = useRequest()
const saveRequest = useRequest()

function initialForm() {
  const schedule = defaultReservationSchedule()
  return {
    site_id: session.officeSiteId,
    vehicle_id: '',
    category: '' as '' | RentalCategory,
    customer_type: 'individual' as 'individual' | 'institution',
    customer_name: '',
    customer_email: '',
    customer_phone: '',
    pickup_at: schedule.pickup_at,
    due_at: schedule.due_at,
    pickup_location_type: 'site' as RentalLocation,
    pickup_location_detail: '',
    dropoff_location_type: 'cap_haitien_airport' as RentalLocation,
    dropoff_location_detail: '',
    apply_airport_pickup_fee: false,
    apply_airport_dropoff_fee: false,
    currency: 'USD' as Currency,
    daily_rate: '',
    kilometer_plan: 'limited' as KilometerPlan,
    included_km: '300',
    additional_km_rate: '',
  }
}

const form = reactive(initialForm())
const vehicles = ref<RentalVehicle[]>([])
const created = ref<CarRentalReservation | null>(null)
const createdNotice = ref('')

const categories: Array<{ value: '' | RentalCategory; label: string }> = [
  { value: '', label: 'Toutes' },
  ...(Object.keys(categoryLabels) as RentalCategory[]).map((value) => ({ value, label: categoryLabels[value] })),
]

const selectedVehicle = computed(() => vehicles.value.find((vehicle) => vehicle.id === form.vehicle_id) ?? null)
const days = computed(() => rentalDays(form.pickup_at, form.due_at))
const periodValid = computed(() => Boolean(form.pickup_at && form.due_at) && days.value > 0)

const airportFeesUsd = computed(() =>
  (form.pickup_location_type === 'cap_haitien_airport' && form.apply_airport_pickup_fee ? AIRPORT_FEE_USD : 0)
  + (form.dropoff_location_type === 'cap_haitien_airport' && form.apply_airport_dropoff_fee ? AIRPORT_FEE_USD : 0),
)

const rentalEstimate = computed(() => {
  const rate = toAmount(form.daily_rate)
  return rate === null ? null : Math.round(rate * days.value * 100) / 100
})

const officeSite = computed(() => session.sites.find((site) => site.id === form.site_id) ?? null)

/* ---------- Disponibilités ---------- */

let availabilityTimer: number | undefined
let availabilityToken = 0

async function loadAvailability(): Promise<void> {
  if (!form.site_id || !periodValid.value) {
    vehicles.value = []
    return
  }

  const token = ++availabilityToken
  const result = await availabilityRequest.run(() => fetchAvailability({
    site_id: form.site_id,
    pickup_at: form.pickup_at,
    due_at: form.due_at,
    category: form.category || undefined,
  }))
  if (token !== availabilityToken) return

  vehicles.value = result?.data ?? []
  if (form.vehicle_id && !vehicles.value.some((vehicle) => vehicle.id === form.vehicle_id)) {
    form.vehicle_id = ''
    form.daily_rate = ''
  }
}

function scheduleAvailability(): void {
  window.clearTimeout(availabilityTimer)
  availabilityTimer = window.setTimeout(() => void loadAvailability(), 300)
}

watch(() => [form.site_id, form.pickup_at, form.due_at, form.category], scheduleAvailability)

onMounted(() => void loadAvailability())
onBeforeUnmount(() => window.clearTimeout(availabilityTimer))

function vehicleIsBookable(vehicle: RentalVehicle): boolean {
  return vehicle.daily_rate_usd !== null && vehicle.minimum_security_deposit_usd !== null
}

function selectVehicle(vehicle: RentalVehicle): void {
  if (!vehicleIsBookable(vehicle)) return
  form.vehicle_id = vehicle.id
  form.currency = 'USD'
  form.daily_rate = vehicle.daily_rate_usd ?? ''
  saveRequest.clearField('vehicle_id')
}

function onSiteChange(): void {
  session.setOfficeSite(form.site_id)
}

/* ---------- Enregistrement ---------- */

const missing = computed(() => {
  const items: string[] = []
  if (!periodValid.value) items.push('une date de retour après la prise en charge')
  if (!form.vehicle_id) items.push('un véhicule disponible')
  if (!form.customer_name.trim()) items.push('le nom du client')
  if (!form.daily_rate) items.push('le tarif journalier')
  if (form.kilometer_plan === 'limited' && (form.included_km === '' || form.additional_km_rate === '')) {
    items.push('les conditions de kilométrage')
  }
  return items
})

async function submit(): Promise<void> {
  if (missing.value.length) {
    saveRequest.fail(`Complétez ${missing.value.join(', ')}.`)
    return
  }

  const result = await saveRequest.run(() => createReservation({
    site_id: form.site_id,
    vehicle_id: form.vehicle_id || undefined,
    category: selectedVehicle.value?.category ?? (form.category || 'suv'),
    customer: {
      customer_type: form.customer_type,
      display_name: form.customer_name.trim(),
      email: form.customer_email.trim() || undefined,
      phone: form.customer_phone.trim() || undefined,
      group_contact_sharing_consent: false,
    },
    pickup_at: form.pickup_at,
    due_at: form.due_at,
    pickup_location_type: form.pickup_location_type,
    pickup_location_detail: form.pickup_location_type === 'custom' ? form.pickup_location_detail.trim() : undefined,
    dropoff_location_type: form.dropoff_location_type,
    dropoff_location_detail: form.dropoff_location_type === 'custom' ? form.dropoff_location_detail.trim() : undefined,
    apply_airport_pickup_fee: form.pickup_location_type === 'cap_haitien_airport' && form.apply_airport_pickup_fee,
    apply_airport_dropoff_fee: form.dropoff_location_type === 'cap_haitien_airport' && form.apply_airport_dropoff_fee,
    currency: form.currency,
    daily_rate: form.daily_rate,
    kilometer_plan: form.kilometer_plan,
    included_km: form.kilometer_plan === 'limited' ? Number(form.included_km) : undefined,
    additional_km_rate: form.kilometer_plan === 'limited' ? form.additional_km_rate : undefined,
  }))
  if (!result) return

  created.value = result.data
  createdNotice.value = result.customer_notification_sent
    ? 'Le courriel de confirmation a été envoyé au client.'
    : 'La réservation est confirmée et journalisée.'
  vehicles.value = vehicles.value.filter((vehicle) => vehicle.id !== result.data.vehicle?.id)
}

function startAnother(): void {
  created.value = null
  Object.assign(form, initialForm())
  saveRequest.reset()
  window.scrollTo({ top: 0 })
  void loadAvailability()
}

async function openCreated(): Promise<void> {
  const reservation = created.value
  if (!reservation) return
  created.value = null
  await router.push({ name: 'rental.reservation', params: { reservationId: reservation.id } })
}

async function backToList(): Promise<void> {
  created.value = null
  await router.push({ name: 'rental.reservations' })
}
</script>

<template>
  <PageHeader
    title="Nouvelle réservation"
    description="Les valeurs du jour sont proposées. Modifiez-les seulement si nécessaire."
    :back="{ name: 'rental.reservations' }"
    back-label="Réservations"
  />

  <div class="new-layout">
    <form id="reservation-form" class="stack-lg" novalidate @submit.prevent="submit">
      <!-- 1. Période et véhicule : critères et résultats dans le même panneau. -->
      <section class="panel" aria-labelledby="period-title">
        <h2 id="period-title" class="title-section">Période et véhicule</h2>

        <FormField v-if="session.sites.length > 1" label="Bureau de départ" v-slot="field">
          <select v-model="form.site_id" v-bind="field.attrs" class="select" @change="onSiteChange">
            <option v-for="site in session.sites" :key="site.id" :value="site.id">{{ site.name }}</option>
          </select>
        </FormField>
        <p v-else-if="officeSite" class="text-secondary">Bureau de départ : <strong>{{ officeSite.name }}</strong></p>

        <div class="grid-2">
          <FormField label="Prise en charge" :error="saveRequest.fieldErrors.value.pickup_at" v-slot="field">
            <input v-model="form.pickup_at" v-bind="field.attrs" class="input" type="datetime-local" required />
          </FormField>
          <FormField label="Retour prévu" :error="saveRequest.fieldErrors.value.due_at" v-slot="field">
            <input v-model="form.due_at" v-bind="field.attrs" class="input" type="datetime-local" required />
          </FormField>
        </div>
        <p v-if="periodValid" class="duration">
          <span class="display display-md">{{ days }}</span> {{ days > 1 ? 'jours facturables' : 'jour facturable' }}
        </p>
        <InlineAlert v-else message="La date de retour doit être après la prise en charge." />

        <div class="segmented" role="group" aria-label="Catégorie">
          <button v-for="item in categories" :key="item.value" type="button" :aria-pressed="form.category === item.value" @click="form.category = item.value">
            {{ item.label }}
          </button>
        </div>

        <div class="availability" aria-live="polite">
          <p class="availability-status text-small">
            <template v-if="availabilityRequest.busy.value">Recherche des véhicules disponibles</template>
            <template v-else-if="periodValid">{{ vehicles.length ? `${plural(vehicles.length, 'véhicule disponible', 'véhicules disponibles')} sur cette période` : 'Aucun véhicule disponible sur cette période' }}</template>
          </p>
          <InlineAlert :message="availabilityRequest.error.value" />

          <div v-if="availabilityRequest.busy.value && !vehicles.length" class="skeleton" style="height: 168px"></div>

          <div v-else-if="vehicles.length" class="vehicle-options" role="radiogroup" aria-label="Véhicules disponibles">
            <button
              v-for="vehicle in vehicles"
              :key="vehicle.id"
              type="button"
              role="radio"
              class="vehicle-option"
              :aria-checked="form.vehicle_id === vehicle.id"
              :disabled="!vehicleIsBookable(vehicle)"
              @click="selectVehicle(vehicle)"
            >
              <VehicleThumb :photo-url="vehicle.reference_photo?.url" :category="vehicle.category" :alt="vehicleName(vehicle)" />
              <span class="vehicle-option-text">
                <strong>{{ vehicleName(vehicle) }}</strong>
                <span class="plate">{{ vehiclePlate(vehicle) }}</span>
                <span v-if="vehicleIsBookable(vehicle)" class="text-small text-secondary">
                  {{ formatMoney(vehicle.daily_rate_usd, 'USD') }} par jour - dépôt {{ formatMoney(vehicle.minimum_security_deposit_usd, 'USD') }}
                </span>
                <span v-else class="text-small field-error">Tarif ou dépôt à configurer dans la fiche</span>
              </span>
              <span class="radio-mark" aria-hidden="true"></span>
            </button>
          </div>

          <div v-else-if="periodValid && !availabilityRequest.busy.value && !availabilityRequest.error.value" class="empty">
            <p>Essayez une autre catégorie, une autre période ou un autre bureau.</p>
          </div>
          <span v-if="saveRequest.fieldErrors.value.vehicle_id" class="field-error" role="alert">{{ saveRequest.fieldErrors.value.vehicle_id }}</span>
        </div>
      </section>

      <!-- 2. Client -->
      <section class="panel" aria-labelledby="customer-title">
        <h2 id="customer-title" class="title-section">Client</h2>
        <div class="segmented" role="group" aria-label="Type de client">
          <button type="button" :aria-pressed="form.customer_type === 'individual'" @click="form.customer_type = 'individual'">Particulier</button>
          <button type="button" :aria-pressed="form.customer_type === 'institution'" @click="form.customer_type = 'institution'">Institution</button>
        </div>
        <FormField :label="form.customer_type === 'individual' ? 'Nom complet' : 'Raison sociale'" :error="saveRequest.fieldErrors.value['customer.display_name']" v-slot="field">
          <input v-model.trim="form.customer_name" v-bind="field.attrs" class="input" autocomplete="off" maxlength="160" required />
        </FormField>
        <div class="grid-2">
          <FormField label="Courriel (facultatif)" help="Pour recevoir la confirmation." :error="saveRequest.fieldErrors.value['customer.email']" v-slot="field">
            <input v-model.trim="form.customer_email" v-bind="field.attrs" class="input" type="email" inputmode="email" autocomplete="off" maxlength="254" />
          </FormField>
          <FormField label="Téléphone (facultatif)" :error="saveRequest.fieldErrors.value['customer.phone']" v-slot="field">
            <input v-model.trim="form.customer_phone" v-bind="field.attrs" class="input" type="tel" inputmode="tel" autocomplete="off" maxlength="64" />
          </FormField>
        </div>
      </section>

      <!-- 3. Lieux -->
      <section class="panel" aria-labelledby="places-title">
        <h2 id="places-title" class="title-section">Lieux</h2>
        <div class="grid-2">
          <FormField label="Départ" v-slot="field">
            <select v-model="form.pickup_location_type" v-bind="field.attrs" class="select">
              <option v-for="(label, value) in locationLabels" :key="value" :value="value">{{ label }}</option>
            </select>
          </FormField>
          <FormField label="Retour" v-slot="field">
            <select v-model="form.dropoff_location_type" v-bind="field.attrs" class="select">
              <option v-for="(label, value) in locationLabels" :key="value" :value="value">{{ label }}</option>
            </select>
          </FormField>
        </div>
        <FormField v-if="form.pickup_location_type === 'custom'" label="Précision du départ" :error="saveRequest.fieldErrors.value.pickup_location_detail" v-slot="field">
          <input v-model.trim="form.pickup_location_detail" v-bind="field.attrs" class="input" maxlength="1000" required />
        </FormField>
        <FormField v-if="form.dropoff_location_type === 'custom'" label="Précision du retour" :error="saveRequest.fieldErrors.value.dropoff_location_detail" v-slot="field">
          <input v-model.trim="form.dropoff_location_detail" v-bind="field.attrs" class="input" maxlength="1000" required />
        </FormField>
        <label v-if="form.pickup_location_type === 'cap_haitien_airport'" class="check">
          <input v-model="form.apply_airport_pickup_fee" type="checkbox" />
          <span>Appliquer {{ formatMoney(AIRPORT_FEE_USD, 'USD') }} pour la prise en charge à l’aéroport</span>
        </label>
        <label v-if="form.dropoff_location_type === 'cap_haitien_airport'" class="check">
          <input v-model="form.apply_airport_dropoff_fee" type="checkbox" />
          <span>Appliquer {{ formatMoney(AIRPORT_FEE_USD, 'USD') }} pour le retour à l’aéroport</span>
        </label>
      </section>

      <!-- 4. Conditions -->
      <section class="panel" aria-labelledby="terms-title">
        <h2 id="terms-title" class="title-section">Conditions</h2>
        <div class="grid-2">
          <FormField label="Devise du tarif" v-slot="field">
            <select v-model="form.currency" v-bind="field.attrs" class="select">
              <option value="USD">USD</option>
              <option value="HTG">HTG</option>
            </select>
          </FormField>
          <FormField label="Tarif journalier" help="Proposé depuis la fiche du véhicule." :error="saveRequest.fieldErrors.value.daily_rate" v-slot="field">
            <input v-model="form.daily_rate" v-bind="field.attrs" class="input" type="number" inputmode="decimal" min="0" step="0.01" required />
          </FormField>
        </div>
        <div class="segmented" role="group" aria-label="Kilométrage">
          <button type="button" :aria-pressed="form.kilometer_plan === 'limited'" @click="form.kilometer_plan = 'limited'">Kilométrage limité</button>
          <button type="button" :aria-pressed="form.kilometer_plan === 'unlimited'" @click="form.kilometer_plan = 'unlimited'">Illimité</button>
        </div>
        <div v-if="form.kilometer_plan === 'limited'" class="grid-2">
          <FormField label="Kilomètres inclus" :error="saveRequest.fieldErrors.value.included_km" v-slot="field">
            <input v-model="form.included_km" v-bind="field.attrs" class="input" type="number" inputmode="numeric" min="0" step="1" required />
          </FormField>
          <FormField :label="`Prix par kilomètre supplémentaire (${form.currency})`" :error="saveRequest.fieldErrors.value.additional_km_rate" v-slot="field">
            <input v-model="form.additional_km_rate" v-bind="field.attrs" class="input" type="number" inputmode="decimal" min="0" step="0.01" required />
          </FormField>
        </div>
      </section>
    </form>

    <aside class="summary" aria-label="Résumé de la réservation">
      <div class="panel summary-panel">
        <h2 class="title-section">Résumé</h2>
        <template v-if="selectedVehicle">
          <VehicleThumb size="lg" :photo-url="selectedVehicle.reference_photo?.url" :category="selectedVehicle.category" :alt="vehicleName(selectedVehicle)" />
          <p class="summary-vehicle">
            <strong>{{ vehicleName(selectedVehicle) }}</strong>
            <span class="plate">{{ vehiclePlate(selectedVehicle) }}</span>
          </p>
        </template>
        <p v-else class="text-muted">Sélectionnez un véhicule disponible.</p>

        <dl class="facts">
          <div>
            <dt>Prise en charge</dt>
            <dd>{{ form.pickup_at ? formatBusinessInput(form.pickup_at) : 'À définir' }}</dd>
          </div>
          <div>
            <dt>Retour prévu</dt>
            <dd>{{ form.due_at ? formatBusinessInput(form.due_at) : 'À définir' }}</dd>
          </div>
          <div>
            <dt>Location</dt>
            <dd>{{ rentalEstimate === null ? 'À définir' : `${days} × ${formatMoney(form.daily_rate, form.currency)}` }}</dd>
          </div>
          <div v-if="airportFeesUsd">
            <dt>Frais aéroport</dt>
            <dd>{{ formatMoney(airportFeesUsd, 'USD') }}</dd>
          </div>
          <div v-if="selectedVehicle">
            <dt>Dépôt minimum</dt>
            <dd>{{ formatMoney(selectedVehicle.minimum_security_deposit_usd, 'USD') }}</dd>
          </div>
        </dl>
        <p class="summary-total">
          <span class="text-secondary text-small">Location estimée</span>
          <span class="display display-lg">{{ rentalEstimate === null ? '-' : formatMoney(rentalEstimate, form.currency) }}</span>
        </p>
        <InlineAlert :message="saveRequest.error.value" />
        <button class="btn btn-primary btn-block summary-submit" type="submit" form="reservation-form" :disabled="saveRequest.busy.value || !app.canReachServer">
          {{ saveRequest.busy.value ? 'Enregistrement' : 'Enregistrer la réservation' }}
        </button>
      </div>
    </aside>
  </div>

  <!-- Barre d'action au pouce, téléphone uniquement. -->
  <div class="action-bar">
    <span class="action-bar-total">
      <span class="text-small text-secondary">{{ selectedVehicle ? vehicleName(selectedVehicle) : 'Aucun véhicule' }}</span>
      <strong class="display display-sm">{{ rentalEstimate === null ? '-' : formatMoney(rentalEstimate, form.currency) }}</strong>
    </span>
    <button class="btn btn-primary" type="submit" form="reservation-form" :disabled="saveRequest.busy.value || !app.canReachServer">
      {{ saveRequest.busy.value ? 'Enregistrement' : 'Enregistrer' }}
    </button>
  </div>

  <SheetDialog :open="Boolean(created)" title="Réservation enregistrée" :description="createdNotice" @close="openCreated">
    <template v-if="created">
      <p class="display display-xl">{{ created.number }}</p>
      <dl class="facts">
        <div>
          <dt>Client</dt>
          <dd>{{ created.customer?.display_name ?? 'Non disponible' }}</dd>
        </div>
        <div>
          <dt>Véhicule</dt>
          <dd>{{ vehicleName(created.vehicle) }}</dd>
        </div>
        <div>
          <dt>Prise en charge</dt>
          <dd>{{ formatDateTime(created.pickup_at) }}</dd>
        </div>
        <div>
          <dt>Retour prévu</dt>
          <dd>{{ formatDateTime(created.due_at) }}</dd>
        </div>
        <div>
          <dt>Tarif journalier</dt>
          <dd>{{ formatMoney(created.daily_rate, created.currency) }}</dd>
        </div>
        <div>
          <dt>Dépôt minimum</dt>
          <dd>{{ formatMoney(created.minimum_security_deposit_usd, 'USD') }}</dd>
        </div>
      </dl>
    </template>
    <template #footer>
      <button class="btn btn-secondary" type="button" @click="backToList">Retour à la liste</button>
      <button class="btn btn-secondary" type="button" @click="startAnother">Nouvelle réservation</button>
      <button class="btn btn-primary" type="button" @click="openCreated">Voir la réservation</button>
    </template>
  </SheetDialog>
</template>

<style scoped>
.new-layout {
  display: grid;
  gap: 28px;
  align-items: start;
}

.duration {
  display: flex;
  align-items: baseline;
  gap: 8px;
  color: var(--ink-2);
  font-weight: 600;
}

.availability {
  display: grid;
  gap: 12px;
  padding-top: 4px;
  border-top: 1px solid var(--line);
}

.availability-status {
  min-height: 1.4em;
  padding-top: 12px;
  color: var(--ink-2);
  font-weight: 650;
}

.vehicle-options {
  display: grid;
  gap: 10px;
}

.vehicle-option {
  display: grid;
  grid-template-columns: 72px minmax(0, 1fr) 28px;
  align-items: center;
  gap: 14px;
  min-height: 84px;
  padding: 12px;
  border: 2px solid var(--line);
  border-radius: 16px;
  background: var(--surface);
  text-align: left;
  cursor: pointer;
}

.vehicle-option[aria-checked='true'] {
  border-color: var(--accent);
  background: var(--accent-soft);
}

.vehicle-option:disabled {
  cursor: not-allowed;
  opacity: 0.6;
}

.vehicle-option-text {
  display: grid;
  justify-items: start;
  gap: 4px;
  min-width: 0;
}

.radio-mark {
  width: 26px;
  height: 26px;
  border: 2px solid var(--line-strong);
  border-radius: 50%;
}

.vehicle-option[aria-checked='true'] .radio-mark {
  border: 8px solid var(--accent);
}

.summary {
  display: none;
}

.summary-vehicle {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
}

.summary-total {
  display: grid;
  gap: 6px;
  padding-top: 12px;
  border-top: 2px solid var(--ink);
}

.action-bar {
  position: fixed;
  z-index: 15;
  left: 0;
  right: 0;
  bottom: calc(var(--tabbar-h) + env(safe-area-inset-bottom));
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 10px var(--gutter);
  border-top: 1px solid var(--line);
  background: var(--surface);
  box-shadow: 0 -6px 18px rgba(23, 24, 29, 0.08);
}

.action-bar-total {
  display: grid;
  gap: 2px;
  min-width: 0;
}

.action-bar-total .text-small {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.action-bar .btn {
  flex: none;
  min-width: 150px;
}

@media (max-width: 1099px) {
  .new-layout {
    padding-bottom: 84px;
  }
}

@media (min-width: 640px) {
  .vehicle-options {
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
  }
}

@media (min-width: 1100px) {
  .new-layout {
    grid-template-columns: minmax(0, 1fr) 340px;
  }

  .summary {
    position: sticky;
    top: 24px;
    display: block;
  }

  .action-bar {
    display: none;
  }
}

@media (min-width: 960px) and (max-width: 1099px) {
  .action-bar {
    left: var(--rail-w);
    bottom: 0;
  }
}
</style>
