<script setup lang="ts">
import { computed, reactive, watch } from 'vue'
import { adjustStock, receiveStock, transferStock } from '../../api/parts'
import type { PartsProduct, PartsStockAction as StockAction } from '../../api/types'
import { useRequest } from '../../composables/useRequest'
import { useSessionStore } from '../../stores/session'
import { useAppStore } from '../../stores/app'
import { availableAt } from '../../lib/partsCart'
import SheetDialog from '../ui/SheetDialog.vue'
import FormField from '../ui/FormField.vue'
import InlineAlert from '../ui/InlineAlert.vue'

/*
 * Mouvement de stock d'un article : entrée (arrivage), transfert entre
 * deux emplacements ou ajustement d'inventaire. Une tâche par dialogue.
 */
const props = defineProps<{
  product: PartsProduct
  action: StockAction | null
}>()

const emit = defineEmits<{ close: []; done: [message: string] }>()

const session = useSessionStore()
const app = useAppStore()
const request = useRequest()

const sites = computed(() => session.sites.filter((site) => site.kind === 'store' || site.kind === 'warehouse'))
const form = reactive({ site_id: '', to_site_id: '', quantity: '', unit_cost_htg: '', reference: '', reason: '' })

const titles: Record<StockAction, string> = {
  receipt: 'Entrée de stock',
  transfer: 'Transférer',
  adjust: 'Ajuster l’inventaire',
}

const current = computed(() => (form.site_id ? availableAt(props.product, form.site_id) : null))

watch(() => props.action, (action) => {
  if (!action) return
  request.reset()
  const office = sites.value.find((site) => site.id === session.officeSiteId)?.id
  // Transfert : départ proposé = l'emplacement le mieux fourni, arrivée = le magasin actif.
  const fullest = [...(props.product.stock ?? [])].sort((left, right) => right.quantity - left.quantity)[0]?.site_id
  const from = action === 'transfer' ? (fullest ?? sites.value[0]?.id ?? '') : (office ?? sites.value[0]?.id ?? '')
  Object.assign(form, {
    site_id: from,
    to_site_id: sites.value.find((site) => site.id !== from && site.id === office)?.id ?? sites.value.find((site) => site.id !== from)?.id ?? '',
    quantity: '',
    unit_cost_htg: '',
    reference: '',
    reason: '',
  })
  if (action === 'adjust') form.quantity = String(availableAt(props.product, from) ?? 0)
}, { immediate: true })

watch(() => form.site_id, (siteId) => {
  if (props.action === 'adjust') form.quantity = String(availableAt(props.product, siteId) ?? 0)
})

async function submit(): Promise<void> {
  const quantity = Number(form.quantity)
  if (!Number.isInteger(quantity) || quantity < (props.action === 'adjust' ? 0 : 1)) {
    request.fail('Vérifiez les champs signalés.', { quantity: 'Saisissez un nombre entier de pièces.' })
    return
  }
  const product = props.product.id
  if (props.action === 'receipt') {
    const result = await request.run(() => receiveStock({
      site_id: form.site_id,
      reference: form.reference.trim(),
      lines: [{ product_id: product, quantity, unit_cost_htg: form.unit_cost_htg.trim() ? form.unit_cost_htg.trim().replace(',', '.') : null }],
    }))
    if (result) emit('done', `${quantity} reçue${quantity > 1 ? 's' : ''}.`)
  } else if (props.action === 'transfer') {
    const result = await request.run(() => transferStock({ product_id: product, from_site_id: form.site_id, to_site_id: form.to_site_id, quantity, note: form.reason.trim() || null }))
    if (result) emit('done', `${quantity} transférée${quantity > 1 ? 's' : ''}.`)
  } else if (props.action === 'adjust') {
    const result = await request.run(() => adjustStock({ product_id: product, site_id: form.site_id, counted_quantity: quantity, reason: form.reason.trim() }))
    if (result) emit('done', 'Inventaire ajusté.')
  }
}
</script>

<template>
  <SheetDialog :open="action !== null" :title="action ? titles[action] : ''" :description="`${product.name} - ${product.sku}`" :locked="request.busy.value" @close="emit('close')">
    <form id="stock-action-form" class="form" novalidate @submit.prevent="submit">
      <FormField :label="action === 'transfer' ? 'Depuis' : 'Emplacement'" :error="request.fieldErrors.value.site_id || request.fieldErrors.value.from_site_id" v-slot="field">
        <select v-model="form.site_id" v-bind="field.attrs" class="select">
          <option v-for="site in sites" :key="site.id" :value="site.id">
            {{ site.name }}{{ availableAt(product, site.id) === null ? '' : ` (${availableAt(product, site.id)} en stock)` }}
          </option>
        </select>
      </FormField>
      <FormField v-if="action === 'transfer'" label="Vers" :error="request.fieldErrors.value.to_site_id" v-slot="field">
        <select v-model="form.to_site_id" v-bind="field.attrs" class="select">
          <option v-for="site in sites.filter((item) => item.id !== form.site_id)" :key="site.id" :value="site.id">{{ site.name }}</option>
        </select>
      </FormField>
      <FormField
        :label="action === 'adjust' ? 'Quantité comptée' : action === 'transfer' ? 'Quantité à transférer' : 'Quantité reçue'"
        :help="action === 'adjust' && current !== null ? `Stock enregistré : ${current}.` : undefined"
        required
        :error="request.fieldErrors.value.quantity || request.fieldErrors.value.counted_quantity || request.fieldErrors.value['lines.0.quantity']"
        v-slot="field"
      >
        <input v-model.trim="form.quantity" v-bind="field.attrs" class="input" type="number" inputmode="numeric" min="0" />
      </FormField>
      <template v-if="action === 'receipt'">
        <FormField label="Référence du bon ou de la facture" required :error="request.fieldErrors.value.reference" v-slot="field">
          <input v-model.trim="form.reference" v-bind="field.attrs" class="input" maxlength="120" autocomplete="off" />
        </FormField>
        <FormField label="Coût unitaire (HTG)" help="Facultatif, pour le coût de revient." :error="request.fieldErrors.value['lines.0.unit_cost_htg']" v-slot="field">
          <input v-model.trim="form.unit_cost_htg" v-bind="field.attrs" class="input" inputmode="decimal" />
        </FormField>
      </template>
      <FormField v-else :label="action === 'adjust' ? 'Motif' : 'Note'" :required="action === 'adjust'" :help="action === 'adjust' ? 'Casse, perte, erreur de saisie, inventaire.' : undefined" :error="request.fieldErrors.value.reason || request.fieldErrors.value.note" v-slot="field">
        <input v-model.trim="form.reason" v-bind="field.attrs" class="input" maxlength="500" autocomplete="off" />
      </FormField>
      <InlineAlert :message="request.error.value" />
    </form>
    <template #footer>
      <button class="btn btn-secondary" type="button" :disabled="request.busy.value" @click="emit('close')">Annuler</button>
      <button class="btn btn-primary" type="submit" form="stock-action-form" :disabled="request.busy.value || !app.canReachServer">
        {{ request.busy.value ? 'Enregistrement' : 'Enregistrer' }}
      </button>
    </template>
  </SheetDialog>
</template>
