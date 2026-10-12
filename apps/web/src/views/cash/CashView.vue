<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { closeCashSession, fetchCashRegisters, openCashSession } from '../../api/cash'
import type { CashPermissions, CashRegisterState, CashSession, Currency } from '../../api/types'
import { useRequest } from '../../composables/useRequest'
import { useSessionStore } from '../../stores/session'
import { useAppStore } from '../../stores/app'
import { useUiStore } from '../../stores/ui'
import { formatMoney } from '../../lib/money'
import { formatTime } from '../../lib/time'
import { CURRENCIES } from '../../lib/dailyReport'
import PageHeader from '../../components/ui/PageHeader.vue'
import InlineAlert from '../../components/ui/InlineAlert.vue'
import StatusPill from '../../components/ui/StatusPill.vue'
import SheetDialog from '../../components/ui/SheetDialog.vue'
import FormField from '../../components/ui/FormField.vue'

/*
 * Caisse : état de chaque caisse des adresses autorisées. On ouvre la caisse
 * avec le fond USD et HTG, on encaisse, puis on la clôture avec les montants
 * comptés. Ouverture et clôture sont deux tâches séparées.
 */
const router = useRouter()
const session = useSessionStore()
const app = useAppStore()
const ui = useUiStore()
const loading = useRequest()
const action = useRequest()

const registers = ref<CashRegisterState[]>([])
const permissions = ref<CashPermissions>({ operate: false, approve: false, reports: false })
const task = ref<null | { kind: 'open'; register: CashRegisterState } | { kind: 'close'; register: CashRegisterState; session: CashSession }>(null)

const openForm = reactive({ opening_usd: '0.00', opening_htg: '0.00' })
const closeForm = reactive({ declared_usd: '', declared_htg: '', variance_note: '' })

async function load(): Promise<void> {
  const result = await loading.run(() => fetchCashRegisters())
  if (!result) return
  registers.value = result.data
  permissions.value = result.permissions
}

onMounted(load)

function startOpen(register: CashRegisterState): void {
  Object.assign(openForm, { opening_usd: register.suggested_opening.USD, opening_htg: register.suggested_opening.HTG })
  action.reset()
  task.value = { kind: 'open', register }
}

function startClose(register: CashRegisterState): void {
  if (!register.session) return
  // Proposé : le montant attendu. La personne compte puis confirme ou corrige.
  Object.assign(closeForm, {
    declared_usd: register.session.totals.USD.expected,
    declared_htg: register.session.totals.HTG.expected,
    variance_note: '',
  })
  action.reset()
  task.value = { kind: 'close', register, session: register.session }
}

const variance = computed<Record<Currency, number> | null>(() => {
  const current = task.value
  if (!current || current.kind !== 'close') return null
  const diff = (declared: string, expected: string): number => Math.round((Number(declared || 0) - Number(expected)) * 100) / 100
  return {
    USD: diff(closeForm.declared_usd, current.session.totals.USD.expected),
    HTG: diff(closeForm.declared_htg, current.session.totals.HTG.expected),
  }
})

const hasVariance = computed(() => !!variance.value && (variance.value.USD !== 0 || variance.value.HTG !== 0))

const varianceText = computed(() => {
  const value = variance.value
  if (!value) return ''
  return CURRENCIES.filter((currency) => value[currency] !== 0).map((currency) => signed(value[currency], currency)).join(' et ')
})

async function saveOpen(): Promise<void> {
  const current = task.value
  if (!current || current.kind !== 'open') return
  const result = await action.run(() => openCashSession(current.register.id, { ...openForm }))
  if (!result) return
  task.value = null
  ui.toast(`${current.register.name} est ouverte.`)
  await Promise.all([load(), session.refreshContext()])
}

async function saveClose(): Promise<void> {
  const current = task.value
  if (!current || current.kind !== 'close') return
  if (hasVariance.value && !closeForm.variance_note.trim()) {
    action.fail('Vérifiez les champs signalés.', { variance_note: 'Le montant compté diffère du montant attendu. Expliquez l’écart.' })
    return
  }
  const result = await action.run(() =>
    closeCashSession(current.session.id, {
      declared_usd: closeForm.declared_usd,
      declared_htg: closeForm.declared_htg,
      variance_note: hasVariance.value ? closeForm.variance_note.trim() : undefined,
    }),
  )
  if (!result) return
  task.value = null
  ui.toast(result.data.review_status === 'pending' ? 'Caisse clôturée. L’écart attend l’approbation d’un superviseur.' : 'Caisse clôturée.')
  await session.refreshContext()
  await router.push({ name: 'cash.session', params: { sessionId: result.data.id } })
}

function signed(value: number, currency: Currency): string {
  return `${value > 0 ? '+' : ''}${formatMoney(value, currency)}`
}
</script>

<template>
  <PageHeader title="Caisse" description="Ouvrez la caisse avant d’encaisser des espèces. Clôturez-la à la fin du quart avec les montants comptés.">
    <template v-if="permissions.reports" #actions>
      <RouterLink class="btn btn-secondary" :to="{ name: 'cash.report' }">Rapport journalier</RouterLink>
    </template>
  </PageHeader>

  <InlineAlert :message="loading.error.value" />
  <div v-if="loading.busy.value && !registers.length" class="skeleton" style="height: 220px"></div>

  <p v-else-if="!registers.length && !loading.error.value" class="panel text-secondary">
    Aucune caisse active pour vos adresses. Le propriétaire crée les caisses dans Configuration.
  </p>

  <div class="registers">
    <article v-for="register in registers" :key="register.id" class="panel register">
      <div class="panel-header">
        <div>
          <h2 class="title-section">{{ register.name }}</h2>
          <p class="text-small text-muted">{{ register.site.name }}</p>
        </div>
        <StatusPill :tone="register.session ? 'success' : 'neutral'" :label="register.session ? 'Ouverte' : 'Fermée'" />
      </div>

      <template v-if="register.session">
        <p class="text-small text-secondary">
          Ouverte à {{ register.session.opened_at ? formatTime(register.session.opened_at) : '' }} par {{ register.session.opened_by ?? 'un utilisateur' }}
        </p>
        <div class="expected">
          <div v-for="currency in CURRENCIES" :key="currency">
            <span class="text-small text-muted">Espèces attendues {{ currency }}</span>
            <strong class="display display-sm">{{ formatMoney(register.session.totals[currency].expected, currency) }}</strong>
            <span class="text-small text-muted">
              Fond {{ formatMoney(register.session.totals[currency].opening, currency) }} - entrées {{ formatMoney(register.session.totals[currency].in, currency) }} - sorties {{ formatMoney(register.session.totals[currency].out, currency) }}
            </span>
          </div>
        </div>
        <p v-if="register.session.pending_cash_payments" class="alert alert-warning">
          {{ register.session.pending_cash_payments }} paiement{{ register.session.pending_cash_payments > 1 ? 's' : '' }} en espèces à approuver avant la clôture.
        </p>
        <div class="btn-row">
          <RouterLink class="btn btn-secondary" :to="{ name: 'cash.session', params: { sessionId: register.session.id } }">Voir la session</RouterLink>
          <button v-if="register.session.can_close" class="btn btn-primary" type="button" :disabled="!app.canReachServer" @click="startClose(register)">Clôturer la caisse</button>
        </div>
      </template>

      <template v-else>
        <p class="text-small text-secondary">Les paiements en espèces sont refusés tant que la caisse est fermée.</p>
        <div v-if="permissions.operate" class="btn-row">
          <button class="btn btn-primary" type="button" :disabled="!app.canReachServer" @click="startOpen(register)">Ouvrir la caisse</button>
        </div>
      </template>
    </article>
  </div>

  <SheetDialog
    :open="task?.kind === 'open'"
    title="Ouvrir la caisse"
    :description="task?.kind === 'open' ? `${task.register.name} - ${task.register.site.name ?? ''}` : undefined"
    :locked="action.busy.value"
    @close="task = null"
  >
    <form id="open-form" class="form" novalidate @submit.prevent="saveOpen">
      <p class="text-small text-secondary">Comptez le fond de caisse. Proposé : les montants comptés à la dernière clôture.</p>
      <div class="grid-2">
        <FormField label="Fond USD" required :error="action.fieldErrors.value.opening_usd" v-slot="field">
          <input v-model="openForm.opening_usd" v-bind="field.attrs" class="input input-amount" type="number" inputmode="decimal" min="0" step="0.01" required />
        </FormField>
        <FormField label="Fond HTG" required :error="action.fieldErrors.value.opening_htg" v-slot="field">
          <input v-model="openForm.opening_htg" v-bind="field.attrs" class="input input-amount" type="number" inputmode="decimal" min="0" step="0.01" required />
        </FormField>
      </div>
      <InlineAlert :message="action.fieldErrors.value.register || action.error.value" />
    </form>
    <template #footer>
      <button class="btn btn-secondary" type="button" :disabled="action.busy.value" @click="task = null">Annuler</button>
      <button class="btn btn-primary" type="submit" form="open-form" :disabled="action.busy.value">Ouvrir la caisse</button>
    </template>
  </SheetDialog>

  <SheetDialog
    :open="task?.kind === 'close'"
    title="Clôturer la caisse"
    description="Comptez les espèces par devise. Le montant attendu est proposé : corrigez-le s’il diffère."
    :locked="action.busy.value"
    @close="task = null"
  >
    <form v-if="task?.kind === 'close'" id="close-form" class="form" novalidate @submit.prevent="saveClose">
      <dl class="facts">
        <div v-for="currency in CURRENCIES" :key="currency">
          <dt>Attendu {{ currency }}</dt>
          <dd>{{ formatMoney(task.session.totals[currency].expected, currency) }}</dd>
        </div>
      </dl>
      <div class="grid-2">
        <FormField label="Compté USD" required :error="action.fieldErrors.value.declared_usd" v-slot="field">
          <input v-model="closeForm.declared_usd" v-bind="field.attrs" class="input input-amount" type="number" inputmode="decimal" min="0" step="0.01" required />
        </FormField>
        <FormField label="Compté HTG" required :error="action.fieldErrors.value.declared_htg" v-slot="field">
          <input v-model="closeForm.declared_htg" v-bind="field.attrs" class="input input-amount" type="number" inputmode="decimal" min="0" step="0.01" required />
        </FormField>
      </div>
      <template v-if="hasVariance">
        <p class="alert alert-warning">
          Écart : {{ varianceText }}. Un superviseur devra l’approuver.
        </p>
        <FormField label="Explication de l’écart" required :error="action.fieldErrors.value.variance_note" v-slot="field">
          <textarea v-model="closeForm.variance_note" v-bind="field.attrs" class="textarea" rows="3" maxlength="1000" required></textarea>
        </FormField>
      </template>
      <InlineAlert :message="action.fieldErrors.value.session || action.error.value" />
    </form>
    <template #footer>
      <button class="btn btn-secondary" type="button" :disabled="action.busy.value" @click="task = null">Annuler</button>
      <button class="btn btn-primary" type="submit" form="close-form" :disabled="action.busy.value">Clôturer</button>
    </template>
  </SheetDialog>
</template>

<style scoped>
.registers {
  display: grid;
  gap: 16px;
}

@media (min-width: 960px) {
  .registers {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

.register {
  align-content: start;
}

.expected {
  display: grid;
  gap: 12px;
}

.expected > div {
  display: grid;
  gap: 2px;
}
</style>
