<script setup lang="ts">
import { computed } from 'vue'
import type { CarRentalReservation } from '../../api/types'
import { useAppStore } from '../../stores/app'
import { formatMoney, sumAmounts, toAmount } from '../../lib/money'
import { rentalDays } from '../../lib/time'

/*
 * Résumé de la mise en circulation sur le détail de la réservation.
 *
 * Le montant de location et le dépôt requis viennent de la réservation et
 * de la fiche véhicule : le préposé ne les ressaisit pas. Le permis, la
 * fiche de sortie et les signatures sont saisis sur l'écran dédié.
 */
const props = defineProps<{
  reservation: CarRentalReservation
  canSubmitPayment: boolean
}>()

const emit = defineEmits<{
  'add-payment': [kind: 'rental' | 'security_deposit', amount: string]
}>()

const app = useAppStore()

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

const missing = computed(() => {
  const items: string[] = []
  if (!requirements.value.approved_rental_payment) items.push('Un paiement de location doit être enregistré et approuvé.')
  if (!requirements.value.minimum_security_deposit_configured) items.push('Le dépôt minimum du véhicule n’est pas configuré.')
  else if (!requirements.value.security_deposit_satisfied) items.push(`Il manque ${formatMoney(depositBalance.value, 'USD')} de dépôt de garantie retenu.`)
  if (!requirements.value.contract_terms_configured) items.push('Les conditions du contrat doivent être saisies par le propriétaire dans la configuration de la société.')
  return items
})
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

    <div v-if="missing.length" class="missing" role="status">
      <strong>Avant la remise</strong>
      <ul>
        <li v-for="item in missing" :key="item">{{ item }}</li>
      </ul>
    </div>

    <RouterLink
      v-if="!missing.length && app.canReachServer"
      class="btn btn-primary btn-block"
      :to="{ name: 'rental.reservation.checkout', params: { reservationId: reservation.id } }"
    >
      Commencer la mise en circulation
    </RouterLink>
    <button v-else class="btn btn-primary btn-block" type="button" disabled>Commencer la mise en circulation</button>
    <p class="text-secondary text-small">Permis recto et verso, fiche de sortie, puis signatures du client et du loueur.</p>
  </section>
</template>

<style scoped>
.requirement-detail {
  display: block;
  margin-top: 2px;
}
</style>
