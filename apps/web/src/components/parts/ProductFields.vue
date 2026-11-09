<script setup lang="ts">
import type { PartsProductInput } from '../../api/types'
import FormField from '../ui/FormField.vue'
import ScanButton from '../ui/ScanButton.vue'

/* Champs d'un article, communs à la création et à la modification. */
defineProps<{
  errors: Record<string, string>
  disabled?: boolean
}>()

const form = defineModel<PartsProductInput>({ required: true })

const emit = defineEmits<{ clear: [field: string] }>()
</script>

<template>
  <fieldset class="form-group" :disabled="disabled">
    <legend>Identité</legend>
    <div class="grid-2">
      <FormField label="Référence" required help="Majuscules automatiques, par exemple HJ125-CHAINE-428." :error="errors.sku" v-slot="field">
        <input v-model.trim="form.sku" v-bind="field.attrs" class="input" maxlength="64" autocapitalize="characters" autocomplete="off" @input="emit('clear', 'sku')" />
      </FormField>
      <FormField label="Code-barres" :error="errors.barcode" v-slot="field">
        <div class="input-action">
          <input v-model.trim="form.barcode" v-bind="field.attrs" class="input" maxlength="64" inputmode="numeric" autocomplete="off" @input="emit('clear', 'barcode')" />
          <ScanButton label="code-barres" :disabled="disabled" @scanned="(value) => { form.barcode = value; emit('clear', 'barcode') }" />
        </div>
      </FormField>
    </div>
    <FormField label="Désignation" required :error="errors.name" v-slot="field">
      <input v-model.trim="form.name" v-bind="field.attrs" class="input" maxlength="255" autocomplete="off" @input="emit('clear', 'name')" />
    </FormField>
    <div class="grid-2">
      <FormField label="Catégorie" help="Par exemple Freinage, Moteur, Électricité." :error="errors.category" v-slot="field">
        <input v-model.trim="form.category" v-bind="field.attrs" class="input" maxlength="80" autocomplete="off" />
      </FormField>
      <FormField label="Marque de la pièce" :error="errors.brand" v-slot="field">
        <input v-model.trim="form.brand" v-bind="field.attrs" class="input" maxlength="80" autocomplete="off" />
      </FormField>
    </div>
  </fieldset>

  <fieldset class="form-group" :disabled="disabled">
    <legend>Prix et stock</legend>
    <div class="grid-2">
      <FormField label="Prix de détail (HTG)" required :error="errors.retail_price_htg" v-slot="field">
        <input v-model.trim="form.retail_price_htg" v-bind="field.attrs" class="input" inputmode="decimal" autocomplete="off" @input="emit('clear', 'retail_price_htg')" />
      </FormField>
      <FormField label="Prix de gros (HTG)" help="Vide si l’article ne se vend pas en gros." :error="errors.wholesale_price_htg" v-slot="field">
        <input v-model.trim="form.wholesale_price_htg" v-bind="field.attrs" class="input" inputmode="decimal" autocomplete="off" @input="emit('clear', 'wholesale_price_htg')" />
      </FormField>
      <FormField label="Unité de vente" required :error="errors.unit" v-slot="field">
        <input v-model.trim="form.unit" v-bind="field.attrs" class="input" maxlength="24" list="parts-units" autocomplete="off" />
      </FormField>
      <FormField label="Seuil de réapprovisionnement" help="Alerte « Stock bas » à ce niveau. 0 pour aucune alerte." :error="errors.reorder_level" v-slot="field">
        <input v-model.number="form.reorder_level" v-bind="field.attrs" class="input" type="number" inputmode="numeric" min="0" max="100000" />
      </FormField>
    </div>
    <datalist id="parts-units">
      <option value="pièce" />
      <option value="paire" />
      <option value="jeu" />
      <option value="kit" />
      <option value="litre" />
      <option value="bidon" />
    </datalist>
  </fieldset>

  <FormField label="Note interne" :error="errors.notes" v-slot="field">
    <textarea v-model="form.notes" v-bind="field.attrs" class="textarea" rows="2" maxlength="2000"></textarea>
  </FormField>
</template>
