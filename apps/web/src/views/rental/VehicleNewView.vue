<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { createVehicle } from '../../api/carRental'
import type { FuelType, RentalCategory, Transmission, VehicleOperationalStatus, VehicleRegistrationStatus } from '../../api/types'
import { useSessionStore } from '../../stores/session'
import { useAppStore } from '../../stores/app'
import { useUiStore } from '../../stores/ui'
import { useRequest } from '../../composables/useRequest'
import { categoryLabels, fuelTypeLabels, registrationStatusLabels, transmissionLabels, vehicleStatusLabels } from '../../lib/labels'
import { normalizeForSearch } from '../../lib/text'
import {
  CLIENTELE_CAR_RENTAL_FLEET_ADDRESS,
  clienteleFleetCatalog,
  type FleetCatalogVehicle,
} from '../../data/clienteleFleetCatalog'
import PageHeader from '../../components/ui/PageHeader.vue'
import FormField from '../../components/ui/FormField.vue'
import InlineAlert from '../../components/ui/InlineAlert.vue'

/*
 * Ajout d'un véhicule, en sections courtes : identité, tarification,
 * disponibilité. Le catalogue de la flotte préremplit les informations
 * visibles dans les publications, sans jamais créer de véhicule seul.
 */
const session = useSessionStore()
const app = useAppStore()
const ui = useUiStore()
const router = useRouter()
const request = useRequest()

function fleetSiteId(): string {
  const target = normalizeForSearch(CLIENTELE_CAR_RENTAL_FLEET_ADDRESS)
  return session.sites.find((site) => normalizeForSearch(`${site.name} ${site.address}`).includes(target))?.id
    ?? session.officeSiteId
}

const form = reactive({
  site_id: fleetSiteId(),
  category: 'suv' as RentalCategory,
  operational_status: 'available' as VehicleOperationalStatus,
  make: '',
  model: '',
  model_year: '',
  registration_number: '',
  registration_status: 'normal' as VehicleRegistrationStatus,
  reference_photo_key: '',
  vin: '',
  latest_odometer_km: '',
  daily_rate_usd: '',
  minimum_security_deposit_usd: '',
  color: '',
  fuel_type: '' as '' | FuelType,
  transmission: '' as '' | Transmission,
  engine_displacement_cc: '',
  doors: '',
})

/* Éléments obligatoires encore vides, affichés avant l'enregistrement. */
const missing = computed(() => {
  const items: Array<{ field: string; label: string }> = []
  if (!form.registration_number.trim()) items.push({ field: 'registration_number', label: 'La plaque en cours' })
  if (!form.make.trim()) items.push({ field: 'make', label: 'La marque' })
  if (!form.model.trim()) items.push({ field: 'model', label: 'Le modèle' })
  if (!form.daily_rate_usd || Number(form.daily_rate_usd) <= 0) items.push({ field: 'daily_rate_usd', label: 'Le tarif quotidien' })
  if (form.minimum_security_deposit_usd === '') items.push({ field: 'minimum_security_deposit_usd', label: 'Le dépôt minimum' })
  if (!form.site_id) items.push({ field: 'site_id', label: 'L’adresse' })
  if (form.latest_odometer_km === '') items.push({ field: 'latest_odometer_km', label: 'Le kilométrage relevé' })
  return items
})

const catalogNotice = ref('')
const chosenCandidate = ref<FleetCatalogVehicle | null>(null)

function prefill(candidate: FleetCatalogVehicle): void {
  chosenCandidate.value = candidate
  Object.assign(form, {
    site_id: fleetSiteId(),
    category: candidate.category,
    make: candidate.make,
    model: candidate.model,
    registration_number: candidate.registrationNumber,
    registration_status: candidate.registrationStatus,
    reference_photo_key: candidate.referencePhoto?.key ?? '',
    daily_rate_usd: candidate.dailyRateUsd === undefined ? '' : String(candidate.dailyRateUsd),
  })
  catalogNotice.value = candidate.requiresReview
    ? `${candidate.reviewMessage ?? 'Vérifiez la plaque et le modèle sur le véhicule.'} Saisissez ensuite le kilométrage et le dépôt minimum.`
    : 'Informations préremplies. Saisissez le kilométrage relevé et le dépôt minimum.'
  request.reset()
}

async function submit(): Promise<void> {
  if (missing.value.length) return

  const result = await request.run(() => createVehicle({
    site_id: form.site_id,
    category: form.category,
    operational_status: form.operational_status,
    make: form.make.trim() || undefined,
    model: form.model.trim() || undefined,
    model_year: form.model_year === '' ? undefined : Number(form.model_year),
    registration_number: form.registration_number.trim(),
    registration_status: form.registration_status,
    reference_photo_key: form.reference_photo_key || undefined,
    vin: form.vin.trim() || undefined,
    latest_odometer_km: Number(form.latest_odometer_km),
    daily_rate_usd: Number(form.daily_rate_usd),
    minimum_security_deposit_usd: Number(form.minimum_security_deposit_usd),
    color: form.color.trim() || undefined,
    fuel_type: form.fuel_type || undefined,
    transmission: form.transmission || undefined,
    engine_displacement_cc: form.engine_displacement_cc === '' ? undefined : Number(form.engine_displacement_cc),
    doors: form.doors === '' ? undefined : Number(form.doors),
  }))
  if (!result) return
  ui.toast(`Véhicule ${result.data.registration_number ?? result.data.code} enregistré.`)
  await router.replace({ name: 'rental.vehicle', params: { vehicleId: result.data.id } })
}
</script>

<template>
  <PageHeader
    title="Ajouter un véhicule"
    description="La plaque en cours est l’identifiant du véhicule. Les champs marqués * sont obligatoires."
    :back="{ name: 'rental.vehicles' }"
    back-label="Véhicules"
  />

  <div class="stack-lg">
    <section class="stack" aria-labelledby="catalog-title">
      <h2 id="catalog-title" class="title-section">Préremplir depuis la flotte connue</h2>
      <div class="catalog" role="list">
        <button
          v-for="candidate in clienteleFleetCatalog"
          :key="candidate.registrationNumber"
          type="button"
          role="listitem"
          class="catalog-item"
          :class="{ selected: chosenCandidate?.registrationNumber === candidate.registrationNumber }"
          @click="prefill(candidate)"
        >
          <img v-if="candidate.referencePhoto" :src="candidate.referencePhoto.url" :alt="candidate.referencePhoto.alt" loading="lazy" />
          <span v-else class="catalog-placeholder" aria-hidden="true"></span>
          <span class="catalog-text">
            <span class="plate">{{ candidate.registrationNumber }}</span>
            <strong>{{ candidate.make }} {{ candidate.model }}</strong>
            <span class="text-small" :class="candidate.requiresReview ? 'review' : 'text-muted'">
              {{ candidate.requiresReview ? 'À vérifier' : categoryLabels[candidate.category] }}
            </span>
          </span>
        </button>
      </div>
      <InlineAlert :message="catalogNotice" tone="info" />
    </section>

    <form class="stack-lg" novalidate @submit.prevent="submit">
      <section class="panel form" aria-labelledby="identity-title">
        <h2 id="identity-title" class="title-section">Identité</h2>
        <div class="grid-2">
          <FormField label="Plaque en cours" required :error="request.fieldErrors.value.registration_number" v-slot="field">
            <input v-model.trim="form.registration_number" v-bind="field.attrs" class="input plate-input" maxlength="32" autocapitalize="characters" autocomplete="off" required />
          </FormField>
          <FormField label="Type de plaque" v-slot="field">
            <select v-model="form.registration_status" v-bind="field.attrs" class="select">
              <option v-for="(label, value) in registrationStatusLabels" :key="value" :value="value">{{ label }}</option>
            </select>
          </FormField>
        </div>
        <div class="segmented" role="group" aria-label="Catégorie">
          <button v-for="(label, value) in categoryLabels" :key="value" type="button" :aria-pressed="form.category === value" @click="form.category = value">{{ label }}</button>
        </div>
        <div class="grid-3">
          <FormField label="Marque" required :error="request.fieldErrors.value.make" v-slot="field">
            <input v-model.trim="form.make" v-bind="field.attrs" class="input" maxlength="64" />
          </FormField>
          <FormField label="Modèle" required :error="request.fieldErrors.value.model" v-slot="field">
            <input v-model.trim="form.model" v-bind="field.attrs" class="input" maxlength="64" />
          </FormField>
          <FormField label="Année (facultatif)" :error="request.fieldErrors.value.model_year" v-slot="field">
            <input v-model="form.model_year" v-bind="field.attrs" class="input" type="number" inputmode="numeric" min="1900" max="2100" />
          </FormField>
        </div>
        <FormField label="VIN (facultatif)" :error="request.fieldErrors.value.vin" v-slot="field">
          <input v-model.trim="form.vin" v-bind="field.attrs" class="input" maxlength="64" autocapitalize="characters" autocomplete="off" />
        </FormField>
      </section>

      <section class="panel form" aria-labelledby="contract-title">
        <div class="stack" style="gap: 2px">
          <h2 id="contract-title" class="title-section">Caractéristiques</h2>
          <p class="text-secondary text-small">Facultatif. Reprises dans le contrat de location.</p>
        </div>
        <div class="grid-2">
          <FormField label="Couleur" :error="request.fieldErrors.value.color" v-slot="field">
            <input v-model="form.color" v-bind="field.attrs" class="input" maxlength="48" autocomplete="off" />
          </FormField>
          <FormField label="Carburant" :error="request.fieldErrors.value.fuel_type" v-slot="field">
            <select v-model="form.fuel_type" v-bind="field.attrs" class="select">
              <option value="">Non renseigné</option>
              <option v-for="(label, value) in fuelTypeLabels" :key="value" :value="value">{{ label }}</option>
            </select>
          </FormField>
          <FormField label="Transmission" :error="request.fieldErrors.value.transmission" v-slot="field">
            <select v-model="form.transmission" v-bind="field.attrs" class="select">
              <option value="">Non renseignée</option>
              <option v-for="(label, value) in transmissionLabels" :key="value" :value="value">{{ label }}</option>
            </select>
          </FormField>
          <FormField label="Cylindrée (cm³)" :error="request.fieldErrors.value.engine_displacement_cc" v-slot="field">
            <input v-model="form.engine_displacement_cc" v-bind="field.attrs" class="input" type="number" inputmode="numeric" min="50" max="10000" />
          </FormField>
          <FormField label="Portes" :error="request.fieldErrors.value.doors" v-slot="field">
            <input v-model="form.doors" v-bind="field.attrs" class="input" type="number" inputmode="numeric" min="2" max="6" />
          </FormField>
        </div>
      </section>

      <section class="panel form" aria-labelledby="pricing-title">
        <h2 id="pricing-title" class="title-section">Tarification</h2>
        <div class="grid-2">
          <FormField label="Tarif quotidien (USD)" required help="Proposé pour les modèles connus, à confirmer." :error="request.fieldErrors.value.daily_rate_usd" v-slot="field">
            <input v-model="form.daily_rate_usd" v-bind="field.attrs" class="input" type="number" inputmode="decimal" min="0.01" step="0.01" required />
          </FormField>
          <FormField label="Dépôt minimum (USD)" required help="À confirmer pour chaque véhicule." :error="request.fieldErrors.value.minimum_security_deposit_usd" v-slot="field">
            <input v-model="form.minimum_security_deposit_usd" v-bind="field.attrs" class="input" type="number" inputmode="decimal" min="0" step="0.01" required />
          </FormField>
        </div>
      </section>

      <section class="panel form" aria-labelledby="availability-title">
        <h2 id="availability-title" class="title-section">Disponibilité</h2>
        <FormField label="Adresse" required :help="`Flotte actuelle : ${CLIENTELE_CAR_RENTAL_FLEET_ADDRESS}.`" :error="request.fieldErrors.value.site_id" v-slot="field">
          <select v-model="form.site_id" v-bind="field.attrs" class="select" required>
            <option value="" disabled>Choisissez une adresse autorisée</option>
            <option v-for="site in session.sites" :key="site.id" :value="site.id">{{ site.name }} - {{ site.address }}</option>
          </select>
        </FormField>
        <div class="grid-2">
          <FormField label="Kilométrage relevé" required :error="request.fieldErrors.value.latest_odometer_km" v-slot="field">
            <input v-model="form.latest_odometer_km" v-bind="field.attrs" class="input" type="number" inputmode="numeric" min="0" step="1" required />
          </FormField>
          <FormField label="État initial" v-slot="field">
            <select v-model="form.operational_status" v-bind="field.attrs" class="select">
              <option v-for="(label, value) in vehicleStatusLabels" :key="value" :value="value">{{ label }}</option>
            </select>
          </FormField>
        </div>
      </section>

      <div v-if="missing.length" class="missing" role="status">
        <strong>À compléter avant l’enregistrement</strong>
        <ul>
          <li v-for="item in missing" :key="item.field">{{ item.label }}</li>
        </ul>
      </div>
      <InlineAlert :message="request.error.value" />
      <button class="btn btn-primary btn-block" type="submit" :disabled="request.busy.value || !app.canReachServer || missing.length > 0">
        {{ request.busy.value ? 'Enregistrement' : 'Enregistrer le véhicule' }}
      </button>
    </form>
  </div>
</template>

<style scoped>
.catalog {
  display: grid;
  grid-auto-flow: column;
  grid-auto-columns: minmax(220px, 260px);
  gap: 12px;
  margin: 0 calc(var(--gutter) * -1);
  padding: 0 var(--gutter) 6px;
  overflow-x: auto;
  scroll-snap-type: x mandatory;
}

.catalog-item {
  display: grid;
  gap: 0;
  overflow: hidden;
  padding: 0;
  border: 2px solid transparent;
  border-radius: 8px;
  background: var(--surface);
  text-align: left;
  scroll-snap-align: start;
  cursor: pointer;
}

.catalog-item.selected {
  border-color: var(--accent);
}

.catalog-item img,
.catalog-placeholder {
  width: 100%;
  aspect-ratio: 16 / 10;
  object-fit: cover;
  background: var(--surface-sunken);
}

.catalog-text {
  display: grid;
  justify-items: start;
  gap: 6px;
  padding: 12px 14px 14px;
}

.review {
  color: var(--warning);
  font-weight: 600;
}

.plate-input {
  font-size: var(--text-xl);
  font-weight: 600;
  text-transform: uppercase;
}
</style>
