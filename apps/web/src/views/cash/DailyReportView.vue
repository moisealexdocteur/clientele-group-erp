<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { fetchDailyCashReport, recordDailyReportExport, type DailyReportFormat } from '../../api/cash'
import type { Currency, DailyCashReport } from '../../api/types'
import { useRequest } from '../../composables/useRequest'
import { useSessionStore } from '../../stores/session'
import { useAppStore } from '../../stores/app'
import { useUiStore } from '../../stores/ui'
import { formatMoney } from '../../lib/money'
import { formatDate, formatDateTime, formatTime, toDateInput } from '../../lib/time'
import {
  CURRENCIES,
  buildDailyReportPdf,
  buildDailyReportXlsx,
  paymentMethodLabels,
  reportFilename,
  sessionStateLabel,
} from '../../lib/dailyReport'
import { downloadBytes } from '../../lib/xlsx'
import PageHeader from '../../components/ui/PageHeader.vue'
import InlineAlert from '../../components/ui/InlineAlert.vue'
import StatusPill from '../../components/ui/StatusPill.vue'
import FormField from '../../components/ui/FormField.vue'

/*
 * Rapport journalier de caisse : chargé tout de suite pour aujourd'hui et
 * toutes les adresses autorisées. Impression A4, PDF et Excel ;
 * chaque impression et chaque export est journalisé.
 */
const session = useSessionStore()
const app = useAppStore()
const ui = useUiStore()
const loading = useRequest()

const date = ref(toDateInput(app.now))
const siteId = ref('')
const report = ref<DailyCashReport | null>(null)
const exporting = ref<DailyReportFormat | null>(null)

const sites = computed(() => session.sites)
const dayLabel = computed(() => formatDate(`${date.value}T12:00:00`))
const movements = computed(() =>
  (report.value?.sessions ?? []).flatMap((item) => (item.movements ?? []).map((movement) => ({ session: item, movement }))),
)

async function load(): Promise<void> {
  const result = await loading.run(() => fetchDailyCashReport({ date: date.value, site_id: siteId.value || undefined }))
  if (result) report.value = result.data
}

onMounted(load)
watch([date, siteId], load)

function signed(value: string | null | undefined, currency: Currency): string {
  const amount = Number(value ?? 0)
  return `${amount > 0 ? '+' : ''}${formatMoney(value ?? 0, currency)}`
}

async function logExport(format: DailyReportFormat): Promise<void> {
  if (!report.value) return
  await recordDailyReportExport({ date: report.value.date, site_id: siteId.value || undefined, format })
}

async function exportFile(format: 'pdf' | 'xlsx'): Promise<void> {
  const value = report.value
  if (!value || exporting.value) return
  exporting.value = format
  try {
    await logExport(format)
    if (format === 'pdf') {
      downloadBytes(await buildDailyReportPdf(value), reportFilename(value, 'pdf'), 'application/pdf')
    } else {
      downloadBytes(buildDailyReportXlsx(value), reportFilename(value, 'xlsx'), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
    }
  } catch (error) {
    ui.toast(error instanceof Error ? error.message : 'L’export n’a pas pu être créé.', 'danger')
  } finally {
    exporting.value = null
  }
}

async function print(): Promise<void> {
  if (!report.value || exporting.value) return
  exporting.value = 'print_a4'
  const style = document.createElement('style')
  style.textContent = '@page { size: A4; margin: 14mm; }'
  try {
    await logExport('print_a4')
    document.head.append(style)
    await nextTick()
    window.print()
  } catch (error) {
    ui.toast(error instanceof Error ? error.message : 'L’impression n’a pas pu être enregistrée.', 'danger')
  } finally {
    style.remove()
    exporting.value = null
  }
}
</script>

<template>
  <div class="report-page">
    <div class="no-print">
      <PageHeader title="Rapport journalier" description="Espèces par caisse et par devise, écarts et autres règlements du jour. Aucune donnée client." :back="{ name: 'cash' }" back-label="Caisse" />

      <div class="panel filters">
        <div class="grid-2">
          <FormField label="Jour" v-slot="field">
            <input v-model="date" v-bind="field.attrs" class="input" type="date" :max="toDateInput(app.now)" required />
          </FormField>
          <FormField label="Adresse" v-slot="field">
            <select v-model="siteId" v-bind="field.attrs" class="select">
              <option value="">Toutes les adresses autorisées</option>
              <option v-for="site in sites" :key="site.id" :value="site.id">{{ site.name }}</option>
            </select>
          </FormField>
        </div>
        <div class="btn-row">
          <button class="btn btn-primary" type="button" :disabled="!report || !!exporting || !app.canReachServer" @click="exportFile('pdf')">PDF</button>
          <button class="btn btn-secondary" type="button" :disabled="!report || !!exporting || !app.canReachServer" @click="exportFile('xlsx')">Excel</button>
          <button class="btn btn-secondary" type="button" :disabled="!report || !!exporting || !app.canReachServer" @click="print">Imprimer</button>
        </div>
      </div>

      <InlineAlert :message="loading.error.value" />
    </div>

    <div v-if="loading.busy.value && !report" class="skeleton no-print" style="height: 320px"></div>

    <!-- Version A4 : écran et impression pleine page. -->
    <article v-if="report" class="report-a4 stack-lg">
      <header class="print-heading">
        <strong>{{ report.company.name }}</strong>
        <span v-if="report.company.tax_identification_number">NIF {{ report.company.tax_identification_number }}</span>
        <h1 class="title-page">Rapport journalier de caisse</h1>
        <span>{{ dayLabel }} - heure de Cap-Haïtien - {{ report.site ?? 'Toutes les adresses autorisées' }}</span>
        <span class="text-small">Établi le {{ formatDateTime(report.generated_at) }}{{ report.generated_by ? ` par ${report.generated_by}` : '' }}</span>
      </header>

      <section class="totals">
        <div v-for="currency in CURRENCIES" :key="currency" class="panel total">
          <span class="text-small text-muted">Espèces attendues {{ currency }}</span>
          <strong class="display display-md">{{ formatMoney(report.totals[currency].expected, currency) }}</strong>
          <dl class="facts">
            <div><dt>Fond</dt><dd>{{ formatMoney(report.totals[currency].opening, currency) }}</dd></div>
            <div><dt>Entrées</dt><dd>{{ formatMoney(report.totals[currency].in, currency) }}</dd></div>
            <div><dt>Sorties</dt><dd>{{ formatMoney(report.totals[currency].out, currency) }}</dd></div>
            <div><dt>Compté (sessions clôturées)</dt><dd>{{ formatMoney(report.totals[currency].declared, currency) }}</dd></div>
            <div><dt>Écart</dt><dd :class="{ negative: Number(report.totals[currency].variance) < 0 }">{{ signed(report.totals[currency].variance, currency) }}</dd></div>
          </dl>
        </div>
      </section>

      <InlineAlert v-if="report.open_sessions" tone="warning" :message="`${report.open_sessions} session${report.open_sessions > 1 ? 's' : ''} encore ouverte${report.open_sessions > 1 ? 's' : ''} : le compté et l’écart ne les incluent pas.`" />
      <InlineAlert v-if="report.pending_reviews" tone="warning" :message="`${report.pending_reviews} écart${report.pending_reviews > 1 ? 's' : ''} en attente d’approbation.`" />

      <section class="panel">
        <h2 class="title-section">Sessions de caisse</h2>
        <p v-if="!report.sessions.length" class="text-secondary">Aucune session ouverte ce jour.</p>
        <ul v-else class="list">
          <li v-for="item in report.sessions" :key="item.id">
            <RouterLink class="list-row" :to="{ name: 'cash.session', params: { sessionId: item.id } }">
              <div class="row-main">
                <strong>{{ item.cash_register.name }}</strong>
                <span class="text-small text-muted">
                  {{ item.site.name }} - {{ item.opened_at ? formatTime(item.opened_at) : '' }} {{ item.opened_by }}<template v-if="item.closed_at"> à {{ formatTime(item.closed_at) }} {{ item.closed_by }}</template>
                </span>
                <span class="text-small">
                  <template v-for="currency in CURRENCIES" :key="currency">
                    {{ currency }} attendu {{ formatMoney(item.totals[currency].expected, currency) }}<template v-if="item.variance">, écart {{ signed(item.variance[currency], currency) }}</template>.
                  </template>
                </span>
                <span v-if="item.variance_note" class="text-small text-secondary">Explication : {{ item.variance_note }}</span>
              </div>
              <StatusPill :tone="item.status === 'open' ? 'success' : item.review_status === 'pending' ? 'warning' : 'neutral'" :label="sessionStateLabel(item)" />
            </RouterLink>
          </li>
        </ul>
      </section>

      <section class="panel">
        <h2 class="title-section">Mouvements d’espèces par nature</h2>
        <p v-if="!report.movements_by_kind.length" class="text-secondary">Aucun mouvement d’espèces.</p>
        <dl v-else class="facts">
          <div v-for="row in report.movements_by_kind" :key="`${row.kind}-${row.currency}`">
            <dt>{{ row.label }} ({{ row.count }})</dt>
            <dd>{{ row.direction === 'out' ? '-' : '' }}{{ formatMoney(row.amount, row.currency) }}</dd>
          </div>
        </dl>
      </section>

      <section class="panel">
        <h2 class="title-section">Encaissements Car Rental approuvés</h2>
        <p v-if="!report.rental_payments.length" class="text-secondary">Aucun encaissement approuvé ce jour.</p>
        <dl v-else class="facts">
          <div v-for="row in report.rental_payments" :key="`${row.method}-${row.currency}`">
            <dt>
              {{ paymentMethodLabels[row.method] ?? row.method }} ({{ row.count }})
              <template v-if="row.outside_session_count"><br /><span class="text-small">dont {{ formatMoney(row.outside_session_amount, row.currency) }} hors session de caisse ({{ row.outside_session_count }})</span></template>
            </dt>
            <dd>{{ formatMoney(row.amount, row.currency) }}</dd>
          </div>
        </dl>
        <p class="text-small text-muted">Réimpressions de reçus ce jour : {{ report.reprints }}.</p>
      </section>

      <section v-if="movements.length" class="panel print-detail">
        <h2 class="title-section">Détail des mouvements</h2>
        <dl class="facts">
          <div v-for="{ session: item, movement } in movements" :key="movement.id">
            <dt>
              {{ movement.occurred_at ? formatTime(movement.occurred_at) : '' }} - {{ item.cash_register.name }} - {{ movement.label }}
              <template v-if="movement.receipt_number"> - reçu {{ movement.receipt_number }}</template>
              <template v-if="movement.reservation_number"> - réservation {{ movement.reservation_number }}</template>
            </dt>
            <dd>{{ movement.direction === 'out' ? '-' : '' }}{{ formatMoney(movement.amount, movement.currency) }}</dd>
          </div>
        </dl>
      </section>

      <p class="print-sign">Vérifié par : ____________________________ Signature : ____________________</p>
    </article>

  </div>
</template>

<style scoped>
.filters {
  margin-bottom: 16px;
}

.totals {
  display: grid;
  gap: 16px;
}

@media (min-width: 720px) {
  .totals {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

.total {
  gap: 8px;
}

.row-main {
  display: grid;
  gap: 2px;
  min-width: 0;
}

.negative {
  color: var(--danger, #c50f1f);
}

.print-heading,
.print-sign,
.print-detail {
  display: none;
}


@media print {
  .no-print {
    display: none !important;
  }

  .print-heading {
    display: grid;
    gap: 2px;
    margin-bottom: 8px;
  }

  .print-detail,
  .print-sign {
    display: grid;
  }

  .panel {
    box-shadow: none;
    border: 1px solid #c8c8c8;
    break-inside: avoid;
  }
}
</style>
