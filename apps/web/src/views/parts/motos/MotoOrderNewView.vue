<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { createMotoOrder, fetchMotoModels } from '../../../api/motos'
import type { Currency, MotoModel } from '../../../api/types'
import { useRequest } from '../../../composables/useRequest'
import { useSessionStore } from '../../../stores/session'
import { useAppStore } from '../../../stores/app'
import { useUiStore } from '../../../stores/ui'
import { formatMoney, formatRate, fromCents, toCents } from '../../../lib/money'
import { toDateInput } from '../../../lib/time'
import PageHeader from '../../../components/ui/PageHeader.vue'
import FormField from '../../../components/ui/FormField.vue'
import InlineAlert from '../../../components/ui/InlineAlert.vue'
import FileCapture from '../../../components/ui/FileCapture.vue'

/*
 * Commande d'une moto payée intégralement d'avance par dépôt bancaire.
 * Le prix du modèle est proposé comme montant ; un dépôt dans l'autre devise
 * est converti au taux du groupe. Un administrateur vérifie ensuite le dépôt.
 */
const router = useRouter()
const session = useSessionStore()
const app = useAppStore()
const ui = useUiStore()
const loading = useRequest()
const request = useRequest()

const models = ref<MotoModel[]>([])
const today = toDateInput(app.now)
const form = reactive({
  site_id: session.sites.find((site) => site.id === session.officeSiteId && site.kind === 'store')?.id
    ?? session.sites.find((site) => site.kind === 'store')?.id
    ?? session.sites[0]?.id
    ?? '',
  model_id: '',
  preferred_color: '',
  customer_name: '',
  customer_phone: '',
  customer_email: '',
  customer_address: '',
  payment_currency: 'USD' as Currency,
  payment_amount: '',
  bank_name: 'Sogebank',
  deposit_reference: '',
  deposit_date: today,
  deposit_proof_file_id: null as string | null,
})

const model = computed(() => models.value.find((item) => item.id === form.model_id) ?? null)
const rate = computed(() => session.context?.exchange_rate?.rate_htg_per_usd ?? null)
const needsRate = computed(() => !!model.value && model.value.price_currency !== form.payment_currency)
const equivalent = computed(() => {
  const amount = toCents(form.payment_amount)
  if (!model.value || amount === null) return null
  if (!needsRate.value) return amount
  const value = Number(rate.value)
  if (!value) return null
  return form.payment_currency === 'HTG' ? Math.floor(amount / value) : Math.round(amount * value)
})
const shortfall = computed(() => (model.value && equivalent.value !== null ? (toCents(model.value.price) ?? 0) - equivalent.value : null))

onMounted(async () => {
  const result = await loading.run(() => fetchMotoModels())
  models.value = (result?.data ?? []).filter((item) => item.is_active)
  if (!form.model_id && models.value[0]) form.model_id = models.value[0].id
})

// Montant proposé : le prix du modèle, dans sa devise.
watch(model, (next) => {
  if (!next) return
  form.payment_currency = next.price_currency
  form.payment_amount = next.price
}, { immediate: true })

async function submit(): Promise<void> {
  const result = await request.run(() => createMotoOrder({
    ...form,
    preferred_color: form.preferred_color.trim() || null,
    customer_email: form.customer_email.trim() || null,
    customer_address: form.customer_address.trim() || null,
    payment_amount: fromCents(toCents(form.payment_amount) ?? 0),
  }))
  if (!result) return
  ui.toast(`Commande ${result.data.number} enregistrée. Le dépôt doit être vérifié.`)
  await router.replace({ name: 'parts.moto.order', params: { orderId: result.data.id } })
}
</script>

<template>
  <PageHeader title="Nouvelle commande de moto" description="Paiement intégral d’avance par dépôt bancaire." :back="{ name: 'parts.motos' }" back-label="Motos" />

  <InlineAlert :message="loading.error.value" />
  <div v-if="!loading.busy.value && !models.length" class="empty">
    <p>Aucun modèle en vente. Ajoutez un modèle et son prix dans Motos, Modèles et stock.</p>
    <RouterLink class="btn btn-primary" :to="{ name: 'parts.moto.models' }">Modèles et stock</RouterLink>
  </div>

  <form v-else class="panel form" novalidate @submit.prevent="submit">
    <fieldset class="form-group" :disabled="request.busy.value">
      <legend>Moto</legend>
      <div class="grid-2">
        <FormField label="Modèle" required :error="request.fieldErrors.value.model_id" v-slot="field">
          <select v-model="form.model_id" v-bind="field.attrs" class="select">
            <option v-for="item in models" :key="item.id" :value="item.id">
              {{ item.label }} - {{ formatMoney(item.price, item.price_currency) }} ({{ item.available ?? 0 }} disponible{{ (item.available ?? 0) > 1 ? 's' : '' }})
            </option>
          </select>
        </FormField>
        <FormField label="Couleur souhaitée" help="Vide : la première moto du modèle, quelle que soit sa couleur." :error="request.fieldErrors.value.preferred_color" v-slot="field">
          <input v-model.trim="form.preferred_color" v-bind="field.attrs" class="input" maxlength="60" autocomplete="off" />
        </FormField>
        <FormField label="Retrait au magasin" required :error="request.fieldErrors.value.site_id" v-slot="field">
          <select v-model="form.site_id" v-bind="field.attrs" class="select">
            <option v-for="site in session.sites" :key="site.id" :value="site.id">{{ site.name }}</option>
          </select>
        </FormField>
      </div>
    </fieldset>

    <fieldset class="form-group" :disabled="request.busy.value">
      <legend>Client</legend>
      <div class="grid-2">
        <FormField label="Nom complet" required :error="request.fieldErrors.value.customer_name" v-slot="field">
          <input v-model.trim="form.customer_name" v-bind="field.attrs" class="input" maxlength="160" autocomplete="off" @input="request.clearField('customer_name')" />
        </FormField>
        <FormField label="Téléphone" required help="Le client est appelé à l’arrivée de sa moto." :error="request.fieldErrors.value.customer_phone" v-slot="field">
          <input v-model.trim="form.customer_phone" v-bind="field.attrs" class="input" type="tel" inputmode="tel" maxlength="40" placeholder="+509" autocomplete="off" @input="request.clearField('customer_phone')" />
        </FormField>
        <FormField label="Courriel" :error="request.fieldErrors.value.customer_email" v-slot="field">
          <input v-model.trim="form.customer_email" v-bind="field.attrs" class="input" type="email" inputmode="email" maxlength="254" autocomplete="off" />
        </FormField>
        <FormField label="Adresse" :error="request.fieldErrors.value.customer_address" v-slot="field">
          <input v-model.trim="form.customer_address" v-bind="field.attrs" class="input" maxlength="255" autocomplete="off" />
        </FormField>
      </div>
    </fieldset>

    <fieldset class="form-group" :disabled="request.busy.value">
      <legend>Dépôt bancaire</legend>
      <div class="grid-2">
        <FormField label="Devise du dépôt" :error="request.fieldErrors.value.payment_currency" v-slot="field">
          <div class="segmented" role="group" v-bind="field.attrs">
            <button type="button" :aria-pressed="form.payment_currency === 'USD'" @click="form.payment_currency = 'USD'">USD</button>
            <button type="button" :aria-pressed="form.payment_currency === 'HTG'" @click="form.payment_currency = 'HTG'">HTG</button>
          </div>
        </FormField>
        <FormField :label="`Montant déposé (${form.payment_currency})`" required :error="request.fieldErrors.value.payment_amount" v-slot="field">
          <input v-model.trim="form.payment_amount" v-bind="field.attrs" class="input" inputmode="decimal" autocomplete="off" @input="request.clearField('payment_amount')" />
        </FormField>
        <FormField label="Banque" :error="request.fieldErrors.value.bank_name" v-slot="field">
          <input v-model.trim="form.bank_name" v-bind="field.attrs" class="input" maxlength="60" />
        </FormField>
        <FormField label="N° de la fiche de dépôt" required :error="request.fieldErrors.value.deposit_reference" v-slot="field">
          <input v-model.trim="form.deposit_reference" v-bind="field.attrs" class="input" maxlength="80" autocomplete="off" @input="request.clearField('deposit_reference')" />
        </FormField>
        <FormField label="Date du dépôt" required :error="request.fieldErrors.value.deposit_date" v-slot="field">
          <input v-model="form.deposit_date" v-bind="field.attrs" class="input" type="date" :max="today" />
        </FormField>
      </div>
      <p v-if="needsRate && model" class="field-help">
        <template v-if="rate">Taux du groupe : {{ formatRate(rate) }}. Équivalent : {{ equivalent === null ? '-' : formatMoney(fromCents(equivalent), model.price_currency) }}.</template>
        <template v-else>Aucun taux HTG/USD n’est défini : déposez dans la devise du prix.</template>
      </p>
      <p v-if="model && shortfall !== null && shortfall > 0" class="alert alert-warning">
        Il manque {{ formatMoney(fromCents(shortfall), model.price_currency) }} : le paiement doit être intégral.
      </p>
      <FileCapture
        purpose="moto_deposit_proof"
        :site-id="form.site_id"
        label="Photo de la fiche de dépôt"
        accept="image/jpeg,image/png,application/pdf"
        help="Facultatif, recommandé. Visible seulement par les personnes qui vérifient ou remettent la moto."
        :disabled="request.busy.value || !form.site_id"
        @uploaded="(file) => (form.deposit_proof_file_id = file.id)"
        @cleared="form.deposit_proof_file_id = null"
      />
    </fieldset>

    <InlineAlert :message="request.error.value" />
    <button class="btn btn-primary btn-block" type="submit" :disabled="request.busy.value || !model || !app.canReachServer">
      {{ request.busy.value ? 'Enregistrement' : 'Enregistrer la commande' }}
    </button>
  </form>
</template>
