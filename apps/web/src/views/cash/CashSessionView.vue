<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { fetchCashSession, reviewCashSession } from '../../api/cash'
import type { CashSession, Currency } from '../../api/types'
import { useRequest } from '../../composables/useRequest'
import { useAppStore } from '../../stores/app'
import { useUiStore } from '../../stores/ui'
import { formatMoney } from '../../lib/money'
import { formatDateTime, formatTime } from '../../lib/time'
import { CURRENCIES, sessionStateLabel } from '../../lib/dailyReport'
import PageHeader from '../../components/ui/PageHeader.vue'
import InlineAlert from '../../components/ui/InlineAlert.vue'
import StatusPill from '../../components/ui/StatusPill.vue'
import SheetDialog from '../../components/ui/SheetDialog.vue'
import FormField from '../../components/ui/FormField.vue'

/*
 * Session de caisse : totaux par devise, mouvements, écart et décision.
 * Le rapport imprimable est le rapport journalier (A4, PDF, Excel).
 */
const props = defineProps<{ sessionId: string }>()

const app = useAppStore()
const ui = useUiStore()
const loading = useRequest()
const action = useRequest()

const record = ref<CashSession | null>(null)
const reviewOpen = ref(false)
const reviewNote = ref('')

const tone = computed(() => {
  const value = record.value
  if (!value) return 'neutral' as const
  if (value.status === 'open') return 'success' as const
  return value.review_status === 'pending' ? ('warning' as const) : ('neutral' as const)
})


onMounted(async () => {
  const result = await loading.run(() => fetchCashSession(props.sessionId))
  if (result) record.value = result.data
})

function signed(value: string | null | undefined, currency: Currency): string {
  const amount = Number(value ?? 0)
  return `${amount > 0 ? '+' : ''}${formatMoney(value ?? 0, currency)}`
}

async function saveReview(): Promise<void> {
  if (!reviewNote.value.trim()) {
    action.fail('Vérifiez les champs signalés.', { note: 'Indiquez la décision ou la mesure prise.' })
    return
  }
  const result = await action.run(() => reviewCashSession(props.sessionId, reviewNote.value.trim()))
  if (!result) return
  record.value = result.data
  reviewOpen.value = false
  ui.toast('Écart approuvé.')
}

</script>

<template>
  <div class="no-print">
    <PageHeader
      :title="record?.cash_register.name ?? 'Session de caisse'"
      :description="record ? `${record.site.name ?? ''} - ouverte le ${record.opened_at ? formatDateTime(record.opened_at) : ''}` : undefined"
      :back="{ name: 'cash' }"
      back-label="Caisse"
    >
      <template v-if="record" #before-title>
        <StatusPill :tone="tone" :label="sessionStateLabel(record)" />
      </template>
    </PageHeader>

    <InlineAlert :message="loading.error.value" />
    <div v-if="loading.busy.value" class="skeleton" style="height: 320px"></div>

    <div v-else-if="record" class="session-grid">
      <section class="panel">
        <h2 class="title-section">Espèces</h2>
        <div class="table-scroll">
          <table class="cash-table">
            <thead>
              <tr>
                <th scope="col">Devise</th>
                <th scope="col">Fond</th>
                <th scope="col">Entrées</th>
                <th scope="col">Sorties</th>
                <th scope="col">Attendu</th>
                <th v-if="record.declared" scope="col">Compté</th>
                <th v-if="record.variance" scope="col">Écart</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="currency in CURRENCIES" :key="currency">
                <th scope="row">{{ currency }}</th>
                <td>{{ formatMoney(record.totals[currency].opening, currency) }}</td>
                <td>{{ formatMoney(record.totals[currency].in, currency) }}</td>
                <td>{{ formatMoney(record.totals[currency].out, currency) }}</td>
                <td><strong>{{ formatMoney(record.totals[currency].expected, currency) }}</strong></td>
                <td v-if="record.declared">{{ formatMoney(record.declared[currency], currency) }}</td>
                <td v-if="record.variance" :class="{ negative: Number(record.variance[currency]) < 0, positive: Number(record.variance[currency]) > 0 }">
                  {{ signed(record.variance[currency], currency) }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <dl class="facts">
          <div><dt>Ouverte par</dt><dd>{{ record.opened_by ?? 'Non renseigné' }}</dd></div>
          <div v-if="record.closed_at"><dt>Clôturée</dt><dd>{{ formatDateTime(record.closed_at) }} - {{ record.closed_by }}</dd></div>
        </dl>
        <p v-if="record.pending_cash_payments" class="alert alert-warning">
          {{ record.pending_cash_payments }} paiement{{ record.pending_cash_payments > 1 ? 's' : '' }} en espèces à approuver avant la clôture.
        </p>
      </section>

      <section v-if="record.variance_note || record.review_status !== 'none'" class="panel">
        <h2 class="title-section">Écart de clôture</h2>
        <p>{{ record.variance_note }}</p>
        <p v-if="record.review_status === 'approved'" class="text-small text-secondary">
          Approuvé le {{ record.reviewed_at ? formatDateTime(record.reviewed_at) : '' }} par {{ record.reviewed_by }} : {{ record.review_note }}
        </p>
        <p v-else class="alert alert-warning">En attente d’approbation par un superviseur.</p>
        <div v-if="record.can_review" class="btn-row">
          <button class="btn btn-primary" type="button" :disabled="!app.canReachServer" @click="reviewOpen = true; reviewNote = ''; action.reset()">Approuver l’écart</button>
        </div>
      </section>

      <section class="panel movements">
        <h2 class="title-section">Mouvements</h2>
        <p v-if="!record.movements?.length" class="text-secondary">Aucun mouvement d’espèces pour cette session.</p>
        <ul v-else class="list">
          <li v-for="movement in record.movements" :key="movement.id" class="list-row movement">
            <div>
              <strong>{{ movement.label }}</strong>
              <span class="text-small text-muted">
                {{ movement.occurred_at ? formatTime(movement.occurred_at) : '' }}
                <template v-if="movement.receipt_number"> - reçu {{ movement.receipt_number }}</template>
                <template v-if="movement.reservation_number"> - réservation {{ movement.reservation_number }}</template>
              </span>
              <span class="links">
                <RouterLink v-if="movement.reservation_id" :to="{ name: 'rental.reservation', params: { reservationId: movement.reservation_id } }">Réservation</RouterLink>
                <RouterLink v-if="movement.payment_id && movement.receipt_number" :to="{ name: 'receipt', params: { paymentId: movement.payment_id } }">Reçu</RouterLink>
              </span>
            </div>
            <strong :class="movement.direction === 'out' ? 'negative' : 'positive'">
              {{ movement.direction === 'out' ? '-' : '+' }}{{ formatMoney(movement.amount, movement.currency) }}
            </strong>
          </li>
        </ul>
      </section>
    </div>
  </div>


  <SheetDialog :open="reviewOpen" title="Approuver l’écart" description="Indiquez la décision ou la mesure prise. Elle est journalisée à votre nom." :locked="action.busy.value" @close="reviewOpen = false">
    <form id="review-form" class="form" novalidate @submit.prevent="saveReview">
      <FormField label="Décision" required :error="action.fieldErrors.value.note" v-slot="field">
        <textarea v-model="reviewNote" v-bind="field.attrs" class="textarea" rows="3" maxlength="1000" required></textarea>
      </FormField>
      <InlineAlert :message="action.fieldErrors.value.session || action.error.value" />
    </form>
    <template #footer>
      <button class="btn btn-secondary" type="button" :disabled="action.busy.value" @click="reviewOpen = false">Annuler</button>
      <button class="btn btn-primary" type="submit" form="review-form" :disabled="action.busy.value">Approuver</button>
    </template>
  </SheetDialog>
</template>

<style scoped>
.session-grid {
  display: grid;
  gap: 16px;
  align-items: start;
}

@media (min-width: 1100px) {
  .session-grid {
    grid-template-columns: minmax(0, 1.2fr) minmax(0, 1fr);
  }

  .movements {
    grid-column: 1 / -1;
  }
}

.table-scroll {
  overflow-x: auto;
}

.cash-table {
  width: 100%;
  border-collapse: collapse;
  font-size: var(--text-sm);
  font-variant-numeric: tabular-nums;
}

.cash-table th,
.cash-table td {
  padding: 10px 8px;
  border-top: 1px solid var(--line);
  text-align: right;
  white-space: nowrap;
}

.cash-table thead th {
  border-top: 0;
  color: var(--ink-3);
  font-weight: 400;
}

.cash-table th:first-child {
  text-align: left;
}

.negative {
  color: var(--danger, #c50f1f);
}

.positive {
  color: var(--ink-1, inherit);
}

.movement > div {
  display: grid;
  gap: 2px;
}

.links {
  display: flex;
  gap: 16px;
  font-size: var(--text-sm);
}

.links a {
  min-height: 32px;
  display: inline-flex;
  align-items: center;
}

</style>
