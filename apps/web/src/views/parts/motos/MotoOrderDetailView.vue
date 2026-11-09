<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import {
  assignMotoUnit,
  cancelMotoOrder,
  deliverCustomsDocument,
  fetchMotoOrder,
  fetchMotoUnits,
  markMotoOrderCalled,
  recordMotoDocumentPrint,
  verifyMotoPayment,
} from '../../../api/motos'
import type { MotoOrderDetail, MotoUnit } from '../../../api/types'
import { useRequest } from '../../../composables/useRequest'
import { useSessionStore } from '../../../stores/session'
import { useAppStore } from '../../../stores/app'
import { useUiStore } from '../../../stores/ui'
import { formatMoney, formatRate } from '../../../lib/money'
import { formatDate, formatDateTime } from '../../../lib/time'
import { buildMotoCertificatePdf, buildMotoReceiptPdf } from '../../../lib/motoPdf'
import { downloadBytes } from '../../../lib/xlsx'
import PageHeader from '../../../components/ui/PageHeader.vue'
import InlineAlert from '../../../components/ui/InlineAlert.vue'
import StatusPill from '../../../components/ui/StatusPill.vue'
import SheetDialog from '../../../components/ui/SheetDialog.vue'
import FormField from '../../../components/ui/FormField.vue'
import PrivateImage from '../../../components/ui/PrivateImage.vue'

/*
 * Commande de moto : paiement, moto attribuée, remise et documents.
 * Chaque action est une tâche séparée, montrée seulement si le rôle la permet.
 */
const props = defineProps<{ orderId: string }>()

const session = useSessionStore()
const app = useAppStore()
const ui = useUiStore()
const loading = useRequest()
const action = useRequest()

const order = ref<MotoOrderDetail | null>(null)
const task = ref<null | 'verify' | 'cancel' | 'assign'>(null)
const reason = ref('')
const units = ref<MotoUnit[]>([])
const unitId = ref('')

const can = (permission: string) => session.can(permission)
const delivered = computed(() => order.value?.status === 'delivered')
const converted = computed(() => order.value && order.value.payment_currency !== order.value.price_currency && order.value.exchange_rate_htg_per_usd)

async function load(): Promise<void> {
  const result = await loading.run(() => fetchMotoOrder(props.orderId))
  if (result) order.value = result.data
}

onMounted(load)

function open(next: 'verify' | 'cancel' | 'assign'): void {
  action.reset()
  reason.value = ''
  task.value = next
  if (next === 'assign') void loadUnits()
}

async function loadUnits(): Promise<void> {
  const model = order.value?.model?.id
  const [stock, reserved] = await Promise.all([
    fetchMotoUnits({ status: 'in_stock', model_id: model }),
    fetchMotoUnits({ status: 'reserved', model_id: model }),
  ])
  units.value = [...stock.data, ...reserved.data.filter((unit) => unit.order_id !== props.orderId)]
  unitId.value = units.value[0]?.id ?? ''
}

async function run(call: () => Promise<{ data: MotoOrderDetail }>, message: string): Promise<void> {
  const result = await action.run(call)
  if (!result) return
  order.value = result.data
  task.value = null
  ui.toast(message)
}

async function called(): Promise<void> {
  await run(() => markMotoOrderCalled(props.orderId), 'Appel enregistré.')
  if (action.error.value) ui.toast(action.error.value, 'danger')
}

async function customs(): Promise<void> {
  const confirmed = await ui.confirm({
    title: 'Remettre le document de dédouanement',
    message: `Le document de dédouanement de la moto ${order.value?.unit?.frame_number ?? ''} est remis au client. L’action est journalisée.`,
    confirmLabel: 'Confirmer la remise',
  })
  if (confirmed) await run(() => deliverCustomsDocument(props.orderId), 'Document de dédouanement remis.')
}

async function print(document: 'receipt' | 'certificate', trial = false): Promise<void> {
  const current = order.value
  if (!current) return
  try {
    const offset = session.context?.company.moto_certificate_offset
    if (document === 'receipt') {
      const result = await recordMotoDocumentPrint(props.orderId, 'receipt')
      order.value = result.data
      downloadBytes(await buildMotoReceiptPdf(result.data), `Recu-vente-moto-${result.data.receipt_number?.replace(' ', '')}.pdf`, 'application/pdf')
    } else if (trial) {
      downloadBytes(await buildMotoCertificatePdf(current, offset, true), 'Essai-alignement-certificat.pdf', 'application/pdf')
    } else {
      const result = await recordMotoDocumentPrint(props.orderId, 'certificate')
      order.value = result.data
      downloadBytes(await buildMotoCertificatePdf(result.data, offset), `Certificat-${result.data.unit?.frame_number ?? 'moto'}.pdf`, 'application/pdf')
    }
  } catch (error) {
    ui.toast(error instanceof Error ? error.message : 'Le document n’a pas pu être créé.', 'danger')
  }
}
</script>

<template>
  <PageHeader
    :title="order ? order.customer_name : 'Commande de moto'"
    :description="order ? `Commande ${order.number} - ${order.model?.label ?? ''}` : undefined"
    :back="{ name: 'parts.motos' }"
    back-label="Motos"
  >
    <template v-if="order" #before-title>
      <StatusPill :tone="order.status === 'submitted' ? 'warning' : order.status === 'assigned' ? 'success' : order.status === 'paid' ? 'info' : 'neutral'" :label="order.status_label" />
    </template>
  </PageHeader>

  <InlineAlert :message="loading.error.value" />
  <div v-if="loading.busy.value && !order" class="skeleton" style="height: 320px"></div>

  <div v-else-if="order" class="detail-grid">
    <div class="stack-lg">
      <section v-if="order.status !== 'cancelled'" class="panel next" aria-labelledby="next-title">
        <h2 id="next-title" class="title-section">Prochaine étape</h2>
        <template v-if="order.status === 'submitted'">
          <p class="text-secondary">Un administrateur vérifie le dépôt sur le relevé de la banque. La commande entre ensuite dans la liste d’attente à son rang de paiement.</p>
          <div class="btn-row">
            <button v-if="can('parts.motos.payments.verify')" class="btn btn-primary" type="button" :disabled="!app.canReachServer" @click="open('verify')">Vérifier le dépôt</button>
          </div>
        </template>
        <template v-else-if="order.status === 'paid'">
          <p class="text-secondary">
            Rang {{ order.queue_position ?? '-' }} dans la liste d’attente du modèle{{ order.preferred_color ? `, couleur ${order.preferred_color}` : '' }}.
            La moto est attribuée à l’arrivée d’un lot, dans l’ordre de paiement.
          </p>
          <div class="btn-row">
            <button v-if="can('parts.motos.assign')" class="btn btn-secondary" type="button" :disabled="!app.canReachServer" @click="open('assign')">Attribuer une moto</button>
          </div>
        </template>
        <template v-else-if="order.status === 'assigned'">
          <p class="text-secondary">
            Moto prête à {{ order.unit?.site.name }}.
            <template v-if="order.unit && order.unit.site.id !== order.site.id"> Retrait prévu à {{ order.site.name }} : transférez la moto ou prévenez le client.</template>
            {{ order.called_at ? `Client appelé le ${formatDateTime(order.called_at)}.` : 'Appelez le client pour qu’il vienne la chercher.' }}
          </p>
          <div class="btn-row">
            <a class="btn btn-secondary" :href="`tel:${order.customer_phone.replace(/[^+0-9]/g, '')}`">Appeler {{ order.customer_phone }}</a>
            <button v-if="can('parts.motos.orders.create')" class="btn btn-ghost" type="button" :disabled="action.busy.value || !app.canReachServer" @click="called">Client appelé</button>
            <RouterLink v-if="can('parts.motos.deliver')" class="btn btn-primary" :to="{ name: 'parts.moto.delivery', params: { orderId } }">Remettre la moto</RouterLink>
          </div>
        </template>
        <template v-else-if="delivered">
          <p class="text-secondary">Livrée le {{ order.delivered_at ? formatDateTime(order.delivered_at) : '' }}. Reçu n° {{ order.receipt_number }}. Vente finale, sans garantie.</p>
          <p v-if="order.customs_document_pending" class="alert alert-warning">Document de dédouanement à remettre : le client revient le récupérer.</p>
          <div v-if="can('parts.motos.deliver')" class="btn-row">
            <button class="btn btn-primary" type="button" @click="print('receipt')">Reçu de vente (PDF)</button>
            <button class="btn btn-secondary" type="button" @click="print('certificate')">Certificat du fournisseur (PDF)</button>
            <button v-if="order.customs_document_pending" class="btn btn-secondary" type="button" :disabled="action.busy.value || !app.canReachServer" @click="customs">Remettre le dédouanement</button>
          </div>
          <p v-if="can('parts.motos.deliver')" class="text-small text-muted">
            Reçu imprimé {{ order.receipt_print_count }} fois, certificat {{ order.certificate_print_count }} fois. Papier sécurisé Lettre pour le reçu ; formulaire du fournisseur pour le certificat.
            <button class="link-button" type="button" @click="print('certificate', true)">Essai d’alignement du certificat</button>
          </p>
        </template>
        <div v-if="['submitted', 'paid', 'assigned'].includes(order.status) && can('parts.motos.payments.verify')" class="btn-row">
          <button v-if="order.status === 'assigned' && can('parts.motos.assign')" class="btn btn-ghost" type="button" @click="open('assign')">Changer la moto</button>
          <button class="btn btn-ghost danger" type="button" @click="open('cancel')">Annuler la commande</button>
        </div>
      </section>
      <p v-else class="alert alert-warning">Commande annulée le {{ order.cancelled_at ? formatDateTime(order.cancelled_at) : '' }} : {{ order.cancellation_reason }}</p>

      <section v-if="order.unit" class="panel" aria-labelledby="unit-title">
        <h2 id="unit-title" class="title-section">Moto</h2>
        <dl class="facts">
          <div><dt>Marque et modèle</dt><dd>{{ order.unit.model?.label }} {{ order.unit.model_year }}</dd></div>
          <div><dt>Couleur</dt><dd>{{ order.unit.color }}</dd></div>
          <div><dt>Numéro de châssis</dt><dd>{{ order.unit.frame_number }}</dd></div>
          <div><dt>Numéro de moteur</dt><dd>{{ order.unit.engine_number }}</dd></div>
          <div><dt>Cylindrée</dt><dd>{{ order.unit.displacement_cc ? `${order.unit.displacement_cc} cm³` : 'Non renseignée' }} - {{ order.unit.cylinders }} cyl.</dd></div>
          <div><dt>Emplacement</dt><dd>{{ order.unit.site.name }}</dd></div>
          <div><dt>Dédouanement</dt><dd>{{ order.unit.customs_document_available ? 'Document disponible' : 'Document pas encore reçu' }}</dd></div>
        </dl>
        <p v-if="order.assignment_note" class="text-small text-muted">{{ order.assignment_note }}</p>
      </section>

      <section v-if="delivered" class="panel" aria-labelledby="delivery-title">
        <h2 id="delivery-title" class="title-section">Remise</h2>
        <dl class="facts">
          <div v-if="order.id_document_number"><dt>Pièce d’identité</dt><dd>{{ order.id_document_label }} n° {{ order.id_document_number }}</dd></div>
          <div v-for="item in order.accessories" :key="item.key"><dt>{{ item.label }} ({{ item.quantity }})</dt><dd>{{ item.delivered ? 'Remis' : 'Non remis' }}</dd></div>
          <div><dt>Dédouanement</dt><dd>{{ order.customs_document_delivered ? `Remis${order.customs_document_delivered_at ? ` le ${formatDate(order.customs_document_delivered_at)}` : ''}` : 'À remettre' }}</dd></div>
        </dl>
      </section>
    </div>

    <div class="stack-lg">
      <section class="panel" aria-labelledby="customer-title">
        <h2 id="customer-title" class="title-section">Client</h2>
        <dl class="facts">
          <div><dt>Téléphone</dt><dd><a :href="`tel:${order.customer_phone.replace(/[^+0-9]/g, '')}`">{{ order.customer_phone }}</a></dd></div>
          <div v-if="order.customer_email"><dt>Courriel</dt><dd>{{ order.customer_email }}</dd></div>
          <div v-if="order.customer_address"><dt>Adresse</dt><dd>{{ order.customer_address }}</dd></div>
          <div><dt>Retrait</dt><dd>{{ order.site.name }}</dd></div>
          <div v-if="order.preferred_color"><dt>Couleur souhaitée</dt><dd>{{ order.preferred_color }}</dd></div>
        </dl>
      </section>

      <section class="panel" aria-labelledby="payment-title">
        <h2 id="payment-title" class="title-section">Paiement</h2>
        <dl class="facts">
          <div><dt>Prix</dt><dd>{{ formatMoney(order.price, order.price_currency) }}</dd></div>
          <div><dt>Dépôt</dt><dd>{{ formatMoney(order.payment_amount, order.payment_currency) }}</dd></div>
          <div v-if="converted"><dt>Taux</dt><dd>{{ formatRate(order.exchange_rate_htg_per_usd) }} - {{ formatMoney(order.amount_in_price_currency, order.price_currency) }}</dd></div>
          <div><dt>Fiche</dt><dd>{{ order.bank_name }} n° {{ order.deposit_reference }}</dd></div>
          <div><dt>Date du dépôt</dt><dd>{{ order.deposit_date ? formatDate(`${order.deposit_date}T12:00:00`) : '' }}</dd></div>
          <div><dt>Vérifié</dt><dd>{{ order.verified_at ? formatDateTime(order.verified_at) : 'Pas encore' }}</dd></div>
        </dl>
        <PrivateImage v-if="order.deposit_proof_url" class="proof" :path="order.deposit_proof_url" alt="Fiche de dépôt">
          <template #fallback><p class="text-small text-muted">Fiche de dépôt jointe (PDF ou image non affichable).</p></template>
        </PrivateImage>
      </section>
    </div>
  </div>

  <SheetDialog :open="task === 'verify'" title="Vérifier le dépôt" description="Confirmez seulement si le dépôt figure sur le relevé de la banque, au bon montant." :locked="action.busy.value" @close="task = null">
    <div v-if="order" class="form">
      <dl class="facts">
        <div><dt>Fiche</dt><dd>{{ order.bank_name }} n° {{ order.deposit_reference }}</dd></div>
        <div><dt>Montant</dt><dd>{{ formatMoney(order.payment_amount, order.payment_currency) }}</dd></div>
        <div><dt>Date</dt><dd>{{ order.deposit_date ? formatDate(`${order.deposit_date}T12:00:00`) : '' }}</dd></div>
      </dl>
      <InlineAlert :message="action.error.value" />
    </div>
    <template #footer>
      <button class="btn btn-secondary" type="button" :disabled="action.busy.value" @click="task = null">Annuler</button>
      <button class="btn btn-primary" type="button" :disabled="action.busy.value || !app.canReachServer" @click="run(() => verifyMotoPayment(orderId), 'Dépôt vérifié. La commande est dans la liste d’attente.')">Le dépôt est sur le relevé</button>
    </template>
  </SheetDialog>

  <SheetDialog :open="task === 'cancel'" title="Annuler la commande" description="La moto attribuée revient en stock. Un remboursement se fait hors de l’application." :locked="action.busy.value" @close="task = null">
    <form id="cancel-form" class="form" novalidate @submit.prevent="run(() => cancelMotoOrder(orderId, reason.trim()), 'Commande annulée.')">
      <FormField label="Motif" required :error="action.fieldErrors.value.reason" v-slot="field">
        <input v-model="reason" v-bind="field.attrs" class="input" maxlength="500" placeholder="Dépôt introuvable, remboursement demandé" />
      </FormField>
      <InlineAlert :message="action.error.value" />
    </form>
    <template #footer>
      <button class="btn btn-secondary" type="button" :disabled="action.busy.value" @click="task = null">Retour</button>
      <button class="btn btn-primary" type="submit" form="cancel-form" :disabled="action.busy.value || !reason.trim() || !app.canReachServer">Annuler la commande</button>
    </template>
  </SheetDialog>

  <SheetDialog :open="task === 'assign'" title="Changer l’attribution" description="Hors de l’ordre de paiement, le motif est journalisé. Une moto prise à une autre commande remet celle-ci en attente." :locked="action.busy.value" @close="task = null">
    <form id="assign-form" class="form" novalidate @submit.prevent="run(() => assignMotoUnit(orderId, unitId || null, reason.trim()), unitId ? 'Moto attribuée.' : 'Commande remise en attente.')">
      <FormField label="Moto" :error="action.fieldErrors.value.unit_id" v-slot="field">
        <select v-model="unitId" v-bind="field.attrs" class="select">
          <option v-for="unit in units" :key="unit.id" :value="unit.id">
            {{ unit.color }} - {{ unit.frame_number }} - {{ unit.site.name }}{{ unit.status === 'reserved' ? ' (attribuée à une autre commande)' : '' }}
          </option>
          <option value="">Aucune : remettre la commande en attente</option>
        </select>
      </FormField>
      <FormField label="Motif" required :error="action.fieldErrors.value.reason" v-slot="field">
        <input v-model="reason" v-bind="field.attrs" class="input" maxlength="500" />
      </FormField>
      <InlineAlert :message="action.error.value" />
    </form>
    <template #footer>
      <button class="btn btn-secondary" type="button" :disabled="action.busy.value" @click="task = null">Retour</button>
      <button class="btn btn-primary" type="submit" form="assign-form" :disabled="action.busy.value || !reason.trim() || !app.canReachServer">Enregistrer</button>
    </template>
  </SheetDialog>
</template>

<style scoped>
.detail-grid {
  display: grid;
  gap: 24px;
  align-items: start;
}

@media (min-width: 1100px) {
  .detail-grid {
    grid-template-columns: minmax(0, 1fr) 360px;
  }
}

.next {
  border-left: 4px solid var(--accent);
}

.danger {
  color: var(--danger, #c50f1f);
}

.link-button {
  min-height: 0;
  padding: 0;
  border: 0;
  background: none;
  color: var(--accent);
  text-decoration: underline;
  cursor: pointer;
}

.proof {
  width: 100%;
  max-height: 320px;
  object-fit: contain;
  border-radius: var(--radius-control);
}
</style>
