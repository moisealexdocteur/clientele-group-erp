<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import {
  approvePayment,
  cancelReservation,
  extendReservation,
  fetchReservation,
  notifyReservation,
  returnReservation,
  submitPayment,
} from '../../api/carRental'
import { privateFileUrl } from '../../api/client'
import type { CarRentalPayment, CarRentalReservation, Currency, PaymentMethod, ReservationCancellationReason } from '../../api/types'
import { useSessionStore } from '../../stores/session'
import { useAppStore } from '../../stores/app'
import { useUiStore } from '../../stores/ui'
import { useRequest } from '../../composables/useRequest'
import {
  cancellationReasonLabels,
  reservationStateLabels,
  reservationStateTones,
} from '../../lib/labels'
import { formatMoney } from '../../lib/money'
import { formatDate, formatDateTime, toDateTimeInput } from '../../lib/time'
import FileCapture from '../../components/ui/FileCapture.vue'
import { vehicleName, vehiclePlate } from '../../lib/text'
import FormField from '../../components/ui/FormField.vue'
import InlineAlert from '../../components/ui/InlineAlert.vue'
import SheetDialog from '../../components/ui/SheetDialog.vue'
import StatusPill from '../../components/ui/StatusPill.vue'
import VehicleThumb from '../../components/rental/VehicleThumb.vue'
import CheckoutPanel from '../../components/rental/CheckoutPanel.vue'

/*
 * Détail d'une réservation. Chaque action est une tâche distincte, ouverte
 * dans son propre panneau, et n'apparaît que si l'état et les droits du
 * contexte la permettent.
 */
const props = defineProps<{ reservationId: string }>()

const session = useSessionStore()
const app = useAppStore()
const ui = useUiStore()
const loading = useRequest()
const action = useRequest()

const reservation = ref<CarRentalReservation | null>(null)
type Task = 'extend' | 'cancel' | 'payment' | null
const task = ref<Task>(null)

const canManage = computed(() => session.can('rental.reservations.manage'))
const canSubmitPayment = computed(() => session.can('rental.payments.submit'))
const canApprovePayment = computed(() => session.can('rental.payments.approve'))
const canReadVehicles = computed(() => session.can('rental.vehicles.read'))

const isReserved = computed(() => reservation.value?.state === 'reserved')
const isOut = computed(() => reservation.value?.state === 'checked_out')
const isOverdue = computed(() => isOut.value && reservation.value !== null && new Date(reservation.value.due_at) < app.now)

const cashRegisters = computed(() =>
  session.sites.find((site) => site.id === reservation.value?.site_id)?.cash_registers.filter((register) => register.is_active) ?? [],
)

const extendForm = reactive({ due_at: '' })
const cancelForm = reactive({ reason: 'customer_request' as ReservationCancellationReason })
const paymentForm = reactive({
  payment_kind: 'rental' as 'rental' | 'security_deposit',
  method: 'cash' as PaymentMethod,
  currency: 'USD' as Currency,
  amount: '',
  cash_register_id: '',
  bank_reference: '',
  proof_file_id: '',
})
const canGrantCredit = computed(() => session.can('rental.payments.credit'))
const notifying = ref(false)

async function load(): Promise<void> {
  const result = await loading.run(() => fetchReservation(props.reservationId))
  if (result) reservation.value = result.data
}

onMounted(load)
watch(() => props.reservationId, load)

function replace(next: CarRentalReservation): void {
  reservation.value = next
}

/* ---------- Tâches ---------- */

function openExtend(): void {
  if (!reservation.value) return
  extendForm.due_at = toDateTimeInput(reservation.value.due_at)
  action.reset()
  task.value = 'extend'
}

async function saveExtend(): Promise<void> {
  if (!reservation.value) return
  const result = await action.run(() => extendReservation(reservation.value as CarRentalReservation, extendForm.due_at))
  if (!result) return
  replace(result.data)
  task.value = null
  ui.toast(result.customer_notification_sent
    ? 'Date de retour mise à jour. Le courriel client a été envoyé.'
    : 'Date de retour mise à jour. Toute facturation complémentaire est enregistrée séparément.')
}

function openCancel(): void {
  cancelForm.reason = 'customer_request'
  action.reset()
  task.value = 'cancel'
}

async function saveCancel(): Promise<void> {
  if (!reservation.value) return
  const result = await action.run(() => cancelReservation(reservation.value as CarRentalReservation, cancelForm.reason))
  if (!result) return
  replace(result.data)
  task.value = null
  ui.toast('Réservation annulée. Aucun remboursement n’a été créé automatiquement.')
}

async function recordReturn(): Promise<void> {
  if (!reservation.value) return
  const confirmed = await ui.confirm({
    title: 'Enregistrer le retour',
    message: 'Le véhicule passera en préparation. Le tarif et les paiements existants ne seront pas modifiés. Un retour anticipé conserve le montant prévu.',
    confirmLabel: 'Enregistrer le retour',
  })
  if (!confirmed) return
  const result = await action.run(() => returnReservation(reservation.value as CarRentalReservation))
  if (!result) {
    ui.toast(action.error.value, 'danger')
    return
  }
  replace(result.data)
  ui.toast(result.customer_notification_sent ? 'Retour enregistré. Le courriel client a été envoyé.' : 'Retour enregistré.')
}

function openPayment(kind: 'rental' | 'security_deposit' = 'rental', amount = ''): void {
  Object.assign(paymentForm, {
    payment_kind: kind,
    method: 'cash',
    currency: kind === 'security_deposit' ? 'USD' : (reservation.value?.currency ?? 'USD'),
    amount,
    cash_register_id: cashRegisters.value[0]?.id ?? '',
    bank_reference: '',
    proof_file_id: '',
  })
  action.reset()
  task.value = 'payment'
}

function setPaymentKind(kind: 'rental' | 'security_deposit'): void {
  paymentForm.payment_kind = kind
  if (kind === 'security_deposit') {
    paymentForm.currency = 'USD'
    if (paymentForm.method === 'credit') paymentForm.method = 'cash'
  }
}

function setPaymentMethod(method: PaymentMethod): void {
  paymentForm.method = method
  action.reset()
}

/* Ce qu'il manque pour enregistrer le paiement, affiché avant l'envoi. */
const paymentMissing = computed(() => {
  const items: string[] = []
  if (!paymentForm.amount || Number(paymentForm.amount) <= 0) items.push('Le montant')
  if (paymentForm.method === 'cash' && !paymentForm.cash_register_id) items.push('La caisse')
  if (paymentForm.method === 'bank_transfer' && !paymentForm.proof_file_id) items.push('La photo ou le fichier du reçu Sogebank')
  return items
})

async function savePayment(): Promise<void> {
  if (!reservation.value || paymentMissing.value.length) return
  const reservationId = reservation.value.id
  const result = await action.run(async () => {
    await submitPayment(reservationId, {
      payment_kind: paymentForm.payment_kind,
      method: paymentForm.method,
      currency: paymentForm.currency,
      amount: paymentForm.amount,
      cash_register_id: paymentForm.method === 'cash' ? paymentForm.cash_register_id : undefined,
      bank_reference: paymentForm.method === 'bank_transfer' ? paymentForm.bank_reference.trim() || undefined : undefined,
      proof_file_id: paymentForm.method === 'bank_transfer' ? paymentForm.proof_file_id : undefined,
    })
    return fetchReservation(reservationId)
  })
  if (!result) return
  replace(result.data)
  task.value = null
  if (paymentForm.method === 'credit') {
    ui.toast('Crédit accordé et enregistré.')
  } else {
    ui.toast(canApprovePayment.value
      ? 'Paiement enregistré. Approuvez-le dans la liste des paiements.'
      : 'Paiement enregistré. Une approbation est requise avant la mise en circulation.')
  }
}

async function openProof(payment: CarRentalPayment): Promise<void> {
  if (!payment.proof_file_url) return
  try {
    window.open(await privateFileUrl(payment.proof_file_url), '_blank', 'noopener')
  } catch {
    ui.toast('Le reçu ne peut pas être affiché avec vos droits.', 'danger')
  }
}

async function resendConfirmation(): Promise<void> {
  if (!reservation.value) return
  notifying.value = true
  try {
    const result = await notifyReservation(reservation.value.id)
    ui.toast(result.message, result.customer_notification_sent ? 'success' : 'danger')
  } catch (error) {
    ui.toast(error instanceof Error ? error.message : 'La confirmation n’a pas pu être envoyée.', 'danger')
  } finally {
    notifying.value = false
  }
}

const paymentMethodLabels: Record<PaymentMethod, string> = {
  cash: 'Espèces',
  bank_transfer: 'Virement Sogebank',
  credit: 'Crédit accordé',
}

async function approve(payment: CarRentalPayment): Promise<void> {
  if (!reservation.value) return
  const confirmed = await ui.confirm({
    title: 'Approuver le paiement',
    message: `${payment.kind === 'rental' ? 'Location' : 'Dépôt de garantie'} : ${formatMoney(payment.amount, payment.currency)} (${paymentMethodLabels[payment.method].toLowerCase()}). Confirmez que le montant a été reçu.`,
    confirmLabel: 'Approuver',
  })
  if (!confirmed) return
  const reservationId = reservation.value.id
  const result = await action.run(async () => {
    await approvePayment(reservationId, payment.id)
    return fetchReservation(reservationId)
  })
  if (!result) {
    ui.toast(action.error.value, 'danger')
    return
  }
  replace(result.data)
  ui.toast('Paiement approuvé.')
}

function onCheckedOut(next: CarRentalReservation, notified: boolean): void {
  replace(next)
  window.scrollTo({ top: 0, behavior: 'smooth' })
  ui.toast(notified ? 'Location mise en circulation. Le courriel client a été envoyé.' : 'Location mise en circulation.')
}

const kilometerText = computed(() => {
  const value = reservation.value
  if (!value) return ''
  if (value.kilometer_plan === 'unlimited') return 'Illimité'
  const base = `${value.included_km ?? 0} km inclus`
  return value.additional_km_rate ? `${base}, puis ${formatMoney(value.additional_km_rate, value.currency)} par km` : base
})

const paymentStatusLabels: Record<CarRentalPayment['status'], string> = {
  submitted: 'À approuver',
  approved: 'Approuvé',
  rejected: 'Refusé',
  reversed: 'Annulé',
}
</script>

<template>
  <RouterLink class="back-link" :to="{ name: 'rental.reservations' }">
    <span aria-hidden="true" class="back-arrow"></span>Réservations
  </RouterLink>

  <InlineAlert :message="loading.error.value" />
  <div v-if="loading.busy.value && !reservation" class="skeleton" style="height: 420px"></div>

  <div v-else-if="reservation" class="detail">
    <header class="detail-header">
      <div class="detail-pills">
        <StatusPill v-if="isOverdue" tone="danger" label="Retour en retard" />
        <StatusPill v-else :tone="reservationStateTones[reservation.state]" :label="reservationStateLabels[reservation.state]" />
      </div>
      <h1 class="display display-xxl detail-number">{{ reservation.number }}</h1>
      <p class="detail-customer">{{ reservation.customer?.display_name ?? 'Client non disponible' }}</p>
      <p v-if="reservation.customer?.email || reservation.customer?.phone" class="text-secondary text-small detail-contact">
        <span v-if="reservation.customer?.email">{{ reservation.customer.email }}</span>
        <span v-if="reservation.customer?.phone">{{ reservation.customer.phone }}</span>
      </p>
    </header>

    <div class="detail-grid">
      <div class="stack-lg">
        <!-- Actions principales, au pouce. -->
        <div v-if="canManage && (isReserved || isOut)" class="btn-row">
          <template v-if="isOut">
            <button class="btn btn-primary" type="button" :disabled="action.busy.value || !app.canReachServer" @click="recordReturn">Enregistrer le retour</button>
            <button class="btn btn-secondary" type="button" :disabled="!app.canReachServer" @click="openExtend">Prolonger</button>
          </template>
          <template v-if="isReserved">
            <RouterLink class="btn btn-secondary" :to="{ name: 'rental.reservation.edit', params: { reservationId } }">Modifier</RouterLink>
            <button class="btn btn-secondary" type="button" :disabled="notifying || !app.canReachServer || !reservation.customer?.email" @click="resendConfirmation">
              {{ notifying ? 'Envoi en cours' : 'Renvoyer la confirmation' }}
            </button>
            <button class="btn btn-danger" type="button" :disabled="!app.canReachServer" @click="openCancel">Annuler la réservation</button>
          </template>
        </div>
        <p v-if="isOut" class="text-muted text-small">Un retour anticipé conserve le tarif et les paiements de la réservation. Aucun remboursement n’est créé automatiquement.</p>

        <CheckoutPanel
          v-if="isReserved && canManage"
          :reservation="reservation"
          :can-submit-payment="canSubmitPayment"
          @done="onCheckedOut"
          @add-payment="openPayment"
        />

        <section class="panel" aria-labelledby="payments-title">
          <div class="panel-header">
            <h2 id="payments-title" class="title-section">Paiements</h2>
            <button
              v-if="canSubmitPayment && (isReserved || isOut)"
              class="btn btn-ghost"
              type="button"
              :disabled="!app.canReachServer"
              @click="openPayment()"
            >
              Enregistrer un paiement
            </button>
          </div>
          <ul v-if="(reservation.payments ?? []).length" class="payments">
            <li v-for="payment in reservation.payments ?? []" :key="payment.id">
              <span class="stack" style="gap: 2px">
                <strong>{{ payment.kind === 'rental' ? 'Location' : 'Dépôt de garantie' }}</strong>
                <span class="text-muted text-small">
                  {{ paymentMethodLabels[payment.method] }}<template v-if="payment.submitted_at"> - {{ formatDateTime(payment.submitted_at) }}</template>
                </span>
              </span>
              <span class="payment-end">
                <strong class="display display-sm">{{ formatMoney(payment.amount, payment.currency) }}</strong>
                <StatusPill :tone="payment.status === 'approved' ? 'success' : payment.status === 'submitted' ? 'warning' : 'neutral'" :label="paymentStatusLabels[payment.status]" />
                <button v-if="payment.proof_file_url" class="btn btn-ghost" type="button" @click="openProof(payment)">Voir le reçu</button>
                <button
                  v-if="payment.status === 'submitted' && canApprovePayment"
                  class="btn btn-secondary"
                  type="button"
                  :disabled="action.busy.value || !app.canReachServer"
                  @click="approve(payment)"
                >
                  Approuver
                </button>
              </span>
            </li>
          </ul>
          <p v-else class="text-muted">Aucun paiement enregistré.</p>
        </section>
      </div>

      <aside class="stack-lg">
        <section class="panel" aria-label="Véhicule">
          <VehicleThumb v-if="reservation.vehicle" size="lg" :vehicle="reservation.vehicle" :alt="vehicleName(reservation.vehicle)" />
          <div class="vehicle-line">
            <strong class="title-section">{{ vehicleName(reservation.vehicle) }}</strong>
            <span v-if="reservation.vehicle" class="plate">{{ vehiclePlate(reservation.vehicle) }}</span>
          </div>
          <RouterLink
            v-if="reservation.vehicle && canReadVehicles"
            class="btn btn-secondary"
            :to="{ name: 'rental.vehicle', params: { vehicleId: reservation.vehicle.id } }"
          >
            Ouvrir la fiche véhicule
          </RouterLink>
        </section>

        <dl class="panel facts">
          <div>
            <dt>Prise en charge</dt>
            <dd>{{ formatDateTime(reservation.pickup_at) }}</dd>
          </div>
          <div>
            <dt>Retour prévu</dt>
            <dd>{{ formatDateTime(reservation.due_at) }}</dd>
          </div>
          <div v-if="reservation.checked_out_at">
            <dt>Remis le</dt>
            <dd>{{ formatDateTime(reservation.checked_out_at) }}</dd>
          </div>
          <div v-if="reservation.returned_at">
            <dt>Retourné le</dt>
            <dd>{{ formatDateTime(reservation.returned_at) }}</dd>
          </div>
          <div>
            <dt>Bureau</dt>
            <dd>{{ reservation.site?.name ?? 'Non disponible' }}</dd>
          </div>
          <div>
            <dt>Tarif journalier</dt>
            <dd>
              {{ formatMoney(reservation.daily_rate, reservation.currency) }}
              <StatusPill v-if="reservation.rate_overridden" tone="warning" label="Tarif modifié" />
            </dd>
          </div>
          <div>
            <dt>Kilométrage</dt>
            <dd>{{ kilometerText }}</dd>
          </div>
          <div v-if="Number(reservation.airport_fees_total_usd) > 0">
            <dt>Frais aéroport</dt>
            <dd>{{ formatMoney(reservation.airport_fees_total_usd, 'USD') }}</dd>
          </div>
          <div>
            <dt>Dépôt minimum</dt>
            <dd>{{ formatMoney(reservation.minimum_security_deposit_usd, 'USD') }}</dd>
          </div>
          <div v-if="reservation.driver_full_name">
            <dt>Conducteur</dt>
            <dd>{{ reservation.driver_full_name }}</dd>
          </div>
          <div v-if="reservation.driver_license_expires_at">
            <dt>Permis valable jusqu’au</dt>
            <dd>{{ formatDate(reservation.driver_license_expires_at) }}</dd>
          </div>
        </dl>
      </aside>
    </div>
  </div>

  <!-- Prolonger -->
  <SheetDialog :open="task === 'extend'" title="Prolonger la location" description="En cas de conflit, la réservation suivante reste inchangée et aucune information client n’est affichée." :locked="action.busy.value" @close="task = null">
    <form id="extend-form" class="form" novalidate @submit.prevent="saveExtend">
      <FormField label="Nouvelle date de retour" :error="action.fieldErrors.value.due_at" v-slot="field">
        <input v-model="extendForm.due_at" v-bind="field.attrs" class="input" type="datetime-local" required />
      </FormField>
      <InlineAlert :message="action.error.value" />
    </form>
    <template #footer>
      <button class="btn btn-secondary" type="button" :disabled="action.busy.value" @click="task = null">Fermer</button>
      <button class="btn btn-primary" type="submit" form="extend-form" :disabled="action.busy.value">Prolonger la location</button>
    </template>
  </SheetDialog>

  <!-- Annuler -->
  <SheetDialog :open="task === 'cancel'" title="Annuler la réservation" description="Cette action n’effectue aucun remboursement automatique." :locked="action.busy.value" @close="task = null">
    <form id="cancel-form" class="form" novalidate @submit.prevent="saveCancel">
      <FormField label="Motif d’annulation" :error="action.fieldErrors.value.reason_code" v-slot="field">
        <select v-model="cancelForm.reason" v-bind="field.attrs" class="select">
          <option v-for="(label, reason) in cancellationReasonLabels" :key="reason" :value="reason">{{ label }}</option>
        </select>
      </FormField>
      <InlineAlert :message="action.error.value" />
    </form>
    <template #footer>
      <button class="btn btn-secondary" type="button" :disabled="action.busy.value" @click="task = null">Garder la réservation</button>
      <button class="btn btn-danger-solid" type="submit" form="cancel-form" :disabled="action.busy.value">Confirmer l’annulation</button>
    </template>
  </SheetDialog>

  <!-- Paiement -->
  <SheetDialog :open="task === 'payment'" title="Enregistrer un paiement" description="L’approbation reste une étape distincte, sauf pour un crédit accordé." :locked="action.busy.value" @close="task = null">
    <form v-if="reservation" id="payment-form" class="form" novalidate @submit.prevent="savePayment">
      <FormField label="Nature" required v-slot="field">
        <div v-bind="field.attrs" class="segmented" role="group">
          <button type="button" :aria-pressed="paymentForm.payment_kind === 'rental'" @click="setPaymentKind('rental')">Location</button>
          <button type="button" :aria-pressed="paymentForm.payment_kind === 'security_deposit'" @click="setPaymentKind('security_deposit')">Dépôt de garantie</button>
        </div>
      </FormField>
      <FormField label="Mode de paiement" required :error="action.fieldErrors.value.method" v-slot="field">
        <div v-bind="field.attrs" class="segmented" role="group">
          <button type="button" :aria-pressed="paymentForm.method === 'cash'" @click="setPaymentMethod('cash')">Espèces</button>
          <button type="button" :aria-pressed="paymentForm.method === 'bank_transfer'" @click="setPaymentMethod('bank_transfer')">Virement Sogebank</button>
          <button
            v-if="canGrantCredit && paymentForm.payment_kind === 'rental'"
            type="button"
            :aria-pressed="paymentForm.method === 'credit'"
            @click="setPaymentMethod('credit')"
          >
            Crédit
          </button>
        </div>
      </FormField>
      <p v-if="paymentForm.method === 'credit'" class="alert alert-warning">Le crédit est approuvé immédiatement et journalisé à votre nom. Le client reste redevable du montant.</p>

      <div class="grid-2">
        <FormField label="Devise" required :help="paymentForm.payment_kind === 'security_deposit' ? 'Le dépôt est contrôlé en USD.' : undefined" v-slot="field">
          <select v-model="paymentForm.currency" v-bind="field.attrs" class="select" :disabled="paymentForm.payment_kind === 'security_deposit'">
            <option value="USD">USD</option>
            <option value="HTG">HTG</option>
          </select>
        </FormField>
        <FormField :label="paymentForm.method === 'credit' ? 'Montant accordé' : 'Montant reçu'" required :error="action.fieldErrors.value.amount" v-slot="field">
          <input v-model="paymentForm.amount" v-bind="field.attrs" class="input input-amount" type="number" inputmode="decimal" min="0.01" step="0.01" required />
        </FormField>
      </div>

      <template v-if="paymentForm.method === 'cash'">
        <FormField label="Caisse" required :error="action.fieldErrors.value.cash_register_id" v-slot="field">
          <select v-model="paymentForm.cash_register_id" v-bind="field.attrs" class="select" required>
            <option value="" disabled>Sélectionnez une caisse active</option>
            <option v-for="register in cashRegisters" :key="register.id" :value="register.id">{{ register.name }}</option>
          </select>
        </FormField>
        <p v-if="!cashRegisters.length" class="alert alert-warning">Aucune caisse active pour ce bureau. Demandez au propriétaire d’en créer une.</p>
      </template>

      <template v-if="paymentForm.method === 'bank_transfer'">
        <FileCapture
          purpose="payment_proof"
          :site-id="reservation.site_id"
          label="Reçu de virement Sogebank *"
          accept="image/jpeg,image/png,image/webp,application/pdf"
          help="Photo nette du reçu ou fichier PDF, 10 Mo au plus."
          :error="action.fieldErrors.value.proof_file_id"
          @uploaded="(file) => (paymentForm.proof_file_id = file.id)"
          @cleared="paymentForm.proof_file_id = ''"
        />
        <FormField label="Référence du virement" help="Facultatif. Numéro indiqué sur le reçu." :error="action.fieldErrors.value.bank_reference" v-slot="field">
          <input v-model="paymentForm.bank_reference" v-bind="field.attrs" class="input" type="text" maxlength="80" autocomplete="off" />
        </FormField>
      </template>

      <div v-if="paymentMissing.length" class="missing" role="status">
        <strong>À compléter</strong>
        <ul>
          <li v-for="item in paymentMissing" :key="item">{{ item }}</li>
        </ul>
      </div>
      <InlineAlert :message="action.error.value" />
    </form>
    <template #footer>
      <button class="btn btn-secondary" type="button" :disabled="action.busy.value" @click="task = null">Fermer</button>
      <button class="btn btn-primary" type="submit" form="payment-form" :disabled="action.busy.value || paymentMissing.length > 0">
        {{ paymentForm.method === 'credit' ? 'Accorder le crédit' : 'Enregistrer le paiement' }}
      </button>
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

.detail {
  display: grid;
  gap: 24px;
}

.detail-header {
  display: grid;
  gap: 10px;
}

.detail-pills {
  display: flex;
  gap: 8px;
}

.detail-number {
  overflow-wrap: anywhere;
}

.detail-customer {
  font-size: var(--text-xl);
  font-weight: 600;
}

.detail-grid {
  display: grid;
  gap: 28px;
  align-items: start;
}

.vehicle-line {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px 12px;
}

.payments {
  display: grid;
}

.payments li {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 10px 16px;
  padding: 14px 0;
  border-top: 1px solid var(--line);
}

.payments li:first-child {
  border-top: 0;
  padding-top: 0;
}

.payment-end {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px;
}

.detail-contact {
  display: flex;
  flex-wrap: wrap;
  gap: 4px 16px;
}

.input-amount {
  font-size: var(--text-xl);
  font-weight: 600;
  font-variant-numeric: tabular-nums;
}

@media (min-width: 1100px) {
  .detail-grid {
    grid-template-columns: minmax(0, 1.5fr) minmax(300px, 1fr);
  }
}
</style>
