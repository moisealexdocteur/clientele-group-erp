<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { fetchMotoModels, fetchMotoUnits, receiveMotoBatch, saveMotoModel } from '../../../api/motos'
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
import SheetDialog from '../../../components/ui/SheetDialog.vue'
import { HAOJIN_DEFAULT_PRICE } from '../../../data/haojinCatalog'
import { matchModel, parseNovamatDelivery, pdfText, type DeliveryLine } from '../../../lib/novamatDelivery'

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
interface UnitLine {
  model_id: string
  model_year: number
  frame_number: string
  engine_number: string
  color: string
  customs_document_available: boolean
  /** Code modèle lu sur le bon, tant qu'il n'existe pas dans les modèles. */
  source_model?: string | null
}
const units = ref<UnitLine[]>([])

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

// Import du bon Novamat : PDF lu dans le navigateur, ou lignes collées.
const importState = reactive({ busy: false, message: '', tone: 'info' as 'info' | 'warning' })
const pasteOpen = ref(false)
const pasted = ref('')
const fileInput = ref<HTMLInputElement | null>(null)

const missingModels = computed(() => [...new Set(units.value.filter((unit) => !unit.model_id && unit.source_model).map((unit) => unit.source_model as string))])
const summary = computed(() => {
  const groups = new Map<string, number>()
  for (const unit of units.value) {
    if (!unit.frame_number.trim()) continue
    const label = `${models.value.find((model) => model.id === unit.model_id)?.model ?? unit.source_model ?? 'Modèle ?'} ${unit.color}`.trim()
    groups.set(label, (groups.get(label) ?? 0) + 1)
  }
  return [...groups.entries()]
})
const duplicateFrames = computed(() => {
  const seen = new Map<string, number>()
  for (const unit of units.value) {
    const frame = unit.frame_number.trim().toUpperCase()
    if (frame) seen.set(frame, (seen.get(frame) ?? 0) + 1)
  }
  return new Set([...seen.entries()].filter(([, count]) => count > 1).map(([frame]) => frame))
})

function applyLines(lines: DeliveryLine[], duplicatesInFile: string[]): void {
  // Une ligne vide laissée par défaut est remplacée par l'import.
  units.value = units.value.filter((unit) => unit.frame_number.trim() || unit.engine_number.trim())
  const known = new Set(units.value.map((unit) => unit.frame_number.trim().toUpperCase()))
  let added = 0
  let skipped = duplicatesInFile.length
  for (const line of lines) {
    if (known.has(line.frame)) {
      skipped += 1
      continue
    }
    known.add(line.frame)
    const model = matchModel(line.model, models.value)
    units.value.push({
      model_id: model?.id ?? '',
      model_year: year,
      frame_number: line.frame,
      engine_number: line.engine,
      color: line.color ?? units.value[units.value.length - 1]?.color ?? '',
      customs_document_available: true,
      source_model: model ? null : line.model,
    })
    added += 1
  }
  importState.tone = added ? 'info' : 'warning'
  importState.message = added
    ? `${plural(added, 'moto lue', 'motos lues')}${skipped ? `, ${plural(skipped, 'doublon ignoré', 'doublons ignorés')}` : ''}. Vérifiez les lignes avant d’enregistrer.`
    : 'Aucune moto trouvée. Le bon doit contenir les numéros de châssis (17 caractères) et de moteur.'
  if (!units.value.length) addUnit()
}

async function importPdf(event: Event): Promise<void> {
  const file = (event.target as HTMLInputElement).files?.[0]
  if (!file) return
  importState.busy = true
  importState.message = ''
  try {
    const text = await pdfText(file)
    if (!text.trim()) {
      importState.tone = 'warning'
      importState.message = 'Ce PDF est une image scannée : son texte ne peut pas être lu. Collez les lignes ou saisissez les motos.'
      return
    }
    const parsed = parseNovamatDelivery(text)
    if (!form.reference) form.reference = file.name.replace(/\.pdf$/i, '').slice(0, 120)
    applyLines(parsed.lines, parsed.duplicates)
  } catch {
    importState.tone = 'warning'
    importState.message = 'Le PDF n’a pas pu être lu. Vérifiez le fichier ou collez les lignes.'
  } finally {
    importState.busy = false
    if (fileInput.value) fileInput.value.value = ''
  }
}

function importPasted(): void {
  const parsed = parseNovamatDelivery(pasted.value)
  applyLines(parsed.lines, parsed.duplicates)
  pasteOpen.value = false
  pasted.value = ''
}

// Modèles lus sur le bon mais absents : ajoutés au prix par défaut, puis rattachés.
async function addMissingModels(): Promise<void> {
  importState.busy = true
  for (const code of missingModels.value) {
    const created = await request.run(() => saveMotoModel({ make: 'Haojin', model: code, displacement_cc: null, cylinders: 1, ...HAOJIN_DEFAULT_PRICE, is_active: true }))
    if (!created) break
  }
  const result = await fetchMotoModels().catch(() => null)
  models.value = (result?.data ?? models.value).filter((item) => item.is_active)
  for (const unit of units.value) {
    if (unit.model_id || !unit.source_model) continue
    const model = matchModel(unit.source_model, models.value)
    if (model) {
      unit.model_id = model.id
      unit.source_model = null
    }
  }
  importState.busy = false
}

function setCustomsForAll(value: boolean): void {
  for (const unit of units.value) unit.customs_document_available = value
}

async function submit(): Promise<void> {
  const result = await request.run(() => receiveMotoBatch({
    site_id: form.site_id,
    reference: form.reference.trim(),
    note: form.note.trim() || null,
    units: units.value
      .filter((unit) => unit.frame_number.trim() || unit.engine_number.trim())
      .map(({ source_model: _source, ...unit }) => ({ ...unit, frame_number: unit.frame_number.trim(), engine_number: unit.engine_number.trim(), color: unit.color.trim() })),
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
    <RouterLink class="btn btn-primary" :to="{ name: 'parts.moto.models' }">Modèles et prix</RouterLink>
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

    <section class="panel stack import">
      <h2 class="title-section">Bon du fournisseur Novamat</h2>
      <p class="text-small text-secondary">Importez le PDF du bon ou de la facture : chaque moto est lue avec son modèle, sa couleur, son châssis et son moteur. Vous vérifiez avant d’enregistrer.</p>
      <div class="btn-row">
        <label class="btn btn-secondary file-button" :class="{ disabled: importState.busy }">
          <input ref="fileInput" type="file" accept="application/pdf" class="visually-hidden" :disabled="importState.busy" @change="importPdf" />
          {{ importState.busy ? 'Lecture' : 'Importer le PDF' }}
        </label>
        <button class="btn btn-ghost" type="button" @click="pasteOpen = true">Coller des lignes</button>
      </div>
      <p v-if="importState.message" class="alert" :class="importState.tone === 'warning' ? 'alert-warning' : 'alert-info'" role="status">{{ importState.message }}</p>
      <div v-if="missingModels.length" class="alert alert-warning">
        <p>Modèles absents de la liste : {{ missingModels.join(', ') }}.</p>
        <button class="btn btn-secondary" type="button" :disabled="importState.busy" @click="addMissingModels">Ajouter ces modèles à 200 000 HTG</button>
      </div>
      <div v-if="summary.length" class="lot-summary">
        <strong>{{ plural(units.filter((unit) => unit.frame_number.trim()).length, 'moto') }} dans ce lot</strong>
        <span class="chips"><span v-for="[label, count] in summary" :key="label" class="chip">{{ label }} : {{ count }}</span></span>
        <span class="btn-row">
          <button class="btn btn-ghost" type="button" @click="setCustomsForAll(true)">Dédouanement reçu pour tout le lot</button>
          <button class="btn btn-ghost" type="button" @click="setCustomsForAll(false)">Dédouanement attendu pour tout le lot</button>
        </span>
      </div>
    </section>

    <fieldset v-for="(unit, index) in units" :key="index" class="panel form-group" :disabled="request.busy.value">
      <legend class="title-section">Moto {{ index + 1 }}</legend>
      <div class="grid-2">
        <FormField label="Modèle" required :error="error(index, 'model_id')" v-slot="field">
          <select v-model="unit.model_id" v-bind="field.attrs" class="select">
            <option v-if="!unit.model_id" value="" disabled>{{ unit.source_model ? `${unit.source_model} : modèle à ajouter` : 'Choisir' }}</option>
            <option v-for="item in models" :key="item.id" :value="item.id">{{ item.label }}</option>
          </select>
        </FormField>
        <FormField label="Année" required :error="error(index, 'model_year')" v-slot="field">
          <input v-model.number="unit.model_year" v-bind="field.attrs" class="input" type="number" inputmode="numeric" min="2000" max="2100" />
        </FormField>
        <FormField label="N° de châssis (Frame No)" required :error="error(index, 'frame_number') || (duplicateFrames.has(unit.frame_number.trim().toUpperCase()) ? 'Ce châssis figure deux fois dans le lot.' : undefined)" v-slot="field">
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

  <SheetDialog :open="pasteOpen" title="Coller des lignes" description="Copiez les lignes du bon (PDF, Excel ou courriel) : châssis puis moteur sur chaque ligne, avec le code produit s’il est présent." @close="pasteOpen = false">
    <textarea v-model="pasted" class="input paste" rows="10" aria-label="Lignes du bon" placeholder="[HJ-125A-D-BL] HAOJIN MOTORCYCLE HJ-125A-D (bleu) 1.0 Unité(s) LZL12P1A2THG38820 HJ157FMI260738820"></textarea>
    <template #footer>
      <button class="btn btn-secondary" type="button" @click="pasteOpen = false">Annuler</button>
      <button class="btn btn-primary" type="button" :disabled="!pasted.trim()" @click="importPasted">Lire les lignes</button>
    </template>
  </SheetDialog>
</template>

<style scoped>
.file-button {
  position: relative;
  cursor: pointer;
}

.file-button.disabled {
  opacity: 0.6;
  pointer-events: none;
}

.lot-summary {
  display: grid;
  gap: 8px;
}

.chips {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
}

.chip {
  padding: 2px 8px;
  border-radius: 999px;
  background: var(--surface-sunken);
  font-size: var(--text-xs);
}

.paste {
  width: 100%;
  min-height: 200px;
  font-family: ui-monospace, monospace;
  font-size: var(--text-xs);
}

.customs {
  align-self: end;
  min-height: 48px;
  align-items: center;
}
</style>
