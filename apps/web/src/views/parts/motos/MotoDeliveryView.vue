<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { deliverMotoOrder, fetchMotoOrder } from '../../../api/motos'
import type { MotoOrderDetail } from '../../../api/types'
import { useRequest } from '../../../composables/useRequest'
import { useAppStore } from '../../../stores/app'
import { useUiStore } from '../../../stores/ui'
import { formatMoney } from '../../../lib/money'
import PageHeader from '../../../components/ui/PageHeader.vue'
import InlineAlert from '../../../components/ui/InlineAlert.vue'
import FormField from '../../../components/ui/FormField.vue'

/*
 * Remise de la moto au client, dans l'ordre du comptoir : fiche de dépôt,
 * pièce d'identité, contrôle de conformité avec le client, accessoires,
 * document de dédouanement, puis vente finale sans garantie.
 */
const props = defineProps<{ orderId: string }>()

const ID_DOCUMENTS: Record<string, string> = {
  cin: 'Carte d’identification nationale (CIN)',
  passport: 'Passeport',
  driver_license: 'Permis de conduire',
  nif_card: 'Carte NIF avec photo',
}

const router = useRouter()
const app = useAppStore()
const ui = useUiStore()
const loading = useRequest()
const request = useRequest()

const order = ref<MotoOrderDetail | null>(null)
const form = reactive({
  deposit_slip_presented: false,
  id_document_type: 'cin',
  id_document_number: '',
  customer_address: '',
  checklist: {} as Record<string, boolean>,
  accessories: {} as Record<string, boolean>,
  customs_document_delivered: false,
  final_sale_acknowledged: false,
})

const unchecked = computed(() => order.value?.checklist.filter((item) => !form.checklist[item.key]).length ?? 0)
const ready = computed(() => form.deposit_slip_presented && form.id_document_number.trim() !== '' && unchecked.value === 0 && form.final_sale_acknowledged)

onMounted(async () => {
  const result = await loading.run(() => fetchMotoOrder(props.orderId))
  if (!result) return
  order.value = result.data
  form.customer_address = result.data.customer_address ?? ''
  // Accessoires cochés par défaut ; le contrôle se coche point par point avec le client.
  for (const item of result.data.checklist) form.checklist[item.key] = false
  for (const item of result.data.accessories) form.accessories[item.key] = true
  form.customs_document_delivered = result.data.unit?.customs_document_available ?? false
})

async function submit(): Promise<void> {
  const result = await request.run(() => deliverMotoOrder(props.orderId, {
    ...form,
    id_document_number: form.id_document_number.trim(),
    customer_address: form.customer_address.trim() || null,
  }))
  if (!result) return
  ui.toast(`Moto remise. Reçu n° ${result.data.receipt_number}.`)
  await router.replace({ name: 'parts.moto.order', params: { orderId: props.orderId } })
}
</script>

<template>
  <PageHeader
    title="Remettre la moto"
    :description="order ? `${order.customer_name} - ${order.unit?.model?.label ?? ''} ${order.unit?.color ?? ''}` : undefined"
    :back="{ name: 'parts.moto.order', params: { orderId } }"
    back-label="Commande"
  />

  <InlineAlert :message="loading.error.value" />
  <div v-if="loading.busy.value && !order" class="skeleton" style="height: 320px"></div>
  <p v-else-if="order && order.status !== 'assigned'" class="alert alert-warning">Cette commande n’a pas de moto à remettre ({{ order.status_label }}).</p>

  <form v-else-if="order" class="form delivery" novalidate @submit.prevent="submit">
    <section class="panel">
      <h2 class="title-section">1. Paiement</h2>
      <p class="text-secondary">{{ formatMoney(order.payment_amount, order.payment_currency) }} - {{ order.bank_name }} fiche n° {{ order.deposit_reference }}</p>
      <label class="check big">
        <input v-model="form.deposit_slip_presented" type="checkbox" />
        <span>Le client présente la fiche de dépôt originale, au même numéro.</span>
      </label>
      <span v-if="request.fieldErrors.value.deposit_slip_presented" class="field-error">{{ request.fieldErrors.value.deposit_slip_presented }}</span>
    </section>

    <section class="panel">
      <h2 class="title-section">2. Pièce d’identité avec photo</h2>
      <div class="grid-2">
        <FormField label="Pièce présentée" required :error="request.fieldErrors.value.id_document_type" v-slot="field">
          <select v-model="form.id_document_type" v-bind="field.attrs" class="select">
            <option v-for="(label, key) in ID_DOCUMENTS" :key="key" :value="key">{{ label }}</option>
          </select>
        </FormField>
        <FormField label="Numéro" required :error="request.fieldErrors.value.id_document_number" v-slot="field">
          <input v-model.trim="form.id_document_number" v-bind="field.attrs" class="input" maxlength="64" autocomplete="off" />
        </FormField>
      </div>
      <p class="field-help">Vérifiez que la photo correspond au client et que le nom est {{ order.customer_name }}.</p>
      <FormField label="Adresse du client" help="Imprimée sur le reçu et le certificat." :error="request.fieldErrors.value.customer_address" v-slot="field">
        <input v-model="form.customer_address" v-bind="field.attrs" class="input" maxlength="255" autocomplete="off" />
      </FormField>
    </section>

    <section class="panel">
      <div class="panel-header">
        <h2 class="title-section">3. Contrôle avec le client</h2>
        <span class="text-small" :class="unchecked ? 'text-warning' : 'text-secondary'">{{ unchecked ? `${unchecked} à vérifier` : 'Tout est conforme' }}</span>
      </div>
      <p class="text-small text-muted">Châssis {{ order.unit?.frame_number }} - moteur {{ order.unit?.engine_number }}</p>
      <div class="checks">
        <label v-for="item in order.checklist" :key="item.key" class="check big">
          <input v-model="form.checklist[item.key]" type="checkbox" />
          <span>{{ item.label }}</span>
        </label>
      </div>
      <span v-if="request.fieldErrors.value.checklist" class="field-error" role="alert">{{ request.fieldErrors.value.checklist }}</span>
    </section>

    <section class="panel">
      <h2 class="title-section">4. Accessoires et documents</h2>
      <div class="checks">
        <label v-for="item in order.accessories" :key="item.key" class="check big">
          <input v-model="form.accessories[item.key]" type="checkbox" />
          <span>{{ item.label }} ({{ item.quantity }})</span>
        </label>
        <label class="check big">
          <input type="checkbox" checked disabled />
          <span>Certificat de vente du fournisseur</span>
        </label>
        <label v-if="order.unit?.customs_document_available" class="check big">
          <input v-model="form.customs_document_delivered" type="checkbox" />
          <span>Document de dédouanement</span>
        </label>
      </div>
      <p v-if="!order.unit?.customs_document_available" class="alert alert-warning">
        Le document de dédouanement de cette moto n’est pas encore disponible. Il est noté « à remettre » : le client reviendra le chercher.
      </p>
      <span v-if="request.fieldErrors.value.customs_document_delivered" class="field-error">{{ request.fieldErrors.value.customs_document_delivered }}</span>
    </section>

    <section class="panel">
      <h2 class="title-section">5. Vente finale</h2>
      <label class="check big">
        <input v-model="form.final_sale_acknowledged" type="checkbox" />
        <span>Le client a vérifié la moto et accepte la vente finale, sans garantie.</span>
      </label>
      <span v-if="request.fieldErrors.value.final_sale_acknowledged" class="field-error">{{ request.fieldErrors.value.final_sale_acknowledged }}</span>
      <InlineAlert :message="request.error.value" />
      <button class="btn btn-primary btn-block" type="submit" :disabled="!ready || request.busy.value || !app.canReachServer">
        {{ request.busy.value ? 'Enregistrement' : 'Remettre la moto et émettre le reçu' }}
      </button>
    </section>
  </form>
</template>

<style scoped>
.delivery {
  display: grid;
  gap: 16px;
}

.checks {
  display: grid;
  gap: 4px;
}

@media (min-width: 720px) {
  .checks {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

.check.big {
  min-height: 48px;
  align-items: center;
}

.text-warning {
  color: var(--warning);
}
</style>
