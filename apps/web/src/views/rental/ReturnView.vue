<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { fetchReservation, issueInvoice, returnReservation, settleDeposit, uploadFile, type UploadedFileRef } from '../../api/carRental'
import { errorMessage, fieldErrors } from '../../api/client'
import type { CarRentalReservation, FuelLevel, RentalAccessory } from '../../api/types'
import { useSessionStore } from '../../stores/session'
import { useAppStore } from '../../stores/app'
import { useUiStore } from '../../stores/ui'
import { useRequest } from '../../composables/useRequest'
import { accessoryLabels, fuelLevelLabel, fuelLevelLabels } from '../../lib/labels'
import { formatMoney } from '../../lib/money'
import { formatDateTime } from '../../lib/time'
import { vehicleName, vehiclePlate } from '../../lib/text'
import type { DamageMark } from '../../lib/damageSketch'
import PageHeader from '../../components/ui/PageHeader.vue'
import FormField from '../../components/ui/FormField.vue'
import FileCapture from '../../components/ui/FileCapture.vue'
import InlineAlert from '../../components/ui/InlineAlert.vue'
import PrivateImage from '../../components/ui/PrivateImage.vue'
import SignaturePad from '../../components/ui/SignaturePad.vue'
import DamageSketch from '../../components/rental/DamageSketch.vue'

/*
 * Retour du véhicule : relevé du compteur et du carburant, accessoires,
 * dommages comparés au départ, puis frais à appliquer. Aucun frais n'est
 * ajouté sans case cochée. Le tarif initial reste inchangé.
 */
const props = defineProps<{ reservationId: string }>()

const session = useSessionStore()
const app = useAppStore()
const ui = useUiStore()
const router = useRouter()
const loading = useRequest()

const reservation = ref<CarRentalReservation | null>(null)
const fuelLevels = Object.keys(fuelLevelLabels).map(Number) as FuelLevel[]
const accessoryKeys = Object.keys(accessoryLabels) as RentalAccessory[]
const canAddOtherCharges = computed(() => session.can('rental.deposits.settle'))
const canSettle = computed(() => session.can('rental.deposits.settle'))
const canInvoice = computed(() => session.can('rental.invoices.issue'))
const deposit = reactive({ retained: '0', reason: '', touched: false })

const form = reactive({
  odometer_km: '',
  fuel_level_percent: null as FuelLevel | null,
  accessories: [] as RentalAccessory[],
  damage_notes: '',
  apply_cleaning_fee: false,
  apply_extra_km: false,
  customer_present: true,
})
const damageMarks = ref<DamageMark[]>([])
const photos = ref<UploadedFileRef[]>([])
const photoKey = ref(0)
const otherCharges = ref<Array<{ label: string; amount: string }>>([])
const customerSigned = ref(false)
const customerPad = ref<InstanceType<typeof SignaturePad> | null>(null)

const busy = ref(false)
const error = ref('')
const errors = ref<Record<string, string>>({})

const checkout = computed(() => reservation.value?.checkout_inspection ?? null)
const departureKm = computed(() => checkout.value?.odometer_km ?? reservation.value?.vehicle?.latest_odometer_km ?? 0)
const drivenKm = computed(() => (form.odometer_km === '' ? 0 : Math.max(0, Number(form.odometer_km) - departureKm.value)))
const extraKm = computed(() => {
  const value = reservation.value
  if (!value || value.kilometer_plan !== 'limited' || value.included_km === null) return 0
  return Math.max(0, drivenKm.value - value.included_km)
})
const extraKmAmount = computed(() => Math.round(extraKm.value * Number(reservation.value?.additional_km_rate ?? 0) * 100) / 100)
const canBillExtraKm = computed(() => extraKm.value > 0 && Boolean(reservation.value?.additional_km_rate))
const missingAccessories = computed(() => (checkout.value?.accessories ?? []).filter((key) => !form.accessories.includes(key)))
const missingAccessoriesText = computed(() => missingAccessories.value.map((item) => accessoryLabels[item].toLowerCase()).join(', '))
const fuelLower = computed(() =>
  form.fuel_level_percent !== null && checkout.value?.fuel_level_percent != null && form.fuel_level_percent < checkout.value.fuel_level_percent,
)
const isEarly = computed(() => reservation.value !== null && new Date(reservation.value.due_at) > app.now)
const isLate = computed(() => reservation.value !== null && new Date(reservation.value.due_at) < app.now)

onMounted(async () => {
  const result = await loading.run(() => fetchReservation(props.reservationId))
  if (!result) return
  reservation.value = result.data
  if (result.data.state !== 'checked_out') {
    loading.fail('Seule une location en circulation peut être retournée.')
    return
  }
  form.odometer_km = ''
  form.accessories = [...(result.data.checkout_inspection?.accessories ?? [])]
})

function toggleAccessory(key: RentalAccessory): void {
  form.accessories = form.accessories.includes(key)
    ? form.accessories.filter((item) => item !== key)
    : [...form.accessories, key]
}

function addPhoto(file: UploadedFileRef): void {
  photos.value = [...photos.value, file]
  photoKey.value += 1
}

function addOtherCharge(): void {
  otherCharges.value = [...otherCharges.value, { label: '', amount: '' }]
}

function removeOtherCharge(index: number): void {
  otherCharges.value = otherCharges.value.filter((_, position) => position !== index)
}

const chargesTotal = computed(() => {
  let total = 0
  if (form.apply_cleaning_fee) total += 20
  if (form.apply_extra_km && canBillExtraKm.value) total += extraKmAmount.value
  for (const charge of otherCharges.value) total += Number(charge.amount) || 0
  return Math.round(total * 100) / 100
})

const heldDeposit = computed(() =>
  Math.round((reservation.value?.security_deposits ?? [])
    .filter((item) => item.status === 'held' && item.currency === 'USD')
    .reduce((sum, item) => sum + Number(item.amount ?? 0), 0) * 100) / 100,
)

/* Retenue proposée : frais du retour dans la limite du dépôt, modifiable. */
const proposedRetention = computed(() =>
  reservation.value?.currency === 'USD' ? Math.min(chargesTotal.value, heldDeposit.value) : 0,
)
const retained = computed(() => (deposit.touched ? Number(deposit.retained) || 0 : proposedRetention.value))
const settlesHere = computed(() => canSettle.value && heldDeposit.value > 0)

function onRetainedInput(event: Event): void {
  deposit.touched = true
  deposit.retained = (event.target as HTMLInputElement).value
}

const missing = computed(() => {
  const items: string[] = []
  if (form.odometer_km === '') items.push('Le kilométrage au retour')
  else if (Number(form.odometer_km) < departureKm.value) items.push(`Un kilométrage d’au moins ${departureKm.value.toLocaleString('fr-FR')} km`)
  if (form.fuel_level_percent === null) items.push('Le niveau de carburant')
  otherCharges.value.forEach((charge, index) => {
    if (!charge.label.trim() || !(Number(charge.amount) > 0)) items.push(`Le motif et le montant du frais ${index + 1}`)
  })
  if (form.customer_present && !customerSigned.value) items.push('La signature du client, ou décochez « Client présent »')
  if (settlesHere.value) {
    if (retained.value < 0 || retained.value > heldDeposit.value) items.push(`Une retenue sur dépôt entre 0 et ${formatMoney(heldDeposit.value, 'USD')}`)
    if (retained.value > 0 && !deposit.reason.trim() && !chargesTotal.value) items.push('Le motif de la retenue sur dépôt')
  }
  return items
})

/**
 * Après le retour : règlement du dépôt et facture finale envoyée au client
 * lorsque le rôle le permet. Une étape qui échoue reste disponible dans le
 * détail de la réservation ; le retour lui-même est déjà enregistré.
 */
async function settleAndInvoice(returned: CarRentalReservation): Promise<string> {
  let current = returned
  try {
    if (settlesHere.value) {
      const reason = deposit.reason.trim() || current.additional_charges?.map((charge) => charge.label).join(', ') || ''
      current = (await settleDeposit(current.id, retained.value.toFixed(2), retained.value > 0 ? reason : '')).data
    }
    const stillHeld = (current.security_deposits ?? []).some((item) => item.status === 'held')
    if (!canInvoice.value || stillHeld) {
      return 'Retour enregistré. La facture sera envoyée après le règlement du dépôt par un administrateur.'
    }
    current = (await issueInvoice(current.id)).data
    const { issueInvoicePdf } = await import('../../components/rental/issueInvoice')
    const sent = await issueInvoicePdf(current)
    return sent.customer_notification_sent
      ? 'Retour enregistré. La facture finale a été envoyée au client.'
      : 'Retour enregistré. La facture finale est disponible dans la réservation.'
  } catch {
    return 'Retour enregistré. Terminez le dépôt ou la facture depuis la réservation.'
  }
}

async function submit(): Promise<void> {
  const current = reservation.value
  if (!current || missing.value.length || busy.value) return
  busy.value = true
  error.value = ''
  errors.value = {}
  try {
    let signatureId: string | undefined
    if (form.customer_present) {
      const blob = await customerPad.value?.toBlob()
      if (!blob) throw new Error('La signature est vide. Signez de nouveau.')
      signatureId = (await uploadFile('signature', current.site_id, blob, 'signature-retour.png')).data.id
    }
    const result = await returnReservation(current, {
      odometer_km: Number(form.odometer_km),
      fuel_level_percent: form.fuel_level_percent as FuelLevel,
      accessories: form.accessories,
      damage_notes: form.damage_notes.trim() || undefined,
      damage_marks: damageMarks.value,
      inspection_photo_file_ids: photos.value.map((photo) => photo.id),
      apply_cleaning_fee: form.apply_cleaning_fee,
      apply_extra_km: form.apply_extra_km && canBillExtraKm.value,
      other_charges: otherCharges.value.length ? otherCharges.value.map((charge) => ({ label: charge.label.trim(), amount: charge.amount })) : undefined,
      customer_signature_file_id: signatureId,
    })
    ui.toast(await settleAndInvoice(result.data))
    // Le retour terminé, l'agent revient à l'accueil.
    await router.replace({ name: 'rental.today' })
  } catch (caught) {
    error.value = caught instanceof Error && !('status' in caught) ? caught.message : errorMessage(caught)
    errors.value = fieldErrors(caught)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <PageHeader
    :title="reservation ? `Retour ${reservation.number}` : 'Retour du véhicule'"
    description="Les champs marqués * sont obligatoires. Le tarif de la réservation n’est pas modifié."
    :back="{ name: 'rental.reservation', params: { reservationId } }"
    back-label="Réservation"
  />

  <InlineAlert :message="loading.error.value" />
  <div v-if="loading.busy.value" class="skeleton" style="height: 420px"></div>

  <form v-else-if="reservation && reservation.state === 'checked_out'" class="return" novalidate @submit.prevent="submit">
    <section class="panel summary" aria-label="Résumé">
      <strong>{{ vehicleName(reservation.vehicle) }}</strong>
      <span v-if="reservation.vehicle" class="plate">{{ vehiclePlate(reservation.vehicle) }}</span>
      <span class="text-secondary text-small">Retour prévu le {{ formatDateTime(reservation.due_at) }}</span>
      <span v-if="isEarly" class="text-small">Retour anticipé : le montant de la réservation est conservé.</span>
      <span v-else-if="isLate" class="text-small field-error">Retour après la date prévue. Aucun frais de retard n’est appliqué automatiquement.</span>
    </section>

    <section class="panel form">
      <h2 class="title-section">Compteur et carburant</h2>
      <FormField
        label="Kilométrage au retour"
        required
        :help="`Départ : ${departureKm.toLocaleString('fr-FR')} km${form.odometer_km !== '' ? ` - parcourus : ${drivenKm.toLocaleString('fr-FR')} km` : ''}`"
        :error="errors.odometer_km"
        v-slot="field"
      >
        <input v-model="form.odometer_km" v-bind="field.attrs" class="input input-large" type="number" inputmode="numeric" :min="departureKm" />
      </FormField>
      <FormField label="Niveau de carburant" required :help="`Départ : ${fuelLevelLabel(checkout?.fuel_level_percent)}`" :error="errors.fuel_level_percent" v-slot="field">
        <div v-bind="field.attrs" class="segmented" role="group">
          <button v-for="level in fuelLevels" :key="level" type="button" :aria-pressed="form.fuel_level_percent === level" @click="form.fuel_level_percent = level">
            {{ fuelLevelLabels[level] }}
          </button>
        </div>
      </FormField>
      <p v-if="fuelLower" class="alert alert-warning">Le niveau est inférieur à celui du départ. Aucun frais de carburant n’est appliqué automatiquement.</p>
    </section>

    <section class="panel form">
      <h2 class="title-section">Accessoires rendus</h2>
      <div class="accessories">
        <label v-for="key in accessoryKeys" :key="key" class="check">
          <input type="checkbox" :checked="form.accessories.includes(key)" @change="toggleAccessory(key)" />
          <span>{{ accessoryLabels[key] }}</span>
        </label>
      </div>
      <p v-if="missingAccessories.length" class="alert alert-warning">
        Remis au départ et non rendus : {{ missingAccessoriesText }}.
      </p>
    </section>

    <section class="panel form">
      <h2 class="title-section">État du véhicule</h2>
      <DamageSketch v-model="damageMarks" :reference="checkout?.damage_marks ?? []" />
      <FormField label="Dommages constatés au retour" help="Facultatif." :error="errors.damage_notes" v-slot="field">
        <textarea v-model="form.damage_notes" v-bind="field.attrs" class="textarea" rows="3" maxlength="2000"></textarea>
      </FormField>
      <div v-if="photos.length" class="photos">
        <figure v-for="photo in photos" :key="photo.id">
          <PrivateImage :path="photo.url" alt="Photo de l’état du véhicule au retour" />
          <button class="btn btn-ghost" type="button" @click="photos = photos.filter((item) => item.id !== photo.id)">Retirer</button>
        </figure>
      </div>
      <FileCapture
        v-if="photos.length < 12"
        :key="photoKey"
        purpose="inspection_photo"
        :site-id="reservation.site_id"
        :label="photos.length ? 'Ajouter une autre photo' : 'Photos de l’état au retour'"
        help="Facultatif. 12 photos au plus."
        @uploaded="addPhoto"
      />
    </section>

    <section class="panel form">
      <h2 class="title-section">Frais supplémentaires</h2>
      <p class="text-secondary text-small">Aucun frais n’est appliqué sans case cochée.</p>
      <label v-if="reservation.currency === 'USD'" class="check">
        <input v-model="form.apply_cleaning_fee" type="checkbox" />
        <span>Nettoyage : le véhicule n’est pas rendu dans le même état de propreté (20,00 USD)</span>
      </label>
      <label v-if="canBillExtraKm" class="check">
        <input v-model="form.apply_extra_km" type="checkbox" />
        <span>Kilométrage supplémentaire : {{ extraKm }} km × {{ formatMoney(reservation.additional_km_rate, reservation.currency) }} = {{ formatMoney(extraKmAmount, reservation.currency) }}</span>
      </label>
      <p v-else-if="extraKm > 0" class="text-secondary text-small">
        {{ extraKm }} km au-delà du forfait. Aucun prix au kilomètre n’est prévu au contrat.
      </p>

      <template v-if="canAddOtherCharges">
        <div v-for="(charge, index) in otherCharges" :key="index" class="charge">
          <FormField :label="`Motif ${index + 1}`" required v-slot="field">
            <input v-model="charge.label" v-bind="field.attrs" class="input" maxlength="80" />
          </FormField>
          <FormField :label="`Montant (${reservation.currency})`" required v-slot="field">
            <input v-model="charge.amount" v-bind="field.attrs" class="input" type="number" inputmode="decimal" min="0.01" step="0.01" />
          </FormField>
          <button class="btn btn-ghost" type="button" @click="removeOtherCharge(index)">Retirer</button>
        </div>
        <button v-if="otherCharges.length < 5" class="btn btn-secondary" type="button" @click="addOtherCharge">Ajouter un autre frais</button>
      </template>
      <p v-if="chargesTotal > 0" class="charges-total">Total des frais : <strong>{{ formatMoney(chargesTotal, reservation.currency) }}</strong></p>
    </section>

    <section v-if="settlesHere" class="panel form">
      <h2 class="title-section">Dépôt de garantie</h2>
      <p class="text-secondary text-small">Dépôt retenu : {{ formatMoney(heldDeposit, 'USD') }}. La retenue proposée reprend les frais du retour.</p>
      <div class="grid-2">
        <FormField label="Montant retenu (USD)" required v-slot="field">
          <input v-bind="field.attrs" :value="deposit.touched ? deposit.retained : proposedRetention.toFixed(2)" class="input" type="number" inputmode="decimal" min="0" :max="heldDeposit" step="0.01" @input="onRetainedInput" />
        </FormField>
        <FormField label="Motif de la retenue" :required="retained > 0 && !chargesTotal" help="Par défaut : les frais du retour." v-slot="field">
          <input v-model="deposit.reason" v-bind="field.attrs" class="input" maxlength="500" />
        </FormField>
      </div>
      <p class="text-small">Libéré au client : <strong>{{ formatMoney(Math.max(0, heldDeposit - retained), 'USD') }}</strong></p>
    </section>

    <section class="panel form">
      <h2 class="title-section">Signature du client</h2>
      <label class="check">
        <input v-model="form.customer_present" type="checkbox" />
        <span>Client présent au retour</span>
      </label>
      <SignaturePad
        v-if="form.customer_present"
        ref="customerPad"
        :label="`Le locataire - ${reservation.driver_full_name ?? 'client'}`"
        :disabled="busy"
        @change="(value) => (customerSigned = value)"
      />
    </section>

    <div v-if="missing.length" class="missing" role="status">
      <strong>À compléter</strong>
      <ul>
        <li v-for="item in missing" :key="item">{{ item }}</li>
      </ul>
    </div>
    <InlineAlert :message="error" />

    <div class="action-bar">
      <span class="text-small text-secondary">
        {{ missing.length ? (missing.length > 1 ? `${missing.length} éléments manquants` : '1 élément manquant') : 'Prêt pour le retour' }}
      </span>
      <button class="btn btn-primary" type="submit" :disabled="busy || missing.length > 0 || !app.canReachServer">
        {{ busy ? 'Enregistrement' : settlesHere ? 'Enregistrer le retour et facturer' : 'Enregistrer le retour' }}
      </button>
    </div>
  </form>
</template>

<style scoped>
.return {
  display: grid;
  gap: 16px;
  padding-bottom: 96px;
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

.charge {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 140px auto;
  align-items: end;
  gap: 8px;
}

.charges-total {
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

.action-bar .btn {
  flex: none;
  min-width: 160px;
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

@media (max-width: 520px) {
  .charge {
    grid-template-columns: minmax(0, 1fr) 110px;
  }

  .charge .btn {
    grid-column: 1 / -1;
    justify-self: start;
  }
}
</style>
