<script setup lang="ts">
import { computed, reactive, watch } from 'vue'
import type { CarRentalReservation } from '../../api/types'
import { checkOutReservation } from '../../api/carRental'
import { useAppStore } from '../../stores/app'
import { useRequest } from '../../composables/useRequest'
import { formatMoney, sumAmounts, toAmount } from '../../lib/money'
import { rentalDays, toDateInput } from '../../lib/time'
import FormField from '../ui/FormField.vue'
import InlineAlert from '../ui/InlineAlert.vue'

/*
 * Mise en circulation.
 *
 * Le montant de location et le dépôt requis viennent de la réservation et
 * de la fiche véhicule : le préposé ne les ressaisit pas. L'action finale
 * n'est disponible que lorsque chaque exigence est remplie, et chaque
 * exigence manquante est nommée.
 */
const props = defineProps<{
  reservation: CarRentalReservation
  canSubmitPayment: boolean
}>()

const emit = defineEmits<{
  done: [reservation: CarRentalReservation, notificationSent: boolean]
  'add-payment': [kind: 'rental' | 'security_deposit', amount: string]
}>()

const app = useAppStore()
const request = useRequest()

const license = reactive({
  driver_full_name: '',
  driver_license_number: '',
  driver_license_expires_at: '',
  driver_license_verified: false,
})

watch(
  () => props.reservation.id,
  () => {
    Object.assign(license, {
      driver_full_name: props.reservation.driver_full_name ?? props.reservation.customer?.display_name ?? '',
      driver_license_number: '',
      driver_license_expires_at: props.reservation.driver_license_expires_at ?? '',
      driver_license_verified: props.reservation.driver_license_verified,
    })
  },
  { immediate: true },
)

const requirements = computed(() => props.reservation.checkout_requirements)
const days = computed(() => rentalDays(props.reservation.pickup_at, props.reservation.due_at))

const rentalTotal = computed(() => {
  const rate = toAmount(props.reservation.daily_rate) ?? 0
  return Math.round(rate * days.value * 100) / 100
})

const airportFees = computed(() => toAmount(props.reservation.airport_fees_total_usd) ?? 0)

const approvedRental = computed(() =>
  sumAmounts((props.reservation.payments ?? [])
    .filter((payment) => payment.kind === 'rental' && payment.status === 'approved' && payment.currency === props.reservation.currency)
    .map((payment) => payment.amount)),
)

const pendingPayments = computed(() => (props.reservation.payments ?? []).filter((payment) => payment.status === 'submitted').length)

const rentalBalance = computed(() => Math.max(0, Math.round((rentalTotal.value - approvedRental.value) * 100) / 100))

const depositRequired = computed(() => toAmount(requirements.value.minimum_security_deposit_usd) ?? 0)
const depositHeld = computed(() => toAmount(requirements.value.held_security_deposit_usd) ?? 0)
const depositBalance = computed(() => Math.max(0, Math.round((depositRequired.value - depositHeld.value) * 100) / 100))

const licenseExpired = computed(() =>
  Boolean(license.driver_license_expires_at) && license.driver_license_expires_at < toDateInput(props.reservation.due_at),
)

const licenseComplete = computed(() =>
  Boolean(license.driver_full_name.trim() && license.driver_license_number.trim() && license.driver_license_expires_at && license.driver_license_verified),
)

const missing = computed(() => {
  const items: string[] = []
  if (!requirements.value.approved_rental_payment) items.push('Un paiement de location doit être enregistré et approuvé.')
  if (!requirements.value.minimum_security_deposit_configured) items.push('Le dépôt minimum du véhicule n’est pas configuré.')
  else if (!requirements.value.security_deposit_satisfied) items.push(`Il manque ${formatMoney(depositBalance.value, 'USD')} de dépôt de garantie retenu.`)
  if (!license.driver_full_name.trim()) items.push('Saisissez le nom complet du conducteur.')
  if (!license.driver_license_number.trim()) items.push('Saisissez le numéro du permis.')
  if (!license.driver_license_expires_at) items.push('Saisissez la date d’expiration du permis.')
  if (!license.driver_license_verified) items.push('Confirmez la vérification de l’original du permis.')
  return items
})

async function submit(): Promise<void> {
  if (missing.value.length) {
    request.fail('La mise en circulation n’est pas encore possible.')
    return
  }
  const result = await request.run(() => checkOutReservation(props.reservation, {
    driver_full_name: license.driver_full_name.trim(),
    driver_license_number: license.driver_license_number.trim(),
    driver_license_expires_at: license.driver_license_expires_at,
    driver_license_verified: license.driver_license_verified,
  }))
  if (result) emit('done', result.data, Boolean(result.customer_notification_sent))
}
</script>

<template>
  <section class="panel checkout" aria-labelledby="checkout-title">
    <div class="panel-header">
      <div>
        <h2 id="checkout-title" class="title-section">Mise en circulation</h2>
        <p class="text-secondary text-small">Montants calculés à partir de la réservation et de la fiche véhicule.</p>
      </div>
    </div>

    <ol class="requirements">
      <li class="requirement" :class="{ met: requirements.approved_rental_payment }">
        <span class="requirement-mark" aria-hidden="true"></span>
        <span>
          <strong>Paiement de location approuvé</strong>
          <span class="requirement-detail text-small text-secondary">
            {{ days }} × {{ formatMoney(reservation.daily_rate, reservation.currency) }} = {{ formatMoney(rentalTotal, reservation.currency) }}<template v-if="airportFees"> + frais aéroport {{ formatMoney(airportFees, 'USD') }}</template>
          </span>
        </span>
        <span class="requirement-value">{{ formatMoney(approvedRental, reservation.currency) }}</span>
      </li>
      <li class="requirement" :class="{ met: requirements.security_deposit_satisfied && requirements.minimum_security_deposit_configured }">
        <span class="requirement-mark" aria-hidden="true"></span>
        <span>
          <strong>Dépôt de garantie retenu</strong>
          <span class="requirement-detail text-small text-secondary">Minimum requis : {{ formatMoney(requirements.minimum_security_deposit_usd, 'USD') }}</span>
        </span>
        <span class="requirement-value">{{ formatMoney(requirements.held_security_deposit_usd, 'USD') }}</span>
      </li>
      <li class="requirement" :class="{ met: licenseComplete }">
        <span class="requirement-mark" aria-hidden="true"></span>
        <span>
          <strong>Permis du conducteur vérifié</strong>
          <span class="requirement-detail text-small text-secondary">Original contrôlé au comptoir</span>
        </span>
        <span class="requirement-value">{{ licenseComplete ? 'Oui' : 'Non' }}</span>
      </li>
    </ol>

    <p v-if="pendingPayments" class="alert alert-warning">
      {{ pendingPayments > 1 ? `${pendingPayments} paiements attendent` : 'Un paiement attend' }} une approbation dans la liste des paiements.
    </p>

    <div v-if="canSubmitPayment" class="btn-row">
      <button
        v-if="!requirements.approved_rental_payment"
        class="btn btn-secondary"
        type="button"
        @click="emit('add-payment', 'rental', rentalBalance ? rentalBalance.toFixed(2) : '')"
      >
        Encaisser la location
      </button>
      <button
        v-if="!requirements.security_deposit_satisfied"
        class="btn btn-secondary"
        type="button"
        @click="emit('add-payment', 'security_deposit', depositBalance ? depositBalance.toFixed(2) : '')"
      >
        Encaisser le dépôt
      </button>
    </div>

    <form class="form license" novalidate @submit.prevent="submit">
      <h3 class="title-section">Permis du conducteur</h3>
      <FormField label="Nom complet du conducteur" :error="request.fieldErrors.value.driver_full_name" v-slot="field">
        <input v-model.trim="license.driver_full_name" v-bind="field.attrs" class="input" maxlength="160" autocomplete="off" required />
      </FormField>
      <div class="grid-2">
        <FormField label="Numéro du permis" :error="request.fieldErrors.value.driver_license_number" v-slot="field">
          <input v-model.trim="license.driver_license_number" v-bind="field.attrs" class="input" maxlength="128" autocomplete="off" required />
        </FormField>
        <FormField
          label="Expiration du permis"
          :help="licenseExpired ? 'Le permis expire avant la date de retour prévue.' : undefined"
          :error="request.fieldErrors.value.driver_license_expires_at"
          v-slot="field"
        >
          <input v-model="license.driver_license_expires_at" v-bind="field.attrs" class="input" type="date" required />
        </FormField>
      </div>
      <label class="check">
        <input v-model="license.driver_license_verified" type="checkbox" />
        <span>J’ai vérifié l’original du permis de conduire.</span>
      </label>

      <div v-if="missing.length" class="missing" role="status">
        <p class="text-small"><strong>Avant la mise en circulation :</strong></p>
        <ul>
          <li v-for="item in missing" :key="item" class="text-small">{{ item }}</li>
        </ul>
      </div>
      <InlineAlert :message="request.error.value" />
      <button class="btn btn-primary btn-block" type="submit" :disabled="request.busy.value || missing.length > 0 || !app.canReachServer">
        {{ request.busy.value ? 'Enregistrement' : 'Mettre en circulation' }}
      </button>
    </form>
  </section>
</template>

<style scoped>
.requirement-detail {
  display: block;
  margin-top: 2px;
}

.license {
  padding-top: 18px;
  border-top: 1px solid var(--line);
}

.missing {
  display: grid;
  gap: 6px;
  padding: 12px 14px;
  border-radius: var(--radius-control);
  color: var(--warning);
  background: var(--warning-soft);
}

.missing ul {
  display: grid;
  gap: 4px;
  padding-left: 18px;
  list-style: disc;
}
</style>
