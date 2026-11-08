<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { createProduct } from '../../api/parts'
import type { PartsProductInput } from '../../api/types'
import { useSessionStore } from '../../stores/session'
import { useAppStore } from '../../stores/app'
import { useUiStore } from '../../stores/ui'
import { useRequest } from '../../composables/useRequest'
import { cleanProductInput, emptyProductInput } from '../../lib/partsForm'
import { siteKindLabels } from '../../lib/labels'
import PageHeader from '../../components/ui/PageHeader.vue'
import FormField from '../../components/ui/FormField.vue'
import InlineAlert from '../../components/ui/InlineAlert.vue'
import ProductFields from '../../components/parts/ProductFields.vue'

/*
 * Nouvel article. Le stock initial est facultatif : il entre au journal
 * comme une entrée de stock « Stock initial » à l'emplacement choisi.
 */
const router = useRouter()
const session = useSessionStore()
const app = useAppStore()
const ui = useUiStore()
const request = useRequest()

const form = ref<PartsProductInput>(emptyProductInput())
const canStock = computed(() => session.can('parts.stock.manage'))
const stockSites = computed(() => session.sites.filter((site) => site.kind === 'store' || site.kind === 'warehouse'))
const initial = reactive({
  site_id: stockSites.value.find((site) => site.id === session.officeSiteId)?.id ?? stockSites.value[0]?.id ?? '',
  quantity: '',
  unit_cost_htg: '',
})

async function submit(): Promise<void> {
  const quantity = initial.quantity.trim() === '' ? null : Number(initial.quantity)
  const result = await request.run(() => createProduct({
    ...cleanProductInput(form.value),
    initial_site_id: quantity ? initial.site_id : null,
    initial_quantity: quantity,
    initial_unit_cost_htg: quantity && initial.unit_cost_htg.trim() ? initial.unit_cost_htg.trim().replace(',', '.') : null,
  }))
  if (!result) return
  ui.toast('Article ajouté.')
  await router.replace({ name: 'parts.product', params: { productId: result.data.id } })
}
</script>

<template>
  <PageHeader title="Ajouter un article" :back="{ name: 'parts.products' }" back-label="Articles" />

  <form class="panel form" novalidate @submit.prevent="submit">
    <ProductFields v-model="form" :errors="request.fieldErrors.value" :disabled="request.busy.value" @clear="request.clearField" />

    <fieldset v-if="canStock && stockSites.length" class="form-group" :disabled="request.busy.value">
      <legend>Stock initial</legend>
      <p class="field-help">Facultatif. Les arrivages suivants se saisissent dans Stock.</p>
      <div class="grid-2">
        <FormField label="Emplacement" :error="request.fieldErrors.value.initial_site_id" v-slot="field">
          <select v-model="initial.site_id" v-bind="field.attrs" class="select">
            <option v-for="site in stockSites" :key="site.id" :value="site.id">{{ site.name }} ({{ siteKindLabels[site.kind ?? 'store'] }})</option>
          </select>
        </FormField>
        <FormField label="Quantité" :error="request.fieldErrors.value.initial_quantity" v-slot="field">
          <input v-model.trim="initial.quantity" v-bind="field.attrs" class="input" type="number" inputmode="numeric" min="1" />
        </FormField>
        <FormField label="Coût unitaire (HTG)" help="Facultatif, pour le coût de revient." :error="request.fieldErrors.value.initial_unit_cost_htg" v-slot="field">
          <input v-model.trim="initial.unit_cost_htg" v-bind="field.attrs" class="input" inputmode="decimal" />
        </FormField>
      </div>
    </fieldset>

    <InlineAlert :message="request.error.value" />
    <button class="btn btn-primary btn-block" type="submit" :disabled="request.busy.value || !app.canReachServer">
      {{ request.busy.value ? 'Enregistrement' : 'Ajouter l’article' }}
    </button>
  </form>
</template>
