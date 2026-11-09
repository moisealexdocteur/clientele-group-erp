<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { fetchPartsCustomer, updatePartsCustomer } from '../../../api/parts'
import type { PartsCustomerDetail } from '../../../api/types'
import { useRequest } from '../../../composables/useRequest'
import { useSessionStore } from '../../../stores/session'
import { useAppStore } from '../../../stores/app'
import { useUiStore } from '../../../stores/ui'
import { formatMoney } from '../../../lib/money'
import { formatDate, formatDateTime } from '../../../lib/time'
import PageHeader from '../../../components/ui/PageHeader.vue'
import InlineAlert from '../../../components/ui/InlineAlert.vue'
import SheetDialog from '../../../components/ui/SheetDialog.vue'
import FormField from '../../../components/ui/FormField.vue'
import StatusPill from '../../../components/ui/StatusPill.vue'

/* Fiche client : coordonnées, motos commandées et achats au comptoir. */
const props = defineProps<{ customerId: string }>()

const session = useSessionStore()
const app = useAppStore()
const ui = useUiStore()
const loading = useRequest()
const request = useRequest()
const customer = ref<PartsCustomerDetail | null>(null)
const editing = ref(false)
const form = reactive({ name: '', phone: '', email: '', address: '' })
const canManage = computed(() => session.can('parts.customers.manage'))

async function load(): Promise<void> {
  const result = await loading.run(() => fetchPartsCustomer(props.customerId))
  if (result) customer.value = result.data
}

function edit(): void {
  if (!customer.value) return
  Object.assign(form, { name: customer.value.name, phone: customer.value.phone ?? '', email: customer.value.email ?? '', address: customer.value.address ?? '' })
  request.reset()
  editing.value = true
}

async function save(): Promise<void> {
  const result = await request.run(() => updatePartsCustomer(props.customerId, { name: form.name.trim(), phone: form.phone.trim(), email: form.email.trim() || null, address: form.address.trim() || null }))
  if (!result) return
  editing.value = false
  ui.toast('Fiche client enregistrée.')
  await load()
}

onMounted(load)
</script>

<template>
  <PageHeader :title="customer?.name ?? 'Client'" :back="{ name: 'parts.customers' }" back-label="Clients">
    <template #actions>
      <button v-if="canManage && customer" class="btn btn-secondary" type="button" :disabled="!app.canReachServer" @click="edit">Modifier</button>
    </template>
  </PageHeader>

  <InlineAlert :message="loading.error.value" />
  <div v-if="loading.busy.value && !customer" class="skeleton" style="height: 240px"></div>

  <template v-else-if="customer">
    <section class="panel">
      <dl class="facts">
        <dt>Téléphone</dt><dd><a v-if="customer.phone" :href="`tel:${customer.phone.replace(/\s/g, '')}`">{{ customer.phone }}</a></dd>
        <dt>Courriel</dt><dd>{{ customer.email ?? '-' }}</dd>
        <dt>Adresse</dt><dd>{{ customer.address ?? '-' }}</dd>
        <dt>Client depuis</dt><dd>{{ customer.created_at ? formatDate(customer.created_at) : '-' }}</dd>
      </dl>
    </section>

    <h2 class="title-section">Motos</h2>
    <div v-if="customer.moto_orders.length" class="list-card">
      <RouterLink v-for="order in customer.moto_orders" :key="order.id" class="list-row" :to="{ name: 'parts.moto.order', params: { orderId: order.id } }">
        <span class="row-main">
          <strong>{{ order.model }}<template v-if="order.color"> - {{ order.color }}</template></strong>
          <span class="text-small text-secondary">Commande {{ order.number }}<template v-if="order.receipt_number"> - reçu {{ order.receipt_number }}</template></span>
        </span>
        <span class="row-side">
          <StatusPill :tone="order.status === 'delivered' ? 'success' : order.status === 'cancelled' ? 'neutral' : 'warning'" :label="order.status_label" />
          <span class="text-small">{{ formatMoney(order.price, order.price_currency) }}</span>
        </span>
      </RouterLink>
    </div>
    <p v-else class="text-muted text-small">Aucune moto commandée.</p>

    <h2 class="title-section">Achats au comptoir</h2>
    <div v-if="customer.sales.length" class="list-card">
      <RouterLink v-for="sale in customer.sales" :key="sale.id" class="list-row" :to="{ name: 'parts.receipt', params: { saleId: sale.id } }">
        <span class="row-main">
          <strong>Reçu {{ sale.number }}</strong>
          <span class="text-small text-secondary">{{ sale.sold_at ? formatDateTime(sale.sold_at) : '' }} - {{ sale.item_count }} article{{ sale.item_count > 1 ? 's' : '' }}{{ sale.price_list === 'wholesale' ? ' - gros' : '' }}</span>
        </span>
        <strong class="row-side">{{ formatMoney(sale.total, sale.currency) }}</strong>
      </RouterLink>
    </div>
    <p v-else class="text-muted text-small">Aucun achat au comptoir.</p>
  </template>

  <SheetDialog :open="editing" title="Modifier le client" :locked="request.busy.value" @close="editing = false">
    <form id="customer-form" class="form" novalidate @submit.prevent="save">
      <FormField label="Nom complet" required :error="request.fieldErrors.value.name" v-slot="field">
        <input v-model="form.name" v-bind="field.attrs" class="input" maxlength="160" autocomplete="off" />
      </FormField>
      <FormField label="Téléphone" required help="Le téléphone identifie le client : un seul client par numéro." :error="request.fieldErrors.value.phone" v-slot="field">
        <input v-model="form.phone" v-bind="field.attrs" class="input" type="tel" inputmode="tel" maxlength="40" autocomplete="off" />
      </FormField>
      <FormField label="Courriel" :error="request.fieldErrors.value.email" v-slot="field">
        <input v-model="form.email" v-bind="field.attrs" class="input" type="email" inputmode="email" maxlength="254" autocomplete="off" />
      </FormField>
      <FormField label="Adresse" :error="request.fieldErrors.value.address" v-slot="field">
        <input v-model="form.address" v-bind="field.attrs" class="input" maxlength="255" autocomplete="off" />
      </FormField>
      <InlineAlert :message="request.error.value" />
    </form>
    <template #footer>
      <button class="btn btn-secondary" type="button" :disabled="request.busy.value" @click="editing = false">Annuler</button>
      <button class="btn btn-primary" type="submit" form="customer-form" :disabled="request.busy.value">{{ request.busy.value ? 'Enregistrement' : 'Enregistrer' }}</button>
    </template>
  </SheetDialog>
</template>

<style scoped>
.facts {
  display: grid;
  grid-template-columns: max-content minmax(0, 1fr);
  gap: 6px 16px;
  margin: 0;
}

.facts dt {
  color: var(--ink-2);
}

.facts dd {
  margin: 0;
  overflow-wrap: anywhere;
}

.title-section {
  margin: 20px 0 8px;
}

.row-main {
  display: grid;
  gap: 2px;
  min-width: 0;
}

.row-side {
  display: grid;
  justify-items: end;
  gap: 4px;
}
</style>
