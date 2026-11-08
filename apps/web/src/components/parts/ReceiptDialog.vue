<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { fetchProducts, receiveStock } from '../../api/parts'
import type { PartsProduct } from '../../api/types'
import { useRequest } from '../../composables/useRequest'
import { useSessionStore } from '../../stores/session'
import { useAppStore } from '../../stores/app'
import SheetDialog from '../ui/SheetDialog.vue'
import FormField from '../ui/FormField.vue'
import InlineAlert from '../ui/InlineAlert.vue'

/* Entrée de stock de plusieurs articles : arrivage ou réception fournisseur. */
const props = defineProps<{ open: boolean }>()
const emit = defineEmits<{ close: []; done: [message: string] }>()

const session = useSessionStore()
const app = useAppStore()
const request = useRequest()
const search = useRequest()

const sites = computed(() => session.sites.filter((site) => site.kind === 'store' || site.kind === 'warehouse'))
const form = reactive({ site_id: '', reference: '', note: '' })
const lines = ref<Array<{ product: PartsProduct; quantity: string; unit_cost_htg: string }>>([])
const query = ref('')
const results = ref<PartsProduct[]>([])

watch(() => props.open, (open) => {
  if (!open) return
  request.reset()
  Object.assign(form, { site_id: sites.value.find((site) => site.id === session.officeSiteId)?.id ?? sites.value[0]?.id ?? '', reference: '', note: '' })
  lines.value = []
  query.value = ''
  results.value = []
}, { immediate: true })

let timer: number | undefined
watch(query, (value) => {
  window.clearTimeout(timer)
  if (value.trim().length < 2) {
    results.value = []
    return
  }
  timer = window.setTimeout(async () => {
    const result = await search.run(() => fetchProducts({ q: value.trim() }))
    results.value = (result?.data ?? []).slice(0, 8)
  }, 250)
})

function add(product: PartsProduct): void {
  if (!lines.value.some((line) => line.product.id === product.id)) {
    lines.value.push({ product, quantity: '1', unit_cost_htg: '' })
  }
  query.value = ''
  results.value = []
}

async function submit(): Promise<void> {
  if (!lines.value.length) {
    request.fail('Ajoutez au moins un article.')
    return
  }
  const result = await request.run(() => receiveStock({
    site_id: form.site_id,
    reference: form.reference.trim(),
    note: form.note.trim() || null,
    lines: lines.value.map((line) => ({
      product_id: line.product.id,
      quantity: Number(line.quantity),
      unit_cost_htg: line.unit_cost_htg.trim() ? line.unit_cost_htg.trim().replace(',', '.') : null,
    })),
  }))
  if (!result) return
  const total = result.data.reduce((sum, movement) => sum + movement.quantity, 0)
  emit('done', `Entrée enregistrée : ${total} pièce${total > 1 ? 's' : ''}.`)
}
</script>

<template>
  <SheetDialog :open="open" title="Entrée de stock" description="Arrivage ou réception fournisseur dans un magasin ou un entrepôt." :locked="request.busy.value" @close="emit('close')">
    <form id="receipt-form" class="form" novalidate @submit.prevent="submit">
      <div class="grid-2">
        <FormField label="Emplacement" :error="request.fieldErrors.value.site_id" v-slot="field">
          <select v-model="form.site_id" v-bind="field.attrs" class="select">
            <option v-for="site in sites" :key="site.id" :value="site.id">{{ site.name }}</option>
          </select>
        </FormField>
        <FormField label="Référence du bon ou de la facture" required :error="request.fieldErrors.value.reference" v-slot="field">
          <input v-model.trim="form.reference" v-bind="field.attrs" class="input" maxlength="120" autocomplete="off" />
        </FormField>
      </div>

      <FormField label="Ajouter un article" :error="request.fieldErrors.value.lines" v-slot="field">
        <input v-model="query" v-bind="field.attrs" class="input" type="search" inputmode="search" placeholder="Référence ou désignation" autocomplete="off" />
      </FormField>
      <div v-if="results.length" class="list">
        <button v-for="product in results" :key="product.id" class="list-row" type="button" @click="add(product)">
          <span><strong>{{ product.name }}</strong><br /><span class="text-small text-muted">{{ product.sku }}</span></span>
          <span aria-hidden="true">+</span>
        </button>
      </div>

      <fieldset v-for="(line, index) in lines" :key="line.product.id" class="form-group">
        <legend>{{ line.product.name }} - {{ line.product.sku }}</legend>
        <div class="grid-2">
          <FormField label="Quantité reçue" required :error="request.fieldErrors.value[`lines.${index}.quantity`]" v-slot="field">
            <input v-model.trim="line.quantity" v-bind="field.attrs" class="input" type="number" inputmode="numeric" min="1" />
          </FormField>
          <FormField label="Coût unitaire (HTG)" :error="request.fieldErrors.value[`lines.${index}.unit_cost_htg`]" v-slot="field">
            <input v-model.trim="line.unit_cost_htg" v-bind="field.attrs" class="input" inputmode="decimal" />
          </FormField>
        </div>
        <button class="btn btn-ghost" type="button" @click="lines.splice(index, 1)">Retirer</button>
      </fieldset>

      <FormField label="Note" :error="request.fieldErrors.value.note" v-slot="field">
        <input v-model.trim="form.note" v-bind="field.attrs" class="input" maxlength="1000" autocomplete="off" />
      </FormField>
      <InlineAlert :message="request.error.value" />
    </form>
    <template #footer>
      <button class="btn btn-secondary" type="button" :disabled="request.busy.value" @click="emit('close')">Annuler</button>
      <button class="btn btn-primary" type="submit" form="receipt-form" :disabled="request.busy.value || !lines.length || !app.canReachServer">
        {{ request.busy.value ? 'Enregistrement' : 'Enregistrer l’entrée' }}
      </button>
    </template>
  </SheetDialog>
</template>
