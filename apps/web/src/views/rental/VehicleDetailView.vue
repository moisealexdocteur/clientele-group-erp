<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import {
  fetchReservations,
  fetchVehicle,
  fetchVehicleDocuments,
  saveVehicleDocuments,
  updateVehicleActive,
  updateVehicleCommercialTerms,
  updateVehicleDetails,
  updateVehiclePhoto,
  updateVehicleRegistration,
  updateVehicleStatus,
} from '../../api/carRental'
import type {
  CarRentalReservationListEntry,
  FuelType,
  RentalCategory,
  RentalVehicle,
  RentalVehicleDocument,
  Transmission,
  VehicleDocumentType,
  VehicleOperationalStatus,
  VehicleRegistrationStatus,
} from '../../api/types'
import { useSessionStore } from '../../stores/session'
import { useAppStore } from '../../stores/app'
import { useUiStore } from '../../stores/ui'
import { useRequest } from '../../composables/useRequest'
import {
  categoryLabels,
  documentStatusTones,
  fuelTypeLabels,
  registrationStatusLabels,
  transmissionLabels,
  vehicleDocumentStatusLabels,
  vehicleDocumentTypeLabels,
  vehicleStatusLabels,
  vehicleStatusTones,
} from '../../lib/labels'
import { formatMoney } from '../../lib/money'
import { defaultMonthPeriod, formatDate } from '../../lib/time'
import { vehicleName, vehiclePlate } from '../../lib/text'
import FileCapture from '../../components/ui/FileCapture.vue'
import FormField from '../../components/ui/FormField.vue'
import ToggleSwitch from '../../components/ui/ToggleSwitch.vue'
import InlineAlert from '../../components/ui/InlineAlert.vue'
import SheetDialog from '../../components/ui/SheetDialog.vue'
import StatusPill from '../../components/ui/StatusPill.vue'
import VehicleThumb from '../../components/rental/VehicleThumb.vue'
import ReservationRow from '../../components/rental/ReservationRow.vue'

/*
 * Fiche véhicule en sections courtes : état, tarification, plaque,
 * documents et réservations. Les modifications ne sont proposées
 * qu'avec la permission de gestion de flotte.
 */
const props = defineProps<{ vehicleId: string }>()

const session = useSessionStore()
const app = useAppStore()
const ui = useUiStore()
const loading = useRequest()
const action = useRequest()

const vehicle = ref<RentalVehicle | null>(null)
const documents = ref<RentalVehicleDocument[]>([])
const reservations = ref<CarRentalReservationListEntry[]>([])
type Task = 'terms' | 'plate' | 'documents' | 'details' | 'photo' | null
const task = ref<Task>(null)

const canManage = computed(() => session.can('rental.vehicles.manage'))
const canReadReservations = computed(() => session.can('rental.reservations.read'))

const detailsForm = reactive({
  category: 'suv' as RentalCategory,
  make: '',
  model: '',
  model_year: '',
  vin: '',
  latest_odometer_km: '',
  color: '',
  fuel_type: '' as '' | FuelType,
  transmission: '' as '' | Transmission,
  engine_displacement_cc: '',
  doors: '',
})

/* Champs obligatoires de la fiche, listés avant l'enregistrement. */
const detailsMissing = computed(() => {
  const items: string[] = []
  if (!detailsForm.make.trim()) items.push('La marque')
  if (!detailsForm.model.trim()) items.push('Le modèle')
  if (detailsForm.latest_odometer_km === '' || Number(detailsForm.latest_odometer_km) < 0) items.push('Le kilométrage')
  return items
})

const termsForm = reactive({ daily_rate_usd: '', minimum_security_deposit_usd: '' })
const plateForm = reactive({ registration_number: '', registration_status: 'normal' as VehicleRegistrationStatus })
const documentsForm = reactive({
  registration_document_number: '',
  registration_issued_at: '',
  oavct_document_number: '',
  oavct_expires_at: '',
  tint_document_number: '',
  tint_expires_at: '',
})

const documentRows = computed(() =>
  (Object.keys(vehicleDocumentTypeLabels) as VehicleDocumentType[]).map((type) => {
    const status = vehicle.value?.document_statuses?.find((item) => item.type === type)
    const record = documents.value.find((item) => item.type === type)
    return {
      type,
      label: vehicleDocumentTypeLabels[type],
      status: status?.status ?? 'not_recorded',
      expiresAt: status?.expires_at ?? record?.expires_at ?? null,
      number: record?.document_number ?? null,
    }
  }),
)

async function load(): Promise<void> {
  const result = await loading.run(async () => {
    const found = await fetchVehicle(props.vehicleId)
    if (!found) throw new Error('missing')
    return found
  })
  if (!result) {
    loading.fail('Ce véhicule n’existe pas ou n’est pas accessible avec vos droits.')
    return
  }
  vehicle.value = result

  if (canManage.value) {
    const docs = await fetchVehicleDocuments(result.id).catch(() => ({ data: [] as RentalVehicleDocument[] }))
    documents.value = docs.data
  }
  if (canReadReservations.value) {
    const period = defaultMonthPeriod()
    const list = await fetchReservations({ from: period.from, to: period.to, query: vehiclePlate(result) }).catch(() => ({ data: [] }))
    reservations.value = list.data
      .filter((entry) => entry.vehicle?.id === result.id && (entry.state === 'reserved' || entry.state === 'checked_out'))
      .sort((left, right) => left.pickup_at.localeCompare(right.pickup_at))
  }
}

onMounted(load)
watch(() => props.vehicleId, load)

function applyDocuments(next: RentalVehicleDocument[]): void {
  documents.value = next
  if (!vehicle.value) return
  vehicle.value = {
    ...vehicle.value,
    document_statuses: (Object.keys(vehicleDocumentTypeLabels) as VehicleDocumentType[]).map((type) => {
      const record = next.find((item) => item.type === type)
      return { type, status: record?.status ?? 'not_recorded', expires_at: record?.expires_at ?? null }
    }),
  }
}

/* ---------- État opérationnel ---------- */

async function changeStatus(status: VehicleOperationalStatus): Promise<void> {
  if (!vehicle.value || vehicle.value.operational_status === status) return
  const confirmed = await ui.confirm({
    title: 'Changer l’état du véhicule',
    message: `${vehiclePlate(vehicle.value)} passera de « ${vehicleStatusLabels[vehicle.value.operational_status]} » à « ${vehicleStatusLabels[status]} ». Le changement est journalisé.`,
    confirmLabel: 'Changer l’état',
  })
  if (!confirmed) return
  const result = await action.run(() => updateVehicleStatus(props.vehicleId, status))
  if (!result) {
    ui.toast(action.error.value, 'danger')
    return
  }
  vehicle.value = { ...result.data, document_statuses: result.data.document_statuses ?? vehicle.value.document_statuses }
  ui.toast(`État mis à jour : ${vehicleStatusLabels[result.data.operational_status]}.`)
}

function applyVehicle(next: RentalVehicle): void {
  vehicle.value = { ...next, document_statuses: next.document_statuses ?? vehicle.value?.document_statuses }
}

/* ---------- Actif ou inactif ---------- */

async function setActive(next: boolean): Promise<void> {
  if (!vehicle.value || vehicle.value.is_active === next) return
  const confirmed = await ui.confirm({
    title: next ? 'Remettre le véhicule dans la flotte' : 'Retirer le véhicule de la flotte',
    message: next
      ? `${vehiclePlate(vehicle.value)} pourra de nouveau être réservé. Le changement est journalisé.`
      : `${vehiclePlate(vehicle.value)} ne sera plus proposé à la réservation. Un véhicule réservé ou en location ne peut pas être retiré. Le changement est journalisé.`,
    confirmLabel: next ? 'Activer' : 'Désactiver',
  })
  if (!confirmed) return
  const result = await action.run(() => updateVehicleActive(props.vehicleId, next))
  if (!result) {
    ui.toast(action.error.value, 'danger')
    return
  }
  applyVehicle(result.data)
  ui.toast(next ? 'Véhicule actif.' : 'Véhicule inactif.')
}

/* ---------- Photo ---------- */

function openPhoto(): void {
  action.reset()
  task.value = 'photo'
}

async function savePhoto(fileId: string): Promise<void> {
  const result = await action.run(() => updateVehiclePhoto(props.vehicleId, fileId))
  if (!result) return
  applyVehicle(result.data)
  task.value = null
  ui.toast('Photo du véhicule enregistrée.')
}

async function removePhoto(): Promise<void> {
  const confirmed = await ui.confirm({
    title: 'Retirer la photo',
    message: 'La photo de référence ou l’illustration de catégorie sera affichée à la place.',
    confirmLabel: 'Retirer',
  })
  if (!confirmed) return
  const result = await action.run(() => updateVehiclePhoto(props.vehicleId, null))
  if (!result) {
    ui.toast(action.error.value, 'danger')
    return
  }
  applyVehicle(result.data)
  ui.toast('Photo retirée.')
}

/* ---------- Caractéristiques ---------- */

function openDetails(): void {
  const current = vehicle.value
  if (!current) return
  Object.assign(detailsForm, {
    category: current.category,
    make: current.make ?? '',
    model: current.model ?? '',
    model_year: current.model_year?.toString() ?? '',
    vin: current.vin ?? '',
    latest_odometer_km: current.latest_odometer_km.toString(),
    color: current.color ?? '',
    fuel_type: current.fuel_type ?? '',
    transmission: current.transmission ?? '',
    engine_displacement_cc: current.engine_displacement_cc?.toString() ?? '',
    doors: current.doors?.toString() ?? '',
  })
  action.reset()
  task.value = 'details'
}

function optionalNumber(value: string): number | undefined {
  return value === '' ? undefined : Number(value)
}

async function saveDetails(): Promise<void> {
  if (detailsMissing.value.length) return
  const result = await action.run(() => updateVehicleDetails(props.vehicleId, {
    category: detailsForm.category,
    make: detailsForm.make.trim(),
    model: detailsForm.model.trim(),
    model_year: optionalNumber(detailsForm.model_year),
    vin: detailsForm.vin.trim(),
    latest_odometer_km: Number(detailsForm.latest_odometer_km),
    color: detailsForm.color.trim() || null,
    fuel_type: detailsForm.fuel_type || null,
    transmission: detailsForm.transmission || null,
    engine_displacement_cc: optionalNumber(detailsForm.engine_displacement_cc) ?? null,
    doors: optionalNumber(detailsForm.doors) ?? null,
  }))
  if (!result) return
  applyVehicle(result.data)
  task.value = null
  ui.toast('Fiche du véhicule mise à jour.')
}

/* ---------- Tarification ---------- */

function openTerms(): void {
  if (!vehicle.value) return
  Object.assign(termsForm, {
    daily_rate_usd: vehicle.value.daily_rate_usd ?? '',
    minimum_security_deposit_usd: vehicle.value.minimum_security_deposit_usd ?? '',
  })
  action.reset()
  task.value = 'terms'
}

async function saveTerms(): Promise<void> {
  if (!termsForm.daily_rate_usd || termsForm.minimum_security_deposit_usd === '') {
    action.fail('Saisissez le tarif quotidien et le dépôt minimum en USD.')
    return
  }
  const result = await action.run(() => updateVehicleCommercialTerms(props.vehicleId, {
    daily_rate_usd: Number(termsForm.daily_rate_usd),
    minimum_security_deposit_usd: Number(termsForm.minimum_security_deposit_usd),
  }))
  if (!result || !vehicle.value) return
  vehicle.value = { ...result.data, document_statuses: result.data.document_statuses ?? vehicle.value.document_statuses }
  task.value = null
  ui.toast('Tarif quotidien et dépôt minimum enregistrés.')
}

/* ---------- Plaque ---------- */

function openPlate(): void {
  if (!vehicle.value) return
  Object.assign(plateForm, {
    registration_number: vehiclePlate(vehicle.value),
    registration_status: vehicle.value.registration_status ?? 'normal',
  })
  action.reset()
  task.value = 'plate'
}

async function savePlate(): Promise<void> {
  if (!plateForm.registration_number.trim()) {
    action.fail('Saisissez la plaque en cours.', { registration_number: 'Plaque requise.' })
    return
  }
  const result = await action.run(() => updateVehicleRegistration(props.vehicleId, {
    registration_number: plateForm.registration_number.trim(),
    registration_status: plateForm.registration_status,
  }))
  if (!result || !vehicle.value) return
  vehicle.value = { ...result.data, document_statuses: result.data.document_statuses ?? vehicle.value.document_statuses }
  task.value = null
  ui.toast('Plaque mise à jour. L’ancienne plaque est conservée dans l’historique.')
}

/* ---------- Documents ---------- */

function openDocuments(): void {
  const find = (type: VehicleDocumentType) => documents.value.find((item) => item.type === type)
  Object.assign(documentsForm, {
    registration_document_number: find('registration')?.document_number ?? '',
    registration_issued_at: find('registration')?.issued_at ?? '',
    oavct_document_number: find('oavct_insurance')?.document_number ?? '',
    oavct_expires_at: find('oavct_insurance')?.expires_at ?? '',
    tint_document_number: find('tint_permit')?.document_number ?? '',
    tint_expires_at: find('tint_permit')?.expires_at ?? '',
  })
  action.reset()
  task.value = 'documents'
}

async function saveDocuments(): Promise<void> {
  const payload: Parameters<typeof saveVehicleDocuments>[1] = []
  if (documentsForm.registration_document_number.trim() || documentsForm.registration_issued_at) {
    payload.push({ type: 'registration', document_number: documentsForm.registration_document_number.trim(), issued_at: documentsForm.registration_issued_at })
  }
  if (documentsForm.oavct_document_number.trim() || documentsForm.oavct_expires_at) {
    payload.push({ type: 'oavct_insurance', document_number: documentsForm.oavct_document_number.trim(), expires_at: documentsForm.oavct_expires_at })
  }
  if (documentsForm.tint_document_number.trim() || documentsForm.tint_expires_at) {
    payload.push({ type: 'tint_permit', document_number: documentsForm.tint_document_number.trim(), expires_at: documentsForm.tint_expires_at })
  }
  if (!payload.length) {
    action.fail('Saisissez au moins une référence ou une date avant d’enregistrer.')
    return
  }
  const result = await action.run(() => saveVehicleDocuments(props.vehicleId, payload))
  if (!result) return
  applyDocuments(result.data)
  task.value = null
  ui.toast('Documents enregistrés.')
}
</script>

<template>
  <RouterLink class="back-link" :to="{ name: 'rental.vehicles' }">
    <span aria-hidden="true" class="back-arrow"></span>Véhicules
  </RouterLink>

  <InlineAlert :message="loading.error.value" />
  <div v-if="loading.busy.value && !vehicle" class="skeleton" style="height: 420px"></div>

  <div v-else-if="vehicle" class="vehicle-detail">
    <header class="vehicle-hero">
      <div class="hero-photo">
        <VehicleThumb size="lg" :vehicle="vehicle" :alt="vehicleName(vehicle)" />
        <div v-if="canManage" class="btn-row">
          <button class="btn btn-secondary" type="button" :disabled="!app.canReachServer" @click="openPhoto">
            {{ vehicle.photo ? 'Changer la photo' : 'Ajouter une photo' }}
          </button>
          <button v-if="vehicle.photo" class="btn btn-ghost" type="button" :disabled="action.busy.value || !app.canReachServer" @click="removePhoto">Retirer la photo</button>
        </div>
      </div>
      <div class="vehicle-hero-text">
        <div class="pills">
          <StatusPill :tone="vehicleStatusTones[vehicle.operational_status]" :label="vehicleStatusLabels[vehicle.operational_status]" />
          <StatusPill v-if="!vehicle.is_active" tone="neutral" label="Inactif" />
        </div>
        <h1 class="display display-xxl hero-plate">{{ vehiclePlate(vehicle) }}</h1>
        <p class="hero-name">{{ vehicleName(vehicle) }}</p>
        <p class="text-secondary">{{ categoryLabels[vehicle.category] }} - {{ vehicle.site?.name ?? 'Adresse non disponible' }}</p>
        <p v-if="!vehicle.photo && vehicle.reference_photo" class="text-muted text-small">Photo de référence : {{ vehicle.reference_photo.label }}</p>
      </div>
    </header>

    <div class="detail-grid">
      <div class="stack-lg">
        <section class="panel" aria-labelledby="status-title">
          <div class="panel-header">
            <h2 id="status-title" class="title-section">État opérationnel</h2>
            <ToggleSwitch
              v-if="canManage"
              :model-value="vehicle.is_active"
              on-label="Actif"
              off-label="Inactif"
              :disabled="action.busy.value || !app.canReachServer"
              @update:model-value="setActive"
            />
          </div>
          <p v-if="!vehicle.is_active" class="text-secondary text-small">Ce véhicule n’est pas proposé à la réservation.</p>
          <div v-if="canManage" class="status-grid" role="group" aria-label="Changer l’état">
            <button
              v-for="(label, status) in vehicleStatusLabels"
              :key="status"
              type="button"
              class="status-option"
              :aria-pressed="vehicle.operational_status === status"
              :disabled="action.busy.value || !app.canReachServer"
              @click="changeStatus(status)"
            >
              <span class="pill" :class="`pill-${vehicleStatusTones[status]}`">{{ label }}</span>
            </button>
          </div>
          <p v-else class="text-secondary">{{ vehicleStatusLabels[vehicle.operational_status] }}</p>
        </section>

        <section v-if="canReadReservations" class="stack" aria-labelledby="vehicle-reservations-title">
          <h2 id="vehicle-reservations-title" class="title-section">Réservations actives ce mois-ci</h2>
          <div v-if="reservations.length" class="list">
            <ReservationRow
              v-for="entry in reservations"
              :key="entry.id"
              :entry="entry"
              :focus="entry.state === 'checked_out' ? 'due' : 'pickup'"
            />
          </div>
          <p v-else class="text-muted">Aucune réservation active pour ce véhicule sur le mois en cours.</p>
        </section>

        <section class="panel" aria-labelledby="documents-title">
          <div class="panel-header">
            <h2 id="documents-title" class="title-section">Documents</h2>
            <button v-if="canManage" class="btn btn-ghost" type="button" :disabled="!app.canReachServer" @click="openDocuments">Modifier</button>
          </div>
          <dl class="facts">
            <div v-for="row in documentRows" :key="row.type">
              <dt>
                {{ row.label }}
                <span v-if="row.expiresAt" class="doc-date">expire le {{ formatDate(row.expiresAt) }}</span>
              </dt>
              <dd><StatusPill :tone="documentStatusTones[row.status]" :label="vehicleDocumentStatusLabels[row.status]" /></dd>
            </div>
          </dl>
        </section>
      </div>

      <aside class="stack-lg">
        <section class="panel" aria-labelledby="pricing-title">
          <div class="panel-header">
            <h2 id="pricing-title" class="title-section">Tarification</h2>
            <button v-if="canManage" class="btn btn-ghost" type="button" :disabled="!app.canReachServer" @click="openTerms">Modifier</button>
          </div>
          <p class="price">
            <span class="display display-xl">{{ formatMoney(vehicle.daily_rate_usd, 'USD') }}</span>
            <span class="text-secondary">par jour</span>
          </p>
          <dl class="facts">
            <div>
              <dt>Dépôt minimum</dt>
              <dd>{{ formatMoney(vehicle.minimum_security_deposit_usd, 'USD') }}</dd>
            </div>
          </dl>
        </section>

        <section class="panel" aria-labelledby="identity-title">
          <div class="panel-header">
            <h2 id="identity-title" class="title-section">Identité</h2>
            <button v-if="canManage" class="btn btn-ghost" type="button" :disabled="!app.canReachServer" @click="openDetails">Modifier</button>
          </div>
          <dl class="facts">
            <div>
              <dt>Type de plaque</dt>
              <dd>{{ registrationStatusLabels[vehicle.registration_status ?? 'normal'] }}</dd>
            </div>
            <div>
              <dt>Kilométrage relevé</dt>
              <dd>{{ vehicle.latest_odometer_km.toLocaleString('fr-FR') }} km</dd>
            </div>
            <div v-if="vehicle.model_year">
              <dt>Année</dt>
              <dd>{{ vehicle.model_year }}</dd>
            </div>
            <div>
              <dt>Couleur</dt>
              <dd>{{ vehicle.color || 'Non renseignée' }}</dd>
            </div>
            <div>
              <dt>Carburant</dt>
              <dd>{{ vehicle.fuel_type ? fuelTypeLabels[vehicle.fuel_type] : 'Non renseigné' }}</dd>
            </div>
            <div>
              <dt>Transmission</dt>
              <dd>{{ vehicle.transmission ? transmissionLabels[vehicle.transmission] : 'Non renseignée' }}</dd>
            </div>
            <div v-if="vehicle.engine_displacement_cc">
              <dt>Cylindrée</dt>
              <dd>{{ vehicle.engine_displacement_cc.toLocaleString('fr-FR') }} cm³</dd>
            </div>
            <div v-if="vehicle.doors">
              <dt>Portes</dt>
              <dd>{{ vehicle.doors }}</dd>
            </div>
            <div v-if="vehicle.vin">
              <dt>Numéro de série</dt>
              <dd class="mono">{{ vehicle.vin }}</dd>
            </div>
            <div>
              <dt>Adresse</dt>
              <dd>{{ vehicle.site?.name ?? 'Non disponible' }}</dd>
            </div>
          </dl>
          <button v-if="canManage" class="btn btn-secondary" type="button" :disabled="!app.canReachServer" @click="openPlate">Remplacer la plaque</button>
        </section>
      </aside>
    </div>
  </div>

  <SheetDialog :open="task === 'terms'" title="Tarif et dépôt" description="Les nouvelles conditions s’appliquent aux réservations futures." :locked="action.busy.value" @close="task = null">
    <form id="terms-form" class="form" novalidate @submit.prevent="saveTerms">
      <FormField label="Tarif quotidien (USD)" required :error="action.fieldErrors.value.daily_rate_usd" v-slot="field">
        <input v-model="termsForm.daily_rate_usd" v-bind="field.attrs" class="input" type="number" inputmode="decimal" min="0.01" step="0.01" required />
      </FormField>
      <FormField label="Dépôt minimum (USD)" required :error="action.fieldErrors.value.minimum_security_deposit_usd" v-slot="field">
        <input v-model="termsForm.minimum_security_deposit_usd" v-bind="field.attrs" class="input" type="number" inputmode="decimal" min="0" step="0.01" required />
      </FormField>
      <InlineAlert :message="action.error.value" />
    </form>
    <template #footer>
      <button class="btn btn-secondary" type="button" :disabled="action.busy.value" @click="task = null">Fermer</button>
      <button class="btn btn-primary" type="submit" form="terms-form" :disabled="action.busy.value || !termsForm.daily_rate_usd || termsForm.minimum_security_deposit_usd === ''">Enregistrer</button>
    </template>
  </SheetDialog>

  <SheetDialog :open="task === 'photo'" title="Photo du véhicule" description="Utilisez une photo réelle du véhicule. Elle remplace la photo de référence dans l’application." :locked="action.busy.value" @close="task = null">
    <div v-if="vehicle" class="form">
      <FileCapture
        purpose="vehicle_photo"
        :site-id="vehicle.site_id"
        label="Photo"
        help="JPEG, PNG ou WebP, 10 Mo au plus. Évitez que la plaque soit lisible si la photo peut être envoyée au client."
        :disabled="action.busy.value"
        @uploaded="(file) => savePhoto(file.id)"
      />
      <InlineAlert :message="action.error.value" />
    </div>
    <template #footer>
      <button class="btn btn-secondary" type="button" :disabled="action.busy.value" @click="task = null">Fermer</button>
    </template>
  </SheetDialog>

  <SheetDialog :open="task === 'details'" title="Identité du véhicule" description="Ces informations sont reprises dans le contrat de location. Les champs marqués * sont obligatoires." :locked="action.busy.value" @close="task = null">
    <form id="details-form" class="form" novalidate @submit.prevent="saveDetails">
      <FormField label="Catégorie" required :error="action.fieldErrors.value.category" v-slot="field">
        <select v-model="detailsForm.category" v-bind="field.attrs" class="select">
          <option v-for="(label, value) in categoryLabels" :key="value" :value="value">{{ label }}</option>
        </select>
      </FormField>
      <div class="grid-2">
        <FormField label="Marque" required :error="action.fieldErrors.value.make" v-slot="field">
          <input v-model="detailsForm.make" v-bind="field.attrs" class="input" maxlength="64" autocomplete="off" />
        </FormField>
        <FormField label="Modèle" required :error="action.fieldErrors.value.model" v-slot="field">
          <input v-model="detailsForm.model" v-bind="field.attrs" class="input" maxlength="64" autocomplete="off" />
        </FormField>
        <FormField label="Année" :error="action.fieldErrors.value.model_year" v-slot="field">
          <input v-model="detailsForm.model_year" v-bind="field.attrs" class="input" type="number" inputmode="numeric" min="1900" max="2100" />
        </FormField>
        <FormField label="Kilométrage relevé" required :error="action.fieldErrors.value.latest_odometer_km" v-slot="field">
          <input v-model="detailsForm.latest_odometer_km" v-bind="field.attrs" class="input" type="number" inputmode="numeric" min="0" />
        </FormField>
        <FormField label="Couleur" :error="action.fieldErrors.value.color" v-slot="field">
          <input v-model="detailsForm.color" v-bind="field.attrs" class="input" maxlength="48" autocomplete="off" />
        </FormField>
        <FormField label="Carburant" :error="action.fieldErrors.value.fuel_type" v-slot="field">
          <select v-model="detailsForm.fuel_type" v-bind="field.attrs" class="select">
            <option value="">Non renseigné</option>
            <option v-for="(label, value) in fuelTypeLabels" :key="value" :value="value">{{ label }}</option>
          </select>
        </FormField>
        <FormField label="Transmission" :error="action.fieldErrors.value.transmission" v-slot="field">
          <select v-model="detailsForm.transmission" v-bind="field.attrs" class="select">
            <option value="">Non renseignée</option>
            <option v-for="(label, value) in transmissionLabels" :key="value" :value="value">{{ label }}</option>
          </select>
        </FormField>
        <FormField label="Cylindrée (cm³)" :error="action.fieldErrors.value.engine_displacement_cc" v-slot="field">
          <input v-model="detailsForm.engine_displacement_cc" v-bind="field.attrs" class="input" type="number" inputmode="numeric" min="50" max="10000" />
        </FormField>
        <FormField label="Portes" :error="action.fieldErrors.value.doors" v-slot="field">
          <input v-model="detailsForm.doors" v-bind="field.attrs" class="input" type="number" inputmode="numeric" min="2" max="6" />
        </FormField>
        <FormField label="Numéro de série" :error="action.fieldErrors.value.vin" v-slot="field">
          <input v-model="detailsForm.vin" v-bind="field.attrs" class="input mono" maxlength="64" autocapitalize="characters" autocomplete="off" />
        </FormField>
      </div>
      <div v-if="detailsMissing.length" class="missing" role="status">
        <strong>À compléter</strong>
        <ul>
          <li v-for="item in detailsMissing" :key="item">{{ item }}</li>
        </ul>
      </div>
      <InlineAlert :message="action.error.value" />
    </form>
    <template #footer>
      <button class="btn btn-secondary" type="button" :disabled="action.busy.value" @click="task = null">Fermer</button>
      <button class="btn btn-primary" type="submit" form="details-form" :disabled="action.busy.value || detailsMissing.length > 0">Enregistrer</button>
    </template>
  </SheetDialog>

  <SheetDialog :open="task === 'plate'" title="Remplacer la plaque" description="L’ancienne plaque reste dans l’historique du véhicule." :locked="action.busy.value" @close="task = null">
    <form id="plate-form" class="form" novalidate @submit.prevent="savePlate">
      <FormField label="Plaque en cours" required :error="action.fieldErrors.value.registration_number" v-slot="field">
        <input v-model.trim="plateForm.registration_number" v-bind="field.attrs" class="input" maxlength="32" autocapitalize="characters" required />
      </FormField>
      <FormField label="Type de plaque" v-slot="field">
        <select v-model="plateForm.registration_status" v-bind="field.attrs" class="select">
          <option v-for="(label, value) in registrationStatusLabels" :key="value" :value="value">{{ label }}</option>
        </select>
      </FormField>
      <InlineAlert :message="action.error.value" />
    </form>
    <template #footer>
      <button class="btn btn-secondary" type="button" :disabled="action.busy.value" @click="task = null">Fermer</button>
      <button class="btn btn-primary" type="submit" form="plate-form" :disabled="action.busy.value">Enregistrer la plaque</button>
    </template>
  </SheetDialog>

  <SheetDialog :open="task === 'documents'" title="Documents du véhicule" description="La flotte est équipée de vitres teintées : renseignez le permis et son expiration." :locked="action.busy.value" @close="task = null">
    <form id="documents-form" class="form" novalidate @submit.prevent="saveDocuments">
      <fieldset class="form-group">
        <legend>Immatriculation</legend>
        <div class="grid-2">
          <FormField label="Référence" v-slot="field">
            <input v-model.trim="documentsForm.registration_document_number" v-bind="field.attrs" class="input" maxlength="100" />
          </FormField>
          <FormField label="Date de délivrance" v-slot="field">
            <input v-model="documentsForm.registration_issued_at" v-bind="field.attrs" class="input" type="date" />
          </FormField>
        </div>
      </fieldset>
      <fieldset class="form-group">
        <legend>Assurance OAVCT</legend>
        <div class="grid-2">
          <FormField label="Référence" v-slot="field">
            <input v-model.trim="documentsForm.oavct_document_number" v-bind="field.attrs" class="input" maxlength="100" />
          </FormField>
          <FormField label="Expiration" v-slot="field">
            <input v-model="documentsForm.oavct_expires_at" v-bind="field.attrs" class="input" type="date" />
          </FormField>
        </div>
      </fieldset>
      <fieldset class="form-group">
        <legend>Permis de vitres teintées</legend>
        <div class="grid-2">
          <FormField label="Référence" v-slot="field">
            <input v-model.trim="documentsForm.tint_document_number" v-bind="field.attrs" class="input" maxlength="100" />
          </FormField>
          <FormField label="Expiration" v-slot="field">
            <input v-model="documentsForm.tint_expires_at" v-bind="field.attrs" class="input" type="date" />
          </FormField>
        </div>
      </fieldset>
      <InlineAlert :message="action.error.value" />
    </form>
    <template #footer>
      <button class="btn btn-secondary" type="button" :disabled="action.busy.value" @click="task = null">Fermer</button>
      <button class="btn btn-primary" type="submit" form="documents-form" :disabled="action.busy.value">Enregistrer les documents</button>
    </template>
  </SheetDialog>
</template>

<style scoped>
.back-link {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  min-height: 40px;
  margin: 0 0 8px -4px;
  padding: 0 8px 0 4px;
  border-radius: 4px;
  color: var(--accent);
  font-weight: 600;
  text-decoration: none;
}

.back-arrow {
  width: 9px;
  height: 9px;
  border-left: 2.5px solid currentColor;
  border-bottom: 2.5px solid currentColor;
  transform: rotate(45deg);
}

.vehicle-detail {
  display: grid;
  gap: 28px;
}

.vehicle-hero {
  display: grid;
  gap: 18px;
}

.hero-photo {
  display: grid;
  gap: 12px;
}

.vehicle-hero-text {
  display: grid;
  gap: 8px;
  align-content: end;
}

.pills {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.hero-plate {
  overflow-wrap: anywhere;
}

.hero-name {
  font-size: var(--text-xl);
  font-weight: 600;
}

.status-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
  gap: 8px;
}

.status-option {
  display: flex;
  align-items: center;
  min-height: 56px;
  padding: 0 12px;
  border: 2px solid var(--line);
  border-radius: 4px;
  background: var(--surface);
  cursor: pointer;
}

.status-option[aria-pressed='true'] {
  border-color: var(--ink);
}

.doc-date {
  display: block;
  margin-top: 2px;
  font-size: var(--text-xs);
}

.price {
  display: grid;
  gap: 4px;
}

.detail-grid {
  display: grid;
  gap: 28px;
  align-items: start;
}

@media (min-width: 720px) {
  .vehicle-hero {
    grid-template-columns: minmax(260px, 0.9fr) minmax(0, 1.1fr);
    align-items: end;
  }
}

@media (min-width: 1100px) {
  .detail-grid {
    grid-template-columns: minmax(0, 1.5fr) minmax(300px, 1fr);
  }
}
</style>
