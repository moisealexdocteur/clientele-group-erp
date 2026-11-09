<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { fetchMotoModels, fetchMotoUnits, receiveMotoBatch } from '../../../api/motos'
import type { MotoModel } from '../../../api/types'
import { useRequest } from '../../../composables/useRequest'
import { useSessionStore } from '../../../stores/session'
import { useAppStore } from '../../../stores/app'
import { useUiStore } from '../../../stores/ui'
import { plural } from '../../../lib/text'
import PageHeader from '../../../components/ui/PageHeader.vue'
import FormField from '../../../components/ui/FormField.vue'
import InlineAlert from '../../../components/ui/InlineAlert.vue'
import ScanButton from '../../../components/ui/ScanButton.vue'
import { colorSuggestions } from '../../../data/motoColors'

/*
 * Réception d'un lot du fournisseur. Chaque moto est saisie avec ses numéros
 * de châssis et de moteur. À l'enregistrement, les motos sont attribuées aux
 * commandes payées dans l'ordre de paiement.
 */
const router = useRouter()
const session = useSessionStore()
const app = useAppStore()
const ui = useUiStore()
const loading = useRequest()
const request = useRequest()

const models = ref<MotoModel[]>([])
const sites = computed(() => session.sites.filter((site) => site.kind === 'store' || site.kind === 'warehouse'))
const year = new Date(app.now).getFullYear()
const form = reactive({
  site_id: sites.value.find((site) => site.id === session.officeSiteId)?.id ?? sites.value[0]?.id ?? '',
  reference: '',
  note: '',
})
const units = ref<Array<{ model_id: string; model_year: number; frame_number: string; engine_number: string; color: string; customs_document_available: boolean }>>([])

function addUnit(): void {
  const previous = units.value[units.value.length - 1]
  // Le modèle, l'année et la couleur de la ligne précédente sont repris.
  units.value.push({
    model_id: previous?.model_id ?? models.value[0]?.id ?? '',
    model_year: previous?.model_year ?? year,
    frame_number: '',
    engine_number: '',
    color: previous?.color ?? '',
    customs_document_available: previous?.customs_document_available ?? true,
  })
}

// Couleurs proposées : couleurs connues, couleurs du stock et du lot en cours.
const stockColors = ref<string[]>([])
const colors = computed(() => colorSuggestions([...stockColors.value, ...units.value.map((unit) => unit.color)]))

onMounted(async () => {
  const result = await loading.run(() => fetchMotoModels())
  models.value = (result?.data ?? []).filter((item) => item.is_active)
  addUnit()
  const stock = await fetchMotoUnits().catch(() => null)
  stockColors.value = (stock?.data ?? []).map((unit) => unit.color)
})

function error(index: number, field: string): string | undefined {
  return request.fieldErrors.value[`units.${index}.${field}`]
}

async function submit(): Promise<void> {
  const result = await request.run(() => receiveMotoBatch({
    site_id: form.site_id,
    reference: form.reference.trim(),
    note: form.note.trim() || null,
    units: units.value.map((unit) => ({ ...unit, frame_number: unit.frame_number.trim(), engine_number: unit.engine_number.trim(), color: unit.color.trim() })),
  }))
  if (!result) return
  ui.toast(`${plural(result.data.units.length, 'moto reçue', 'motos reçues')}, ${plural(result.data.assigned, 'attribuée', 'attribuées')} aux commandes payées.`)
  await router.replace({ name: 'parts.motos' })
}
</script>

<template>
  <PageHeader title="Réception d’un lot" description="Motos reçues du fournisseur, avec leurs numéros de châssis et de moteur." :back="{ name: 'parts.motos' }" back-label="Motos" />

  <div v-if="!loading.busy.value && !models.length" class="empty">
    <p>Aucun modèle en vente. Ajoutez d’abord le modèle et son prix.</p>
    <RouterLink class="btn btn-primary" :to="{ name: 'parts.moto.models' }">Modèles et stock</RouterLink>
  </div>

  <form v-else class="form" novalidate @submit.prevent="submit">
    <section class="panel">
      <div class="grid-2">
        <FormField label="Emplacement" required :error="request.fieldErrors.value.site_id" v-slot="field">
          <select v-model="form.site_id" v-bind="field.attrs" class="select">
            <option v-for="site in sites" :key="site.id" :value="site.id">{{ site.name }}</option>
          </select>
        </FormField>
        <FormField label="Référence du bon ou de la facture du fournisseur" required :error="request.fieldErrors.value.reference" v-slot="field">
          <input v-model.trim="form.reference" v-bind="field.attrs" class="input" maxlength="120" autocomplete="off" />
        </FormField>
      </div>
    </section>

    <fieldset v-for="(unit, index) in units" :key="index" class="panel form-group" :disabled="request.busy.value">
      <legend class="title-section">Moto {{ index + 1 }}</legend>
      <div class="grid-2">
        <FormField label="Modèle" required :error="error(index, 'model_id')" v-slot="field">
          <select v-model="unit.model_id" v-bind="field.attrs" class="select">
            <option v-for="item in models" :key="item.id" :value="item.id">{{ item.label }}</option>
          </select>
        </FormField>
        <FormField label="Année" required :error="error(index, 'model_year')" v-slot="field">
          <input v-model.number="unit.model_year" v-bind="field.attrs" class="input" type="number" inputmode="numeric" min="2000" max="2100" />
        </FormField>
        <FormField label="N° de châssis (Frame No)" required :error="error(index, 'frame_number')" v-slot="field">
          <div class="input-action">
            <input v-model="unit.frame_number" v-bind="field.attrs" class="input input-code" maxlength="64" autocapitalize="characters" autocomplete="off" />
            <ScanButton label="n° de châssis" @scanned="(value) => (unit.frame_number = value)" />
          </div>
        </FormField>
        <FormField label="N° de moteur (Engine No)" required :error="error(index, 'engine_number')" v-slot="field">
          <div class="input-action">
            <input v-model="unit.engine_number" v-bind="field.attrs" class="input input-code" maxlength="64" autocapitalize="characters" autocomplete="off" />
            <ScanButton label="n° de moteur" @scanned="(value) => (unit.engine_number = value)" />
          </div>
        </FormField>
        <FormField label="Couleur" required :error="error(index, 'color')" v-slot="field">
          <input v-model.trim="unit.color" v-bind="field.attrs" class="input" maxlength="60" autocomplete="off" list="moto-colors" placeholder="Choisir ou saisir" />
        </FormField>
        <label class="check customs">
          <input v-model="unit.customs_document_available" type="checkbox" />
          <span>Document de dédouanement reçu</span>
        </label>
      </div>
      <button v-if="units.length > 1" class="btn btn-ghost" type="button" @click="units.splice(index, 1)">Retirer cette moto</button>
    </fieldset>

    <datalist id="moto-colors">
      <option v-for="color in colors" :key="color" :value="color" />
    </datalist>
    <button class="btn btn-secondary" type="button" :disabled="units.length >= 200" @click="addUnit">Ajouter une moto</button>
    <InlineAlert :message="request.error.value" />
    <button class="btn btn-primary btn-block" type="submit" :disabled="request.busy.value || !units.length || !app.canReachServer">
      {{ request.busy.value ? 'Enregistrement' : `Enregistrer ${plural(units.length, 'moto')}` }}
    </button>
  </form>
</template>

<style scoped>
.customs {
  align-self: end;
  min-height: 48px;
  align-items: center;
}
</style>
