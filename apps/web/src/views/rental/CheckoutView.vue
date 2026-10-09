<script setup lang="ts">
import { computed, nextTick, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { checkOutReservation, fetchReservation, uploadFile, type UploadedFileRef } from '../../api/carRental'
import { errorMessage, fieldErrors } from '../../api/client'
import type { CarRentalReservation, FuelLevel, RentalAccessory } from '../../api/types'
import { useSessionStore } from '../../stores/session'
import { useAppStore } from '../../stores/app'
import { useUiStore } from '../../stores/ui'
import { useRequest } from '../../composables/useRequest'
import { accessoryLabels, fuelLevelLabels } from '../../lib/labels'
import { countryOptions, needsSubdivision, subdivisionLabel, SUBDIVISIONS } from '../../lib/countries'
import { formatMoney } from '../../lib/money'
import { formatDateTime, rentalDays, toDateInput } from '../../lib/time'
import { vehicleName, vehiclePlate } from '../../lib/text'
import PageHeader from '../../components/ui/PageHeader.vue'
import FormField from '../../components/ui/FormField.vue'
import FileCapture from '../../components/ui/FileCapture.vue'
import InlineAlert from '../../components/ui/InlineAlert.vue'
import PrivateImage from '../../components/ui/PrivateImage.vue'
import SignaturePad from '../../components/ui/SignaturePad.vue'
import DamageSketch from '../../components/rental/DamageSketch.vue'
import type { DamageMark } from '../../lib/damageSketch'

/*
 * Mise en circulation en trois étapes : conducteur et permis, fiche de
 * sortie, puis contrat et signatures. Les montants viennent de la
 * réservation ; le bouton final reste grisé tant qu'un élément manque.
 */
const props = defineProps<{ reservationId: string }>()

const session = useSessionStore()
const app = useAppStore()
const ui = useUiStore()
const router = useRouter()
const loading = useRequest()

const reservation = ref<CarRentalReservation | null>(null)
type Step = 'driver' | 'sheet' | 'contract'
const step = ref<Step>('driver')
const steps: Array<{ key: Step; label: string }> = [
  { key: 'driver', label: '1. Permis' },
  { key: 'sheet', label: '2. Sortie' },
  { key: 'contract', label: '3. Signer' },
]

const countries = countryOptions()
const fuelLevels = Object.keys(fuelLevelLabels).map(Number) as FuelLevel[]
const accessoryKeys = Object.keys(accessoryLabels) as RentalAccessory[]

const form = reactive({
  driver_full_name: '',
  driver_license_number: '',
  driver_license_expires_at: '',
  driver_license_country: 'HT',
  driver_license_subdivision: '',
  driver_license_front_file_id: '',
  driver_license_back_file_id: '',
  driver_license_verified: false,
  with_additional_driver: false,
  additional_driver_name: '',
  additional_driver_license_number: '',
  odometer_km: '',
  fuel_level_percent: null as FuelLevel | null,
  accessories: ['spare_tire', 'jack', 'wheel_wrench', 'vehicle_documents'] as RentalAccessory[],
  damage_notes: '',
  terms_accepted: false,
  company_signer_name: '',
})

const photos = ref<UploadedFileRef[]>([])
const damageMarks = ref<DamageMark[]>([])
const photoKey = ref(0)
const customerSigned = ref(false)
const companySigned = ref(false)
const customerPad = ref<InstanceType<typeof SignaturePad> | null>(null)
const companyPad = ref<InstanceType<typeof SignaturePad> | null>(null)

const busy = ref(false)
const progress = ref('')
const error = ref('')
const errors = ref<Record<string, string>>({})

const terms = computed(() => session.context?.company.legal?.rental_contract_terms?.trim() ?? '')
const requirements = computed(() => reservation.value?.checkout_requirements ?? null)
const days = computed(() => (reservation.value ? rentalDays(reservation.value.pickup_at, reservation.value.due_at) : 0))
const rentalAmount = computed(() => Math.round(Number(reservation.value?.daily_rate ?? 0) * days.value * 100) / 100)
const subdivisionOptions = computed(() => SUBDIVISIONS[form.driver_license_country] ?? null)
const lastOdometer = computed(() => reservation.value?.vehicle?.latest_odometer_km ?? 0)
const licenseExpiresBeforeReturn = computed(() =>
  Boolean(form.driver_license_expires_at && reservation.value && form.driver_license_expires_at < toDateInput(reservation.value.due_at)),
)

onMounted(async () => {
  // Les conditions du contrat peuvent avoir été saisies après la connexion.
  const [result] = await Promise.all([
    loading.run(() => fetchReservation(props.reservationId)),
    session.refreshContext().catch(() => undefined),
  ])
  if (!result) return
  const data = result.data
  reservation.value = data
  if (data.state !== 'reserved') {
    loading.fail('Cette réservation n’est plus en attente de remise.')
    return
  }
  form.driver_full_name = data.driver_full_name ?? data.customer?.display_name ?? ''
  form.driver_license_expires_at = data.driver_license_expires_at ?? ''
  form.odometer_km = String(data.vehicle?.latest_odometer_km ?? '')
  form.company_signer_name = session.user?.name ?? ''
})

function onCountryChange(): void {
  form.driver_license_subdivision = ''
}

function toggleAccessory(key: RentalAccessory): void {
  form.accessories = form.accessories.includes(key)
    ? form.accessories.filter((item) => item !== key)
    : [...form.accessories, key]
}

function addPhoto(file: UploadedFileRef): void {
  photos.value = [...photos.value, file]
  photoKey.value += 1
}

function removePhoto(id: string): void {
  photos.value = photos.value.filter((photo) => photo.id !== id)
}

/* ---------- Éléments manquants, par étape ---------- */

const driverMissing = computed(() => {
  const items: string[] = []
  if (!form.driver_full_name.trim()) items.push('Le nom complet du conducteur')
  if (!form.driver_license_number.trim()) items.push('Le numéro du permis')
  if (!form.driver_license_country) items.push('Le pays émetteur du permis')
  if (needsSubdivision(form.driver_license_country) && !form.driver_license_subdivision.trim()) {
    items.push(`${subdivisionLabel(form.driver_license_country)} émetteur du permis`)
  }
  if (!form.driver_license_expires_at) items.push('La date d’expiration du permis')
  if (!form.driver_license_front_file_id) items.push('La photo du recto du permis')
  if (!form.driver_license_back_file_id) items.push('La photo du verso du permis')
  if (!form.driver_license_verified) items.push('La vérification de l’original du permis')
  if (form.with_additional_driver) {
    if (!form.additional_driver_name.trim()) items.push('Le nom du conducteur additionnel')
    if (!form.additional_driver_license_number.trim()) items.push('Le permis du conducteur additionnel')
  }
  return items
})

const sheetMissing = computed(() => {
  const items: string[] = []
  if (form.odometer_km === '') items.push('Le kilométrage au compteur')
  else if (Number(form.odometer_km) < lastOdometer.value) items.push(`Un kilométrage d’au moins ${lastOdometer.value.toLocaleString('fr-FR')} km`)
  if (form.fuel_level_percent === null) items.push('Le niveau de carburant')
  return items
})

const contractMissing = computed(() => {
  const items: string[] = []
  if (!terms.value) items.push('Les conditions du contrat (configuration de la société)')
  if (!form.terms_accepted) items.push('L’acceptation des conditions par le client')
  if (!customerSigned.value) items.push('La signature du client')
  if (!form.company_signer_name.trim()) items.push('Le nom de la personne qui signe pour le loueur')
  if (!companySigned.value) items.push('La signature pour le loueur')
  return items
})

const paymentMissing = computed(() => {
  const items: string[] = []
  const value = requirements.value
  if (!value) return items
  if (!value.approved_rental_payment) items.push('Un paiement de location approuvé')
  if (!value.security_deposit_satisfied) items.push(`Le dépôt de garantie (${formatMoney(value.minimum_security_deposit_usd, 'USD')} au minimum)`)
  return items
})

const allMissing = computed(() => [...paymentMissing.value, ...driverMissing.value, ...sheetMissing.value, ...contractMissing.value])

const stepMissing = computed(() => {
  if (step.value === 'driver') return driverMissing.value
  if (step.value === 'sheet') return sheetMissing.value
  return [...contractMissing.value, ...paymentMissing.value]
})

function stepDone(key: Step): boolean {
  if (key === 'driver') return driverMissing.value.length === 0
  if (key === 'sheet') return sheetMissing.value.length === 0
  return contractMissing.value.length === 0
}

async function goTo(next: Step): Promise<void> {
  step.value = next
  await nextTick()
  window.scrollTo({ top: 0 })
}

/* ---------- Envoi ---------- */

/** Étape à rouvrir pour corriger un champ refusé par le serveur. */
function stepForField(field: string): Step {
  if (field.startsWith('driver_') || field.startsWith('additional_')) return 'driver'
  if (['odometer_km', 'fuel_level_percent', 'accessories', 'damage_notes'].includes(field) || field.startsWith('inspection_')) return 'sheet'
  return 'contract'
}

async function uploadSignature(pad: InstanceType<typeof SignaturePad> | null, name: string): Promise<string> {
  const blob = await pad?.toBlob()
  if (!blob || !reservation.value) throw new Error('La signature est vide. Signez de nouveau.')
  const result = await uploadFile('signature', reservation.value.site_id, blob, name)
  return result.data.id
}

async function submit(): Promise<void> {
  const current = reservation.value
  if (!current || allMissing.value.length || busy.value) return
  busy.value = true
  error.value = ''
  errors.value = {}

  let checkedOut: CarRentalReservation | null = null
  try {
    progress.value = 'Enregistrement des signatures'
    const [customerSignatureId, companySignatureId] = await Promise.all([
      uploadSignature(customerPad.value, 'signature-client.png'),
      uploadSignature(companyPad.value, 'signature-loueur.png'),
    ])

    progress.value = 'Mise en circulation'
    const result = await checkOutReservation(current, {
      driver_full_name: form.driver_full_name.trim(),
      driver_license_number: form.driver_license_number.trim(),
      driver_license_expires_at: form.driver_license_expires_at,
      driver_license_country: form.driver_license_country,
      driver_license_subdivision: needsSubdivision(form.driver_license_country) ? form.driver_license_subdivision.trim() : undefined,
      driver_license_front_file_id: form.driver_license_front_file_id,
      driver_license_back_file_id: form.driver_license_back_file_id,
      driver_license_verified: form.driver_license_verified,
      additional_driver_name: form.with_additional_driver ? form.additional_driver_name.trim() : undefined,
      additional_driver_license_number: form.with_additional_driver ? form.additional_driver_license_number.trim() : undefined,
      odometer_km: Number(form.odometer_km),
      fuel_level_percent: form.fuel_level_percent as FuelLevel,
      accessories: form.accessories,
      damage_notes: form.damage_notes.trim() || undefined,
      inspection_photo_file_ids: photos.value.map((photo) => photo.id),
      damage_marks: damageMarks.value,
      terms_accepted: form.terms_accepted,
      customer_signature_file_id: customerSignatureId,
      company_signature_file_id: companySignatureId,
      company_signer_name: form.company_signer_name.trim(),
      defer_customer_notification: true,
    })
    checkedOut = result.data
  } catch (caught) {
    error.value = caught instanceof Error && !('status' in caught) ? caught.message : errorMessage(caught)
    errors.value = fieldErrors(caught)
    const first = Object.keys(errors.value)[0]
    if (first) await goTo(stepForField(first))
    busy.value = false
    progress.value = ''
    return
  }

  // La location est en circulation. Le contrat peut être émis de nouveau
  // depuis le détail si sa création échoue ici.
  progress.value = 'Création du contrat PDF'
  try {
    const issued = await (await import('../../components/rental/issueContract')).issueContract(checkedOut)
    ui.toast(issued.sent
      ? 'Location mise en circulation. Le contrat signé a été envoyé au client.'
      : 'Location mise en circulation. Le contrat signé est enregistré.')
  } catch {
    ui.toast('Location mise en circulation. Le contrat n’a pas pu être créé : utilisez « Créer le contrat PDF » dans la réservation.', 'danger')
  }
  busy.value = false
  await router.replace({ name: 'rental.reservation', params: { reservationId: props.reservationId } })
}
</script>

<template>
  <PageHeader
    :title="reservation ? `Mise en circulation ${reservation.number}` : 'Mise en circulation'"
    description="Les champs marqués * sont obligatoires."
    :back="{ name: 'rental.reservation', params: { reservationId } }"
    back-label="Réservation"
  />

  <InlineAlert :message="loading.error.value" />
  <div v-if="loading.busy.value" class="skeleton" style="height: 420px"></div>

  <div v-else-if="reservation && reservation.state === 'reserved'" class="checkout">
    <nav class="segmented steps" aria-label="Étapes">
      <button
        v-for="item in steps"
        :key="item.key"
        type="button"
        :aria-pressed="step === item.key"
        :class="{ done: stepDone(item.key) }"
        @click="goTo(item.key)"
      >
        {{ item.label }}
      </button>
    </nav>

    <section class="panel summary" aria-label="Résumé">
      <strong>{{ vehicleName(reservation.vehicle) }}</strong>
      <span v-if="reservation.vehicle" class="plate">{{ vehiclePlate(reservation.vehicle) }}</span>
      <span class="text-secondary text-small">
        {{ formatDateTime(reservation.pickup_at) }} au {{ formatDateTime(reservation.due_at) }} - {{ days }} j -
        {{ formatMoney(rentalAmount, reservation.currency) }}
      </span>
    </section>

    <p v-if="paymentMissing.length" class="alert alert-warning">
      Avant la remise, il manque : {{ paymentMissing.join(', ').toLowerCase() }}. Revenez à la réservation pour les encaisser.
    </p>

    <!-- Étape 1 : conducteur et permis -->
    <form v-show="step === 'driver'" class="stack-lg" novalidate @submit.prevent="goTo('sheet')">
      <section class="panel form">
        <h2 class="title-section">Conducteur principal</h2>
        <FormField label="Nom complet" required :error="errors.driver_full_name" v-slot="field">
          <input v-model="form.driver_full_name" v-bind="field.attrs" class="input" maxlength="160" autocomplete="off" />
        </FormField>
        <div class="grid-2">
          <FormField label="Numéro du permis" required :error="errors.driver_license_number" v-slot="field">
            <input v-model="form.driver_license_number" v-bind="field.attrs" class="input mono" maxlength="128" autocomplete="off" autocapitalize="characters" />
          </FormField>
          <FormField
            label="Expiration du permis"
            required
            :help="licenseExpiresBeforeReturn ? 'Attention : le permis expire avant la date de retour prévue.' : undefined"
            :error="errors.driver_license_expires_at"
            v-slot="field"
          >
            <input v-model="form.driver_license_expires_at" v-bind="field.attrs" class="input" type="date" />
          </FormField>
          <FormField label="Pays émetteur" required :error="errors.driver_license_country" v-slot="field">
            <select v-model="form.driver_license_country" v-bind="field.attrs" class="select" @change="onCountryChange">
              <optgroup label="Fréquents">
                <option v-for="country in countries.frequent" :key="country.code" :value="country.code">{{ country.name }}</option>
              </optgroup>
              <optgroup label="Tous les pays">
                <option v-for="country in countries.others" :key="country.code" :value="country.code">{{ country.name }}</option>
              </optgroup>
            </select>
          </FormField>
          <FormField
            v-if="needsSubdivision(form.driver_license_country)"
            :label="subdivisionLabel(form.driver_license_country)"
            required
            :error="errors.driver_license_subdivision"
            v-slot="field"
          >
            <select v-if="subdivisionOptions" v-model="form.driver_license_subdivision" v-bind="field.attrs" class="select">
              <option value="" disabled>Choisissez</option>
              <option v-for="name in subdivisionOptions" :key="name" :value="name">{{ name }}</option>
            </select>
            <input v-else v-model="form.driver_license_subdivision" v-bind="field.attrs" class="input" maxlength="64" />
          </FormField>
        </div>
        <p v-if="!needsSubdivision(form.driver_license_country)" class="text-secondary text-small">
          Permis national : aucune province ou État à indiquer.
        </p>
      </section>

      <section class="panel form">
        <h2 class="title-section">Photos du permis</h2>
        <div class="grid-2">
          <FileCapture
            purpose="driver_license_front"
            :site-id="reservation.site_id"
            label="Recto"
            required
            help="Photo nette, sans reflet."
            :error="errors.driver_license_front_file_id"
            @uploaded="(file) => (form.driver_license_front_file_id = file.id)"
            @cleared="form.driver_license_front_file_id = ''"
          />
          <FileCapture
            purpose="driver_license_back"
            :site-id="reservation.site_id"
            label="Verso"
            required
            help="Photo nette, sans reflet."
            :error="errors.driver_license_back_file_id"
            @uploaded="(file) => (form.driver_license_back_file_id = file.id)"
            @cleared="form.driver_license_back_file_id = ''"
          />
        </div>
        <label class="check">
          <input v-model="form.driver_license_verified" type="checkbox" />
          <span>J’ai vérifié l’original du permis de conduire.<span class="required" aria-hidden="true">*</span></span>
        </label>
        <span v-if="errors.driver_license_verified" class="field-error" role="alert">{{ errors.driver_license_verified }}</span>
      </section>

      <section class="panel form">
        <label class="check">
          <input v-model="form.with_additional_driver" type="checkbox" />
          <span>Ajouter un conducteur additionnel</span>
        </label>
        <div v-if="form.with_additional_driver" class="grid-2">
          <FormField label="Nom complet" required :error="errors.additional_driver_name" v-slot="field">
            <input v-model="form.additional_driver_name" v-bind="field.attrs" class="input" maxlength="160" autocomplete="off" />
          </FormField>
          <FormField label="Numéro du permis" required :error="errors.additional_driver_license_number" v-slot="field">
            <input v-model="form.additional_driver_license_number" v-bind="field.attrs" class="input mono" maxlength="128" autocomplete="off" />
          </FormField>
        </div>
      </section>
    </form>

    <!-- Étape 2 : fiche de sortie -->
    <form v-show="step === 'sheet'" class="stack-lg" novalidate @submit.prevent="goTo('contract')">
      <section class="panel form">
        <h2 class="title-section">Compteur et carburant</h2>
        <FormField
          label="Kilométrage au compteur"
          required
          :help="`Dernier relevé : ${lastOdometer.toLocaleString('fr-FR')} km`"
          :error="errors.odometer_km"
          v-slot="field"
        >
          <input v-model="form.odometer_km" v-bind="field.attrs" class="input input-large" type="number" inputmode="numeric" :min="lastOdometer" />
        </FormField>
        <FormField label="Niveau de carburant" required :error="errors.fuel_level_percent" v-slot="field">
          <div v-bind="field.attrs" class="segmented" role="group">
            <button
              v-for="level in fuelLevels"
              :key="level"
              type="button"
              :aria-pressed="form.fuel_level_percent === level"
              @click="form.fuel_level_percent = level"
            >
              {{ fuelLevelLabels[level] }}
            </button>
          </div>
        </FormField>
      </section>

      <section class="panel form">
        <h2 class="title-section">Accessoires remis</h2>
        <div class="accessories">
          <label v-for="key in accessoryKeys" :key="key" class="check">
            <input type="checkbox" :checked="form.accessories.includes(key)" @change="toggleAccessory(key)" />
            <span>{{ accessoryLabels[key] }}</span>
          </label>
        </div>
      </section>

      <section class="panel form">
        <h2 class="title-section">État du véhicule</h2>
        <DamageSketch v-model="damageMarks" />
        <FormField label="Dommages constatés" help="Facultatif. Laissez vide si aucun dommage n’est constaté." :error="errors.damage_notes" v-slot="field">
          <textarea v-model="form.damage_notes" v-bind="field.attrs" class="textarea" rows="3" maxlength="2000"></textarea>
        </FormField>
        <div v-if="photos.length" class="photos">
          <figure v-for="photo in photos" :key="photo.id">
            <PrivateImage :path="photo.url" alt="Photo de l’état du véhicule" />
            <button class="btn btn-ghost" type="button" @click="removePhoto(photo.id)">Retirer</button>
          </figure>
        </div>
        <FileCapture
          v-if="photos.length < 12"
          :key="photoKey"
          purpose="inspection_photo"
          :site-id="reservation.site_id"
          :label="photos.length ? 'Ajouter une autre photo' : 'Photos de l’état du véhicule'"
          help="Facultatif. 12 photos au plus : faces avant, arrière, côtés et dommages."
          @uploaded="addPhoto"
        />
      </section>
    </form>

    <!-- Étape 3 : contrat et signatures -->
    <form v-show="step === 'contract'" class="stack-lg" novalidate @submit.prevent="submit">
      <section class="panel form">
        <h2 class="title-section">Conditions du contrat</h2>
        <p v-if="!terms" class="alert alert-warning">
          Les conditions du contrat ne sont pas configurées. Le propriétaire doit les saisir dans Configuration, fiche de la société, avant toute remise.
        </p>
        <div v-else class="terms" tabindex="0" aria-label="Conditions générales du contrat">{{ terms }}</div>
        <label class="check">
          <input v-model="form.terms_accepted" type="checkbox" :disabled="!terms" />
          <span>Le client a lu les conditions et les accepte.<span class="required" aria-hidden="true">*</span></span>
        </label>
        <span v-if="errors.terms_accepted" class="field-error" role="alert">{{ errors.terms_accepted }}</span>
      </section>

      <section class="panel form">
        <h2 class="title-section">Signatures</h2>
        <SignaturePad ref="customerPad" :label="`Le locataire - ${form.driver_full_name || 'client'}`" :disabled="busy" @change="(value) => (customerSigned = value)" />
        <span v-if="errors.customer_signature_file_id" class="field-error" role="alert">{{ errors.customer_signature_file_id }}</span>
        <FormField label="Signataire pour le loueur" required :error="errors.company_signer_name" v-slot="field">
          <input v-model="form.company_signer_name" v-bind="field.attrs" class="input" maxlength="160" autocomplete="off" />
        </FormField>
        <SignaturePad ref="companyPad" label="Pour le loueur" :disabled="busy" @change="(value) => (companySigned = value)" />
        <span v-if="errors.company_signature_file_id" class="field-error" role="alert">{{ errors.company_signature_file_id }}</span>
      </section>
    </form>

    <div v-if="stepMissing.length" class="missing" role="status">
      <strong>À compléter</strong>
      <ul>
        <li v-for="item in stepMissing" :key="item">{{ item }}</li>
      </ul>
    </div>
    <InlineAlert :message="error" />

    <div class="action-bar">
      <span class="text-small text-secondary action-status">
        <template v-if="busy">{{ progress }}</template>
        <template v-else-if="allMissing.length">{{ allMissing.length > 1 ? `${allMissing.length} éléments manquants` : '1 élément manquant' }}</template>
        <template v-else>Prêt pour la remise</template>
      </span>
      <button v-if="step === 'driver'" class="btn btn-primary" type="button" @click="goTo('sheet')">Suivant</button>
      <button v-else-if="step === 'sheet'" class="btn btn-primary" type="button" @click="goTo('contract')">Suivant</button>
      <button
        v-else
        class="btn btn-primary"
        type="button"
        :disabled="busy || allMissing.length > 0 || !app.canReachServer"
        @click="submit"
      >
        {{ busy ? 'Enregistrement' : 'Mettre en circulation' }}
      </button>
    </div>
  </div>
</template>

<style scoped>
.checkout {
  display: grid;
  gap: 16px;
  padding-bottom: 96px;
}

.steps {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
}

.steps button.done:not([aria-pressed='true'])::after {
  content: '';
  display: inline-block;
  width: 6px;
  height: 10px;
  margin-left: 8px;
  border: solid var(--success);
  border-width: 0 2px 2px 0;
  transform: rotate(45deg) translateY(-2px);
}

.summary {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 4px 12px;
}

.input-large {
  font-size: var(--text-xl);
  font-weight: 600;
  font-variant-numeric: tabular-nums;
}

.accessories {
  display: grid;
  gap: 8px;
}

.photos {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
  gap: 8px;
}

.photos figure {
  display: grid;
  gap: 4px;
  margin: 0;
}

.photos :deep(img) {
  width: 100%;
  aspect-ratio: 4 / 3;
  object-fit: cover;
  border-radius: var(--radius-control);
  background: var(--surface-sunken);
}

.terms {
  max-height: 320px;
  overflow-y: auto;
  padding: 12px 14px;
  border: 1px solid var(--line-strong);
  border-radius: var(--radius-control);
  background: var(--surface-sunken);
  font-size: var(--text-sm);
  line-height: 1.5;
  white-space: pre-wrap;
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

.action-bar .btn {
  flex: none;
  min-width: 160px;
}

.action-status {
  min-width: 0;
}

@media (min-width: 900px) {
  .accessories {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

}

@media (min-width: 960px) {
  .action-bar {
    left: var(--rail-w);
    bottom: 0;
  }
}
</style>
