<script setup lang="ts">
import { ref, watch } from 'vue'
import { saveProductFitments } from '../../api/parts'
import type { PartsFitment, PartsProduct } from '../../api/types'
import { useRequest } from '../../composables/useRequest'
import { useAppStore } from '../../stores/app'
import SheetDialog from '../ui/SheetDialog.vue'
import InlineAlert from '../ui/InlineAlert.vue'

/* Compatibilités d'une pièce : marque, modèle et années de motocyclette lorsque connus. */
const props = defineProps<{ product: PartsProduct; open: boolean }>()
const emit = defineEmits<{ close: []; saved: [product: PartsProduct] }>()

const app = useAppStore()
const request = useRequest()
const rows = ref<Array<{ make: string; model: string; year_from: string; year_to: string }>>([])

watch(() => props.open, (open) => {
  if (!open) return
  request.reset()
  rows.value = props.product.fitments.map((fitment) => ({
    make: fitment.make,
    model: fitment.model ?? '',
    year_from: fitment.year_from === null ? '' : String(fitment.year_from),
    year_to: fitment.year_to === null ? '' : String(fitment.year_to),
  }))
  // Haojin est la marque vendue par Clientèle Auto Parts : proposée pour la première ligne.
  if (!rows.value.length) rows.value.push({ make: 'Haojin', model: '', year_from: '', year_to: '' })
}, { immediate: true })

function add(): void {
  rows.value.push({ make: rows.value[rows.value.length - 1]?.make ?? '', model: '', year_from: '', year_to: '' })
}

async function save(): Promise<void> {
  const year = (value: string) => (value.trim() === '' ? null : Number(value))
  const fitments: PartsFitment[] = rows.value
    .filter((row) => row.make.trim() !== '')
    .map((row) => ({ make: row.make.trim(), model: row.model.trim() || null, year_from: year(row.year_from), year_to: year(row.year_to) }))
  const result = await request.run(() => saveProductFitments(props.product.id, fitments))
  if (result) emit('saved', result.data)
}

function error(index: number, field: string): string | undefined {
  return request.fieldErrors.value[`fitments.${index}.${field}`]
}
</script>

<template>
  <SheetDialog :open="open" title="Compatibilités" description="Motocyclettes sur lesquelles la pièce se monte. Une ligne sans marque est ignorée." :locked="request.busy.value" @close="emit('close')">
    <form id="fitments-form" class="form" novalidate @submit.prevent="save">
      <fieldset v-for="(row, index) in rows" :key="index" class="form-group fitment">
        <legend>Compatibilité {{ index + 1 }}</legend>
        <div class="grid-2">
          <label class="field">
            <span class="field-label">Marque</span>
            <input v-model="row.make" class="input" maxlength="60" autocomplete="off" :aria-invalid="error(index, 'make') ? 'true' : undefined" />
            <span v-if="error(index, 'make')" class="field-error">{{ error(index, 'make') }}</span>
          </label>
          <label class="field">
            <span class="field-label">Modèle</span>
            <input v-model="row.model" class="input" maxlength="80" autocomplete="off" placeholder="Ex. HJ125-7" />
          </label>
          <label class="field">
            <span class="field-label">Année de début</span>
            <input v-model="row.year_from" class="input" type="number" inputmode="numeric" min="1950" max="2100" />
            <span v-if="error(index, 'year_from')" class="field-error">{{ error(index, 'year_from') }}</span>
          </label>
          <label class="field">
            <span class="field-label">Année de fin</span>
            <input v-model="row.year_to" class="input" type="number" inputmode="numeric" min="1950" max="2100" />
            <span v-if="error(index, 'year_to')" class="field-error">{{ error(index, 'year_to') }}</span>
          </label>
        </div>
        <button class="btn btn-ghost" type="button" @click="rows.splice(index, 1)">Retirer</button>
      </fieldset>
      <button class="btn btn-secondary" type="button" :disabled="rows.length >= 50" @click="add">Ajouter une compatibilité</button>
      <InlineAlert :message="request.error.value" />
    </form>
    <template #footer>
      <button class="btn btn-secondary" type="button" :disabled="request.busy.value" @click="emit('close')">Annuler</button>
      <button class="btn btn-primary" type="submit" form="fitments-form" :disabled="request.busy.value || !app.canReachServer">
        {{ request.busy.value ? 'Enregistrement' : 'Enregistrer' }}
      </button>
    </template>
  </SheetDialog>
</template>
