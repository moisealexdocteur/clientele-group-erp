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
 * toutes les adresses autorisées. Impression A4 ou 80 mm, PDF et Excel ;
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
const printMode = ref<'a4' | '80mm'>('a4')

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

async function print(mode: 'a4' | '80mm'): Promise<void> {
  if (!report.value || exporting.value) return
  exporting.value = mode === 'a4' ? 'print_a4' : 'print_80mm'
  const style = document.createElement('style')
  style.textContent = mode === 'a4' ? '@page { size: A4; margin: 14mm; }' : '@page { size: 80mm auto; margin: 0; }'
  try {
    await logExport(mode === 'a4' ? 'print_a4' : 'print_80mm')
    printMode.value = mode
    document.head.append(style)
    await nextTick()
    window.print()
  } catch (error) {
    ui.toast(error instanceof Error ? error.message : 'L’impression n’a pas pu être enregistrée.', 'danger')
  } finally {
    style.remove()
    printMode.value = 'a4'
    exporting.value = null
  }
}
</script>

<template>
  <div class="report-page" :class="`print-${printMode}`">
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
          <button class="btn btn-secondary" type="button" :disabled="!report || !!exporting || !app.canReachServer" @click="print('a4')">Imprimer A4</button>
          <button class="btn btn-secondary" type="button" :disabled="!report || !!exporting || !app.canReachServer" @click="print('80mm')">Imprimer 80 mm</button>
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
        <h2 class="title-section">Autres règlements approuvés</h2>
        <p v-if="!report.other_payments.length" class="text-secondary">Aucun virement ni crédit approuvé ce jour.</p>
        <dl v-else class="facts">
          <div v-for="row in report.other_payments" :key="`${row.method}-${row.currency}`">
            <dt>{{ paymentMethodLabels[row.method] ?? row.method }} ({{ row.count }})</dt>
            <dd>{{ formatMoney(row.amount, row.currency) }}</dd>
          </div>
        </dl>
        <p class="text-small text-muted">Réimpressions de reçus et de rapports ce jour : {{ report.reprints }}.</p>
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

    <!-- Version 80 mm : impression thermique seulement. -->
    <article v-if="report" class="report-80" aria-hidden="true">
      <div class="ticket-head">
        <strong>{{ report.company.display_name }}</strong>
        <span>RAPPORT JOURNALIER</span>
        <span>{{ dayLabel }}</span>
        <span>{{ report.site ?? 'Toutes les adresses' }}</span>
      </div>
      <template v-for="currency in CURRENCIES" :key="currency">
        <p class="ticket-section">{{ currency }}</p>
        <dl class="ticket-lines">
          <div><dt>Fond</dt><dd>{{ formatMoney(report.totals[currency].opening, currency) }}</dd></div>
          <div><dt>Entrées</dt><dd>{{ formatMoney(report.totals[currency].in, currency) }}</dd></div>
          <div><dt>Sorties</dt><dd>{{ formatMoney(report.totals[currency].out, currency) }}</dd></div>
          <div class="strong"><dt>Attendu</dt><dd>{{ formatMoney(report.totals[currency].expected, currency) }}</dd></div>
          <div><dt>Compté</dt><dd>{{ formatMoney(report.totals[currency].declared, currency) }}</dd></div>
          <div class="strong"><dt>Écart</dt><dd>{{ signed(report.totals[currency].variance, currency) }}</dd></div>
        </dl>
      </template>
      <p class="ticket-section">Sessions ({{ report.sessions.length }})</p>
      <dl class="ticket-lines">
        <div v-for="item in report.sessions" :key="item.id">
          <dt>{{ item.cash_register.name }}</dt>
          <dd>{{ sessionStateLabel(item) }}</dd>
        </div>
      </dl>
      <template v-if="report.other_payments.length">
        <p class="ticket-section">Autres règlements</p>
        <dl class="ticket-lines">
          <div v-for="row in report.other_payments" :key="`${row.method}-${row.currency}`">
            <dt>{{ paymentMethodLabels[row.method] ?? row.method }}</dt>
            <dd>{{ formatMoney(row.amount, row.currency) }}</dd>
          </div>
        </dl>
      </template>
      <p class="ticket-foot">Établi le {{ formatDateTime(report.generated_at) }}</p>
      <p class="ticket-sign">Signature</p>
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
.print-detail,
.report-80 {
  display: none;
}

.ticket-head {
  display: grid;
  justify-items: center;
  text-align: center;
  padding-bottom: 4px;
  border-bottom: 1px dashed #000000;
}

.ticket-section {
  margin-top: 6px;
  font-weight: 700;
  border-bottom: 1px solid #000000;
}

.ticket-lines div {
  display: flex;
  justify-content: space-between;
  gap: 8px;
}

.ticket-lines dd {
  margin: 0;
  text-align: right;
}

.ticket-lines .strong {
  font-weight: 700;
}

.ticket-foot {
  margin-top: 8px;
  text-align: center;
  font-size: 10px;
}

.ticket-sign {
  margin-top: 18px;
  border-top: 1px solid #000000;
  font-size: 10px;
}

@media print {
  .no-print {
    display: none !important;
  }

  .print-a4 .print-heading {
    display: grid;
    gap: 2px;
    margin-bottom: 8px;
  }

  .print-a4 .print-detail,
  .print-a4 .print-sign {
    display: grid;
  }

  .print-a4 .panel {
    box-shadow: none;
    border: 1px solid #c8c8c8;
    break-inside: avoid;
  }

  .print-80mm .report-a4 {
    display: none !important;
  }

  .print-80mm .report-80 {
    display: block;
    width: 80mm;
    padding: 4mm;
    color: #000000;
    font-family: 'Segoe UI', Roboto, Arial, sans-serif;
    font-size: 11.5px;
    line-height: 1.35;
  }
}
</style>
