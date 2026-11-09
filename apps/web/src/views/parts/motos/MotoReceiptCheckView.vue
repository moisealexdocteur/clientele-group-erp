<script setup lang="ts">
import { reactive, ref } from 'vue'
import { checkMotoReceipt } from '../../../api/motos'
import type { MotoReceiptCheck } from '../../../api/types'
import { useRequest } from '../../../composables/useRequest'
import { useAppStore } from '../../../stores/app'
import { formatMoney } from '../../../lib/money'
import { formatDateTime } from '../../../lib/time'
import PageHeader from '../../../components/ui/PageHeader.vue'
import FormField from '../../../components/ui/FormField.vue'
import InlineAlert from '../../../components/ui/InlineAlert.vue'
import ScanButton from '../../../components/ui/ScanButton.vue'

/*
 * Contrôle d'un reçu de vente présenté par un client : scanner son QR, ou
 * saisir le numéro et le code de vérification imprimés. Le résultat montre
 * la vente enregistrée ; tout écart avec le papier ou la moto est un faux.
 */
const app = useAppStore()
const request = useRequest()
const form = reactive({ number: '', code: '' })
const result = ref<MotoReceiptCheck | null>(null)

async function check(payload: { scanned?: string; number?: string; code?: string }): Promise<void> {
  result.value = null
  const response = await request.run(() => checkMotoReceipt(payload))
  if (response) result.value = response
}

function scanned(value: string): void {
  void check({ scanned: value })
}
</script>

<template>
  <PageHeader title="Vérifier un reçu" description="Reçu de vente de moto présenté par un client." :back="{ name: 'parts.motos' }" back-label="Motos" />

  <section class="panel stack">
    <div class="scan-line">
      <ScanButton label="QR du reçu" raw @scanned="scanned" />
      <p class="text-small text-secondary">Scannez le QR imprimé sur le reçu, ou saisissez le numéro et le code.</p>
    </div>
    <form class="form" novalidate @submit.prevent="check({ number: form.number, code: form.code })">
      <div class="grid-2">
        <FormField label="N° du reçu" help="8 chiffres, par exemple 0000 0012." v-slot="field">
          <input v-model.trim="form.number" v-bind="field.attrs" class="input input-code" inputmode="numeric" maxlength="12" autocomplete="off" />
        </FormField>
        <FormField label="Code de vérification" help="Imprimé sous le numéro, par exemple 1A2B-3C4D." v-slot="field">
          <input v-model.trim="form.code" v-bind="field.attrs" class="input input-code" maxlength="12" autocapitalize="characters" autocomplete="off" />
        </FormField>
      </div>
      <button class="btn btn-primary btn-block" type="submit" :disabled="request.busy.value || !form.number || !form.code || !app.canReachServer">
        {{ request.busy.value ? 'Vérification' : 'Vérifier' }}
      </button>
    </form>
    <InlineAlert :message="request.error.value" />
  </section>

  <section v-if="result && !result.valid" class="verdict verdict-bad" role="alert">
    <strong>Reçu non authentique</strong>
    <p>{{ result.message }}</p>
    <p class="text-small">Ne remettez rien. Gardez le document et prévenez un administrateur.</p>
  </section>

  <section v-else-if="result?.order" class="verdict verdict-good" role="status">
    <strong>Reçu authentique - {{ result.order.status_label }}</strong>
    <p class="text-small">Comparez chaque ligne avec le papier et la moto. Un seul écart signifie un reçu modifié.</p>
    <dl class="facts">
      <dt>Reçu</dt><dd>N° {{ result.order.receipt_number }} - commande {{ result.order.number }}</dd>
      <dt>Client</dt><dd>{{ result.order.customer_name }} - {{ result.order.customer_phone }}</dd>
      <dt>Moto</dt><dd>{{ result.order.model }} {{ result.order.model_year }} - {{ result.order.color }}</dd>
      <dt>Châssis</dt><dd class="code">{{ result.order.frame_number }}</dd>
      <dt>Moteur</dt><dd class="code">{{ result.order.engine_number }}</dd>
      <dt>Prix</dt><dd>{{ formatMoney(result.order.price, result.order.price_currency) }} - dépôt {{ result.order.deposit_reference }}</dd>
      <dt>Remise</dt><dd>{{ result.order.delivered_at ? `${formatDateTime(result.order.delivered_at)} à ${result.order.site ?? ''}` : 'Pas encore remise' }}</dd>
    </dl>
    <p v-if="result.order.status === 'delivered'" class="alert alert-warning">
      Cette moto a déjà été remise. Le reçu ne permet pas une seconde remise<template v-if="result.order.customs_document_pending"> ; seul le document de dédouanement reste à remettre</template>.
    </p>
    <RouterLink class="btn btn-secondary" :to="{ name: 'parts.moto.order', params: { orderId: result.order.id } }">Ouvrir la commande</RouterLink>
  </section>
</template>

<style scoped>
.scan-line {
  display: flex;
  gap: 12px;
  align-items: center;
}

.verdict {
  display: grid;
  gap: 8px;
  margin-top: 16px;
  padding: 16px;
  border-radius: var(--radius-control);
  border: 2px solid;
}

.verdict-good {
  border-color: var(--success);
  background: var(--success-soft);
}

.verdict-bad {
  border-color: var(--danger);
  background: var(--danger-soft);
}

.facts {
  display: grid;
  grid-template-columns: max-content minmax(0, 1fr);
  gap: 4px 12px;
  margin: 0;
}

.facts dt {
  color: var(--ink-2);
}

.facts dd {
  margin: 0;
  overflow-wrap: anywhere;
}

.code {
  font-family: var(--font-mono, ui-monospace, monospace);
}
</style>
