<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { fetchMotoModels, fetchMotoUnits, saveMotoModel } from '../../../api/motos'
import type { Currency, MotoModel, MotoUnit } from '../../../api/types'
import { useRequest } from '../../../composables/useRequest'
import { useSessionStore } from '../../../stores/session'
import { useAppStore } from '../../../stores/app'
import { useUiStore } from '../../../stores/ui'
import { formatMoney } from '../../../lib/money'
import { plural } from '../../../lib/text'
import PageHeader from '../../../components/ui/PageHeader.vue'
import InlineAlert from '../../../components/ui/InlineAlert.vue'
import SheetDialog from '../../../components/ui/SheetDialog.vue'
import FormField from '../../../components/ui/FormField.vue'
import StatusPill from '../../../components/ui/StatusPill.vue'

/* Modèles vendus avec leur prix, et motos disponibles en stock. */
const session = useSessionStore()
const app = useAppStore()
const ui = useUiStore()
const loading = useRequest()
const action = useRequest()

const models = ref<MotoModel[]>([])
const units = ref<MotoUnit[]>([])
const editing = ref<MotoModel | null | 'new'>(null)
const form = reactive({ make: 'Haojin', model: '', displacement_cc: '' as string | number, cylinders: 1, price: '', price_currency: 'USD' as Currency, is_active: true })
const canManage = computed(() => session.can('parts.motos.models.manage'))

async function load(): Promise<void> {
  const result = await loading.run(() => Promise.all([fetchMotoModels(), fetchMotoUnits({ status: 'in_stock' })]))
  if (!result) return
  models.value = result[0].data
  units.value = result[1].data
}

onMounted(load)

function open(model: MotoModel | 'new'): void {
  action.reset()
  editing.value = model
  Object.assign(form, model === 'new'
    ? { make: 'Haojin', model: '', displacement_cc: '', cylinders: 1, price: '', price_currency: 'USD', is_active: true }
    : { make: model.make, model: model.model, displacement_cc: model.displacement_cc ?? '', cylinders: model.cylinders, price: model.price, price_currency: model.price_currency, is_active: model.is_active })
}

async function save(): Promise<void> {
  const target = editing.value
  const result = await action.run(() => saveMotoModel({
    make: form.make.trim(),
    model: form.model.trim(),
    displacement_cc: form.displacement_cc === '' ? null : Number(form.displacement_cc),
    cylinders: Number(form.cylinders),
    price: String(form.price).replace(/\s/g, '').replace(',', '.'),
    price_currency: form.price_currency,
    is_active: form.is_active,
  }, target && target !== 'new' ? target.id : undefined))
  if (!result) return
  editing.value = null
  ui.toast('Modèle enregistré.')
  await load()
}
</script>

<template>
  <PageHeader title="Modèles et stock" description="Prix de vente par modèle et motos disponibles." :back="{ name: 'parts.motos' }" back-label="Motos">
    <template v-if="canManage" #actions>
      <button class="btn btn-primary" type="button" :disabled="!app.canReachServer" @click="open('new')">Ajouter un modèle</button>
    </template>
  </PageHeader>

  <InlineAlert :message="loading.error.value" />
  <div class="stack-lg">
    <section class="stack" aria-labelledby="models-title">
      <h2 id="models-title" class="title-section">Modèles</h2>
      <div v-if="models.length" class="list">
        <component :is="canManage ? 'button' : 'div'" v-for="model in models" :key="model.id" class="list-row" type="button" @click="canManage && open(model)">
          <span class="row-main">
            <strong>{{ model.label }}</strong>
            <span class="text-small text-muted">{{ model.displacement_cc ? `${model.displacement_cc} cm³ - ` : '' }}{{ model.cylinders }} cyl. - {{ plural(model.available ?? 0, 'disponible') }}</span>
          </span>
          <span class="row-side">
            <strong>{{ formatMoney(model.price, model.price_currency) }}</strong>
            <StatusPill v-if="!model.is_active" tone="neutral" label="Retiré" />
          </span>
        </component>
      </div>
      <div v-else-if="!loading.busy.value" class="empty"><p>Aucun modèle. Ajoutez le premier modèle et son prix.</p></div>
    </section>

    <section class="stack" aria-labelledby="units-title">
      <h2 id="units-title" class="title-section">Motos disponibles</h2>
      <div v-if="units.length" class="list">
        <div v-for="unit in units" :key="unit.id" class="list-row">
          <span class="row-main">
            <strong>{{ unit.model?.label }} {{ unit.model_year }} - {{ unit.color }}</strong>
            <span class="text-small text-muted">Châssis {{ unit.frame_number }} - moteur {{ unit.engine_number }}</span>
          </span>
          <span class="row-side">
            <span class="text-small">{{ unit.site.name }}</span>
            <StatusPill v-if="!unit.customs_document_available" tone="warning" label="Dédouanement attendu" />
          </span>
        </div>
      </div>
      <div v-else-if="!loading.busy.value" class="empty"><p>Aucune moto disponible : toutes les motos reçues sont attribuées ou livrées.</p></div>
    </section>
  </div>

  <SheetDialog :open="editing !== null" :title="editing === 'new' ? 'Ajouter un modèle' : 'Modifier le modèle'" description="Une commande déjà enregistrée garde le prix du jour de son paiement." :locked="action.busy.value" @close="editing = null">
    <form id="model-form" class="form" novalidate @submit.prevent="save">
      <div class="grid-2">
        <FormField label="Marque" required :error="action.fieldErrors.value.make" v-slot="field">
          <input v-model.trim="form.make" v-bind="field.attrs" class="input" maxlength="40" />
        </FormField>
        <FormField label="Modèle" required :error="action.fieldErrors.value.model" v-slot="field">
          <input v-model.trim="form.model" v-bind="field.attrs" class="input" maxlength="60" placeholder="HJ-125A-D" autocapitalize="characters" />
        </FormField>
        <FormField label="Cylindrée (cm³)" :error="action.fieldErrors.value.displacement_cc" v-slot="field">
          <input v-model="form.displacement_cc" v-bind="field.attrs" class="input" type="number" inputmode="numeric" min="49" max="2000" />
        </FormField>
        <FormField label="Cylindres" required :error="action.fieldErrors.value.cylinders" v-slot="field">
          <input v-model.number="form.cylinders" v-bind="field.attrs" class="input" type="number" inputmode="numeric" min="1" max="8" />
        </FormField>
        <FormField label="Prix de vente" required :error="action.fieldErrors.value.price" v-slot="field">
          <input v-model.trim="form.price" v-bind="field.attrs" class="input" inputmode="decimal" />
        </FormField>
        <FormField label="Devise du prix" :error="action.fieldErrors.value.price_currency" v-slot="field">
          <select v-model="form.price_currency" v-bind="field.attrs" class="select">
            <option value="USD">USD</option>
            <option value="HTG">HTG</option>
          </select>
        </FormField>
      </div>
      <label v-if="editing !== 'new'" class="check">
        <input v-model="form.is_active" type="checkbox" />
        <span>En vente : proposé aux nouvelles commandes</span>
      </label>
      <InlineAlert :message="action.error.value" />
    </form>
    <template #footer>
      <button class="btn btn-secondary" type="button" :disabled="action.busy.value" @click="editing = null">Annuler</button>
      <button class="btn btn-primary" type="submit" form="model-form" :disabled="action.busy.value || !app.canReachServer">Enregistrer</button>
    </template>
  </SheetDialog>
</template>

<style scoped>
.row-main {
  display: grid;
  gap: 2px;
  min-width: 0;
}

.row-side {
  display: grid;
  justify-items: end;
  gap: 4px;
  text-align: right;
}
</style>
