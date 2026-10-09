<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import type { RentalVehicle } from '../../api/types'
import { useSessionStore } from '../../stores/session'
import { useAppStore } from '../../stores/app'
import { locationLabels } from '../../lib/labels'
import { formatMoney, toAmount } from '../../lib/money'
import { formatBusinessInput, rentalDays } from '../../lib/time'
import { vehicleName, vehiclePlate } from '../../lib/text'
import FormField from '../ui/FormField.vue'
import InlineAlert from '../ui/InlineAlert.vue'
import VehiclePicker from './VehiclePicker.vue'
import CustomerLookup from './CustomerLookup.vue'
import type { KnownCustomer } from '../../api/carRental'
import VehicleThumb from './VehicleThumb.vue'
import type { ReservationFormValues } from './reservationForm'

/*
 * Formulaire de réservation, commun à la création et à la modification.
 *
 * Règles du contrat Clientèle Rent A Car :
 * - le tarif vient de la fiche véhicule ; seul un rôle autorisé le modifie ;
 * - 100 km par jour inclus, prix du kilomètre supplémentaire facultatif ;
 * - location minimale de 2 jours : avertissement, sans blocage.
 * Le bouton d'enregistrement reste grisé tant qu'un élément obligatoire manque.
 */

const AIRPORT_FEE_USD = 20
const KM_PER_DAY = 100
const MINIMUM_DAYS = 2

const props = defineProps<{
  initial: ReservationFormValues
  mode: 'create' | 'edit'
  currentVehicle?: RentalVehicle | null
  busy: boolean
  error: string
  fieldErrors: Record<string, string>
  /** Tarif particulier déjà accordé par un administrateur (modification). */
  rateOverridden?: boolean
}>()

const emit = defineEmits<{
  submit: [values: ReservationFormValues, vehicle: RentalVehicle | null]
}>()

const session = useSessionStore()
const app = useAppStore()

const form = reactive<ReservationFormValues>({ ...props.initial })
const selectedVehicle = ref<RentalVehicle | null>(props.currentVehicle ?? null)
const kmEdited = ref(props.mode === 'edit')

const canOverrideRate = computed(() => session.can('rental.reservations.override_rate'))
const days = computed(() => rentalDays(form.pickup_at, form.due_at))
const periodValid = computed(() => Boolean(form.pickup_at && form.due_at) && days.value > 0)
const shortRental = computed(() => periodValid.value && days.value < MINIMUM_DAYS)
const officeSite = computed(() => session.sites.find((site) => site.id === form.site_id) ?? null)

const airportFeesUsd = computed(() =>
  (form.pickup_location_type === 'cap_haitien_airport' && form.apply_airport_pickup_fee ? AIRPORT_FEE_USD : 0)
  + (form.dropoff_location_type === 'cap_haitien_airport' && form.apply_airport_dropoff_fee ? AIRPORT_FEE_USD : 0),
)

const rentalEstimate = computed(() => {
  const rate = toAmount(form.daily_rate)
  return rate === null ? null : Math.round(rate * days.value * 100) / 100
})

const rateDiffers = computed(() => {
  const vehicleRate = toAmount(selectedVehicle.value?.daily_rate_usd)
  const rate = toAmount(form.daily_rate)
  return vehicleRate !== null && rate !== null && (form.currency !== 'USD' || Math.round(rate * 100) !== Math.round(vehicleRate * 100))
})

/* Le forfait suit la durée tant que l'utilisateur ne l'a pas modifié. */
watch(days, (value) => {
  if (!kmEdited.value && value > 0) form.included_km = String(value * KM_PER_DAY)
}, { immediate: props.mode === 'create' })

function onVehicleSelected(vehicle: RentalVehicle | null): void {
  selectedVehicle.value = vehicle
  if (!vehicle) return
  // Le tarif de la fiche s'applique à chaque choix de véhicule.
  if (!canOverrideRate.value || !props.rateOverridden) {
    form.currency = 'USD'
    form.daily_rate = vehicle.daily_rate_usd ?? ''
  }
}

function resetRateToVehicle(): void {
  if (!selectedVehicle.value) return
  form.currency = 'USD'
  form.daily_rate = selectedVehicle.value.daily_rate_usd ?? ''
}

/* ---------- Client connu ---------- */

function useKnownCustomer(customer: KnownCustomer): void {
  form.customer_profile_id = customer.id
  form.customer_type = customer.customer_type
  form.customer_name = customer.display_name
  form.customer_hint = [customer.email_hint, customer.phone_hint].filter(Boolean).join(' - ')
  form.customer_email = ''
  form.customer_phone = ''
}

function forgetKnownCustomer(): void {
  form.customer_profile_id = ''
  form.customer_hint = ''
  form.customer_name = ''
}

function onSiteChange(): void {
  session.setOfficeSite(form.site_id)
}

/* ---------- Champs obligatoires ---------- */

const missing = computed(() => {
  const items: Array<{ field: string; label: string }> = []
  if (!periodValid.value) items.push({ field: 'due_at', label: 'Une date de retour après la prise en charge' })
  if (!form.vehicle_id) items.push({ field: 'vehicle_id', label: 'Le véhicule' })
  if (!form.customer_name.trim()) items.push({ field: 'customer_name', label: form.customer_type === 'individual' ? 'Le nom du client' : 'La raison sociale' })
  if (!form.customer_profile_id && !form.customer_email.trim() && !form.customer_phone.trim()) items.push({ field: 'customer_contact', label: 'Un courriel ou un téléphone du client' })
  if (form.pickup_location_type === 'custom' && !form.pickup_location_detail.trim()) items.push({ field: 'pickup_location_detail', label: 'Le lieu de départ' })
  if (form.dropoff_location_type === 'custom' && !form.dropoff_location_detail.trim()) items.push({ field: 'dropoff_location_detail', label: 'Le lieu de retour' })
  if (!form.daily_rate) items.push({ field: 'daily_rate', label: 'Le tarif journalier' })
  if (form.kilometer_plan === 'limited' && form.included_km === '') items.push({ field: 'included_km', label: 'Les kilomètres inclus' })
  return items
})

const canSubmit = computed(() => missing.value.length === 0 && !props.busy && app.canReachServer)

function submit(): void {
  if (!canSubmit.value) return
  emit('submit', { ...form }, selectedVehicle.value)
}

defineExpose({ form })
</script>

<template>
  <div class="editor">
    <form id="reservation-form" class="stack-lg" novalidate @submit.prevent="submit">
      <!-- Période et véhicule : critères et résultats dans le même panneau. -->
      <section class="panel" aria-labelledby="period-title">
        <h2 id="period-title" class="title-section">Période et véhicule</h2>

        <FormField v-if="session.sites.length > 1 && mode === 'create'" label="Bureau de départ" required v-slot="field">
          <select v-model="form.site_id" v-bind="field.attrs" class="select" @change="onSiteChange">
            <option v-for="site in session.sites" :key="site.id" :value="site.id">{{ site.name }}</option>
          </select>
        </FormField>
        <p v-else-if="officeSite" class="text-secondary">Bureau de départ : <strong>{{ officeSite.name }}</strong></p>

        <div class="grid-2">
          <FormField label="Prise en charge" required :error="fieldErrors.pickup_at" v-slot="field">
            <input v-model="form.pickup_at" v-bind="field.attrs" class="input" type="datetime-local" required />
          </FormField>
          <FormField label="Retour prévu" required :error="fieldErrors.due_at" v-slot="field">
            <input v-model="form.due_at" v-bind="field.attrs" class="input" type="datetime-local" required />
          </FormField>
        </div>
        <p v-if="periodValid" class="duration">
          <strong>{{ days }} {{ days > 1 ? 'jours' : 'jour' }}</strong> facturable{{ days > 1 ? 's' : '' }}
        </p>
        <InlineAlert v-else message="La date de retour doit être après la prise en charge." />
        <InlineAlert
          v-if="shortRental"
          tone="warning"
          message="La location minimale prévue au contrat est de 2 jours. Vérifiez la durée avec le client avant d’enregistrer."
        />

        <VehiclePicker
          v-model="form.vehicle_id"
          :site-id="form.site_id"
          :pickup-at="form.pickup_at"
          :due-at="form.due_at"
          :period-valid="periodValid"
          :current-vehicle="currentVehicle"
          :error="fieldErrors.vehicle_id"
          @select="onVehicleSelected"
        />
      </section>

      <!-- Client -->
      <section class="panel" aria-labelledby="customer-title">
        <h2 id="customer-title" class="title-section">Client</h2>
        <div class="segmented" role="group" aria-label="Type de client">
          <button type="button" :aria-pressed="form.customer_type === 'individual'" @click="form.customer_type = 'individual'">Particulier</button>
          <button type="button" :aria-pressed="form.customer_type === 'institution'" @click="form.customer_type = 'institution'">Institution</button>
        </div>
        <div v-if="form.customer_profile_id" class="known-customer">
          <span class="stack" style="gap: 2px">
            <strong>{{ form.customer_name }}</strong>
            <span class="text-small text-secondary">Client connu<template v-if="form.customer_hint"> - {{ form.customer_hint }}</template></span>
          </span>
          <button class="btn btn-ghost" type="button" @click="forgetKnownCustomer">Changer</button>
        </div>
        <template v-else>
        <FormField :label="form.customer_type === 'individual' ? 'Nom complet' : 'Raison sociale'" required :help="mode === 'create' ? 'Saisissez le nom, le courriel ou le téléphone : les clients connus sont proposés.' : undefined" :error="fieldErrors['customer.display_name']" v-slot="field">
          <input v-model.trim="form.customer_name" v-bind="field.attrs" class="input" autocomplete="off" maxlength="160" required />
        </FormField>
        <CustomerLookup v-if="mode === 'create'" :query="form.customer_name" @select="useKnownCustomer" />
        <div class="grid-2">
          <FormField label="Courriel" help="Pour envoyer la confirmation." :error="fieldErrors['customer.email']" v-slot="field">
            <input v-model.trim="form.customer_email" v-bind="field.attrs" class="input" type="email" inputmode="email" autocomplete="off" maxlength="254" />
          </FormField>
          <FormField label="Téléphone" help="Courriel ou téléphone obligatoire." :error="fieldErrors['customer.phone']" v-slot="field">
            <input v-model.trim="form.customer_phone" v-bind="field.attrs" class="input" type="tel" inputmode="tel" autocomplete="off" maxlength="64" />
          </FormField>
        </div>
        <CustomerLookup v-if="mode === 'create'" :query="form.customer_email.includes('@') ? form.customer_email : form.customer_phone" @select="useKnownCustomer" />
        </template>
      </section>

      <!-- Lieux -->
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
        <FormField v-if="form.pickup_location_type === 'custom'" label="Précision du départ" required :error="fieldErrors.pickup_location_detail" v-slot="field">
          <input v-model.trim="form.pickup_location_detail" v-bind="field.attrs" class="input" maxlength="1000" required />
        </FormField>
        <FormField v-if="form.dropoff_location_type === 'custom'" label="Précision du retour" required :error="fieldErrors.dropoff_location_detail" v-slot="field">
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

      <!-- Conditions -->
      <section class="panel" aria-labelledby="terms-title">
        <h2 id="terms-title" class="title-section">Conditions</h2>
        <div class="grid-2">
          <FormField label="Devise du tarif" v-slot="field">
            <select v-model="form.currency" v-bind="field.attrs" class="select" :disabled="!canOverrideRate">
              <option value="USD">USD</option>
              <option value="HTG">HTG</option>
            </select>
          </FormField>
          <FormField
            label="Tarif journalier"
            required
            :help="canOverrideRate ? 'Tarif de la fiche véhicule. Vous pouvez le modifier.' : 'Tarif de la fiche véhicule. Modification réservée à l’administrateur.'"
            :error="fieldErrors.daily_rate"
            v-slot="field"
          >
            <input
              v-model="form.daily_rate"
              v-bind="field.attrs"
              class="input"
              type="number"
              inputmode="decimal"
              min="0"
              step="0.01"
              required
              :readonly="!canOverrideRate"
            />
          </FormField>
        </div>
        <p v-if="canOverrideRate && rateDiffers" class="alert alert-warning">
          Tarif particulier : il diffère de la fiche véhicule ({{ formatMoney(selectedVehicle?.daily_rate_usd, 'USD') }} par jour).
          <button class="btn btn-ghost" type="button" @click="resetRateToVehicle">Reprendre le tarif de la fiche</button>
        </p>

        <div class="segmented" role="group" aria-label="Kilométrage">
          <button type="button" :aria-pressed="form.kilometer_plan === 'limited'" @click="form.kilometer_plan = 'limited'">Kilométrage limité</button>
          <button type="button" :aria-pressed="form.kilometer_plan === 'unlimited'" @click="form.kilometer_plan = 'unlimited'">Illimité</button>
        </div>
        <div v-if="form.kilometer_plan === 'limited'" class="grid-2">
          <FormField label="Kilomètres inclus" required help="Contrat : 100 km par jour." :error="fieldErrors.included_km" v-slot="field">
            <input v-model="form.included_km" v-bind="field.attrs" class="input" type="number" inputmode="numeric" min="0" step="1" required @input="kmEdited = true" />
          </FormField>
          <FormField :label="`Prix du kilomètre supplémentaire (${form.currency})`" help="Facultatif." :error="fieldErrors.additional_km_rate" v-slot="field">
            <input v-model="form.additional_km_rate" v-bind="field.attrs" class="input" type="number" inputmode="decimal" min="0" step="0.01" />
          </FormField>
        </div>
        <label v-if="mode === 'edit'" class="check">
          <input v-model="form.notify_customer" type="checkbox" :disabled="!form.customer_email" />
          <span>Envoyer la confirmation mise à jour au client{{ form.customer_email ? '' : ' (courriel requis)' }}</span>
        </label>
      </section>
    </form>

    <aside class="summary" aria-label="Résumé de la réservation">
      <div class="panel summary-panel">
        <h2 class="title-section">Résumé</h2>
        <template v-if="selectedVehicle">
          <VehicleThumb size="lg" :vehicle="selectedVehicle" :alt="vehicleName(selectedVehicle)" />
          <p class="summary-vehicle">
            <strong>{{ vehicleName(selectedVehicle) }}</strong>
            <span class="plate">{{ vehiclePlate(selectedVehicle) }}</span>
          </p>
        </template>

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
          <span class="text-secondary">Location estimée</span>
          <span class="display display-lg">{{ rentalEstimate === null ? '-' : formatMoney(rentalEstimate, form.currency) }}</span>
        </p>

        <div v-if="missing.length" class="missing" role="status">
          <p><strong>Pour enregistrer, complétez :</strong></p>
          <ul>
            <li v-for="item in missing" :key="item.field">{{ item.label }}</li>
          </ul>
        </div>
        <InlineAlert :message="error" />
        <button class="btn btn-primary btn-block" type="submit" form="reservation-form" :disabled="!canSubmit">
          {{ busy ? 'Enregistrement' : mode === 'create' ? 'Enregistrer la réservation' : 'Enregistrer les modifications' }}
        </button>
      </div>
    </aside>

    <!-- Barre d'action au pouce, téléphone et tablette. -->
    <div class="action-bar">
      <span class="action-bar-text">
        <template v-if="missing.length">
          <span class="text-small missing-count">{{ missing.length > 1 ? `${missing.length} éléments manquants` : '1 élément manquant' }}</span>
          <span class="text-small text-secondary ellipsis">{{ missing[0].label }}</span>
        </template>
        <template v-else>
          <span class="text-small text-secondary ellipsis">{{ selectedVehicle ? vehicleName(selectedVehicle) : 'Aucun véhicule' }}</span>
          <strong class="display display-sm">{{ rentalEstimate === null ? '-' : formatMoney(rentalEstimate, form.currency) }}</strong>
        </template>
      </span>
      <button class="btn btn-primary" type="submit" form="reservation-form" :disabled="!canSubmit">
        {{ busy ? 'Enregistrement' : 'Enregistrer' }}
      </button>
    </div>
  </div>
</template>

<style scoped>
.editor {
  display: grid;
  gap: 24px;
  align-items: start;
}

.duration {
  font-size: var(--text-sm);
  color: var(--ink-2);
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
  gap: 2px;
  padding-top: 12px;
  border-top: 1px solid var(--line-strong);
  font-size: var(--text-sm);
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
  padding: 8px var(--gutter);
  border-top: 1px solid var(--line);
  background: var(--surface);
  box-shadow: var(--shadow-16);
}

.action-bar-text {
  display: grid;
  gap: 0;
  min-width: 0;
}

.known-customer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 10px 12px;
  border: 1px solid var(--accent);
  border-radius: var(--radius-control);
  background: var(--accent-soft);
}

.missing-count {
  color: var(--warning);
  font-weight: 600;
}

.ellipsis {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.action-bar .btn {
  flex: none;
  min-width: 140px;
}

@media (max-width: 1099px) {
  .editor {
    padding-bottom: 80px;
  }
}

@media (min-width: 1100px) {
  .editor {
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
