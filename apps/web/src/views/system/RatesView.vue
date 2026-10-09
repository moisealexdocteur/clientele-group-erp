<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { fetchExchangeRates, setExchangeRate } from '../../api/finance'
import type { ExchangeRate } from '../../api/types'
import { useSessionStore } from '../../stores/session'
import { useAppStore } from '../../stores/app'
import { useUiStore } from '../../stores/ui'
import { useRequest } from '../../composables/useRequest'
import { formatRate } from '../../lib/money'
import { formatDate, formatDateTime, toDateInput } from '../../lib/time'
import PageHeader from '../../components/ui/PageHeader.vue'
import FormField from '../../components/ui/FormField.vue'
import InlineAlert from '../../components/ui/InlineAlert.vue'
import SheetDialog from '../../components/ui/SheetDialog.vue'
import StatusPill from '../../components/ui/StatusPill.vue'

/*
 * Taux HTG/USD du groupe, réglage du socle commun dans Configuration. Le formulaire reprend le
 * dernier taux ; un taux sous la référence BRH demande une confirmation
 * et un motif, et reste signalé dans l'historique.
 */
const session = useSessionStore()
const app = useAppStore()
const ui = useUiStore()
const loading = useRequest()
const saving = useRequest()

const current = ref<ExchangeRate | null>(null)
const history = ref<ExchangeRate[]>([])
const open = ref(false)
const canManage = ref(false)
const today = toDateInput()

const form = reactive({
  rate: '',
  brh: '',
  brh_date: today,
  confirm: false,
  note: '',
})

const belowBrh = computed(() => Boolean(form.rate && form.brh && Number(form.rate) < Number(form.brh)))

const missing = computed(() => {
  const items: string[] = []
  if (!(Number(form.rate) > 0)) items.push('Le taux : nombre de gourdes pour 1 USD')
  if (form.brh && !form.brh_date) items.push('La date du taux BRH')
  if (belowBrh.value) {
    if (!form.confirm) items.push('La confirmation du taux sous la référence BRH')
    if (!form.note.trim()) items.push('Le motif du taux sous la référence BRH')
  }
  return items
})

function when(value: string | null): string {
  return value ? formatDateTime(value) : ''
}

async function load(): Promise<void> {
  const result = await loading.run(() => fetchExchangeRates())
  if (!result) return
  current.value = result.current
  history.value = result.history
  canManage.value = result.can_manage
}

onMounted(load)

function openForm(): void {
  Object.assign(form, {
    rate: current.value ? Number(current.value.rate_htg_per_usd).toString() : '',
    brh: current.value?.brh_reference_rate ? Number(current.value.brh_reference_rate).toString() : '',
    brh_date: current.value?.brh_reference_date ?? today,
    confirm: false,
    note: '',
  })
  saving.reset()
  open.value = true
}

async function save(): Promise<void> {
  if (missing.value.length) return
  const result = await saving.run(() => setExchangeRate({
    rate_htg_per_usd: form.rate,
    brh_reference_rate: form.brh || undefined,
    brh_reference_date: form.brh ? form.brh_date : undefined,
    confirm_below_brh: belowBrh.value ? form.confirm : undefined,
    note: form.note.trim() || undefined,
  }))
  if (!result) return
  open.value = false
  await Promise.all([load(), session.refreshContext().catch(() => undefined)])
  ui.toast(result.data.below_brh ? 'Taux enregistré sous la référence BRH. L’écart est signalé.' : 'Taux enregistré. Il s’applique aux prochains paiements.')
}
</script>

<template>
  <PageHeader title="Taux de change" description="Taux unique du groupe, appliqué par toutes les sociétés aux paiements dans une autre devise que celle de la transaction.">
    <template #actions>
      <button v-if="canManage" class="btn btn-primary" type="button" :disabled="!app.canReachServer" @click="openForm">Saisir le taux du jour</button>
    </template>
  </PageHeader>

  <InlineAlert :message="loading.error.value" />
  <div v-if="loading.busy.value && !history.length" class="skeleton" style="height: 220px"></div>

  <div v-else class="stack-lg">
    <section class="panel current" aria-labelledby="rate-title">
      <h2 id="rate-title" class="title-section">Taux en vigueur</h2>
      <template v-if="current">
        <p class="display display-xl">{{ formatRate(current.rate_htg_per_usd) }}</p>
        <p class="text-secondary text-small">
          Depuis le {{ when(current.effective_at) }}<template v-if="current.set_by"> - saisi par {{ current.set_by }}</template>
        </p>
        <p v-if="current.brh_reference_rate" class="text-small">
          Référence BRH<template v-if="current.brh_reference_date"> du {{ formatDate(current.brh_reference_date) }}</template> : {{ formatRate(current.brh_reference_rate) }}
        </p>
        <p v-if="current.below_brh" class="alert alert-warning">Ce taux est inférieur à la référence BRH. Motif : {{ current.note }}</p>
      </template>
      <p v-else class="alert alert-warning">
        Aucun taux n’est défini. Les paiements dans une autre devise sont refusés tant qu’il n’est pas saisi.
      </p>
    </section>

    <section v-if="history.length" class="panel" aria-labelledby="history-title">
      <h2 id="history-title" class="title-section">Historique</h2>
      <ul class="history">
        <li v-for="item in history" :key="item.id">
          <span class="stack" style="gap: 2px">
            <strong>{{ formatRate(item.rate_htg_per_usd) }}</strong>
            <span class="text-secondary text-small">{{ when(item.effective_at) }}<template v-if="item.set_by"> - {{ item.set_by }}</template></span>
          </span>
          <StatusPill v-if="item.below_brh" tone="warning" label="Sous BRH" />
        </li>
      </ul>
    </section>
  </div>

  <SheetDialog :open="open" title="Saisir le taux du jour" description="Les champs marqués * sont obligatoires." :locked="saving.busy.value" @close="open = false">
    <form id="rate-form" class="form" novalidate @submit.prevent="save">
      <FormField label="Taux : gourdes pour 1 USD" required :error="saving.fieldErrors.value.rate_htg_per_usd" v-slot="field">
        <input v-model="form.rate" v-bind="field.attrs" class="input input-amount" type="number" inputmode="decimal" min="0.0001" step="0.0001" />
      </FormField>
      <div class="grid-2">
        <FormField label="Référence BRH (HTG pour 1 USD)" help="Facultatif. Sert à l’alerte de taux bas." :error="saving.fieldErrors.value.brh_reference_rate" v-slot="field">
          <input v-model="form.brh" v-bind="field.attrs" class="input" type="number" inputmode="decimal" min="0.0001" step="0.0001" />
        </FormField>
        <FormField label="Date du taux BRH" :required="Boolean(form.brh)" :error="saving.fieldErrors.value.brh_reference_date" v-slot="field">
          <input v-model="form.brh_date" v-bind="field.attrs" class="input" type="date" :max="today" />
        </FormField>
      </div>
      <template v-if="belowBrh">
        <p class="alert alert-warning">Ce taux est inférieur à la référence BRH. Il sera signalé dans l’historique et le journal.</p>
        <label class="check">
          <input v-model="form.confirm" type="checkbox" />
          <span>Je confirme ce taux inférieur à la référence BRH.<span class="required" aria-hidden="true">*</span></span>
        </label>
      </template>
      <FormField label="Motif ou note" :required="belowBrh" :error="saving.fieldErrors.value.note || saving.fieldErrors.value.confirm_below_brh" v-slot="field">
        <textarea v-model="form.note" v-bind="field.attrs" class="textarea" rows="2" maxlength="500"></textarea>
      </FormField>
      <div v-if="missing.length" class="missing" role="status">
        <strong>À compléter</strong>
        <ul>
          <li v-for="item in missing" :key="item">{{ item }}</li>
        </ul>
      </div>
      <InlineAlert :message="saving.error.value" />
    </form>
    <template #footer>
      <button class="btn btn-secondary" type="button" :disabled="saving.busy.value" @click="open = false">Annuler</button>
      <button class="btn btn-primary" type="submit" form="rate-form" :disabled="saving.busy.value || missing.length > 0 || !app.canReachServer">Enregistrer le taux</button>
    </template>
  </SheetDialog>
</template>

<style scoped>
.current {
  display: grid;
  gap: 8px;
}

.history {
  display: grid;
}

.history li {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 10px 0;
  border-top: 1px solid var(--line);
}

.history li:first-child {
  border-top: 0;
}

.input-amount {
  font-size: var(--text-xl);
  font-weight: 600;
  font-variant-numeric: tabular-nums;
}
</style>
