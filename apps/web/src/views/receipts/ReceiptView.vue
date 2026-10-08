<script setup lang="ts">
import { computed, nextTick, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import QRCode from 'qrcode'
import { fetchReceipt, recordReceiptPrint } from '../../api/finance'
import type { PaymentReceipt } from '../../api/types'
import { useRequest } from '../../composables/useRequest'
import { useUiStore } from '../../stores/ui'
import { formatMoney, formatRate } from '../../lib/money'
import { formatDateTime } from '../../lib/time'
import InlineAlert from '../../components/ui/InlineAlert.vue'

/*
 * Reçu de paiement au format thermique 80 mm. La page s'imprime telle
 * quelle ; sur un poste kiosque configuré, l'impression est silencieuse.
 * Chaque impression est journalisée, une seconde impression est marquée
 * « Réimpression ».
 */
const props = defineProps<{ paymentId: string }>()

const route = useRoute()
const router = useRouter()
const ui = useUiStore()
const loading = useRequest()

const receipt = ref<PaymentReceipt | null>(null)
const qrSvg = ref('')
const copy = ref<'client' | 'administration'>(route.query.copie === 'administration' ? 'administration' : 'client')
const printing = ref(false)

const methodLabels: Record<string, string> = {
  cash: 'Espèces',
  bank_transfer: 'Virement Sogebank',
  credit: 'Crédit accordé',
}

const isReprint = computed(() => (receipt.value?.print_count ?? 0) > 0)
const converted = computed(() => {
  const value = receipt.value
  return value && value.currency !== value.reservation_currency && value.amount_in_reservation_currency && value.exchange_rate_htg_per_usd ? value : null
})

onMounted(async () => {
  const result = await loading.run(() => fetchReceipt(props.paymentId))
  if (!result) return
  receipt.value = result.data
  if (result.data.verification_url) {
    qrSvg.value = await QRCode.toString(result.data.verification_url, { type: 'svg', margin: 0, errorCorrectionLevel: 'M' })
  }
})

async function print(target: 'client' | 'administration'): Promise<void> {
  if (!receipt.value || printing.value) return
  printing.value = true
  copy.value = target
  try {
    // La mention de réimpression doit figurer sur le papier : l'état est relu avant l'impression.
    const result = await recordReceiptPrint(props.paymentId, target)
    const before = receipt.value.print_count
    receipt.value = { ...result.data, print_count: before }
    await nextTick()
    window.print()
    receipt.value = result.data
  } catch (error) {
    ui.toast(error instanceof Error ? error.message : 'L’impression n’a pas pu être enregistrée.', 'danger')
  } finally {
    printing.value = false
  }
}

function back(): void {
  if (window.history.length > 1) router.back()
  else void router.push({ name: 'rental.reservations' })
}
</script>

<template>
  <div class="receipt-page">
    <div class="receipt-actions no-print">
      <button class="btn btn-secondary" type="button" @click="back">Retour</button>
      <button class="btn btn-primary" type="button" :disabled="!receipt || printing" @click="print('client')">Imprimer le reçu client</button>
      <button class="btn btn-secondary" type="button" :disabled="!receipt || printing" @click="print('administration')">Copie Administration</button>
    </div>

    <InlineAlert class="no-print" :message="loading.error.value" />

    <article v-if="receipt" class="receipt" :class="{ admin: copy === 'administration' }" aria-label="Reçu de paiement">
      <p v-if="copy === 'administration'" class="receipt-banner">COPIE ADMINISTRATION</p>
      <p v-if="isReprint" class="receipt-banner light">RÉIMPRESSION</p>

      <header class="receipt-head">
        <strong class="receipt-company">{{ receipt.company.display_name }}</strong>
        <span>{{ receipt.company.name }}</span>
        <span v-if="receipt.company.address">{{ receipt.company.address }}</span>
        <span v-if="receipt.company.phone_numbers">Tél. {{ receipt.company.phone_numbers }}</span>
        <span v-if="receipt.company.tax_identification_number">NIF {{ receipt.company.tax_identification_number }}</span>
      </header>

      <div class="receipt-title">
        <span>REÇU DE PAIEMENT</span>
        <strong class="receipt-number">{{ receipt.number }}</strong>
        <span>{{ receipt.issued_at ? formatDateTime(receipt.issued_at) : '' }}</span>
        <span>Heure de Cap-Haïtien</span>
      </div>

      <dl class="receipt-lines">
        <div v-if="receipt.site"><dt>Bureau</dt><dd>{{ receipt.site }}</dd></div>
        <div v-if="receipt.cash_register"><dt>Caisse</dt><dd>{{ receipt.cash_register }}</dd></div>
        <div><dt>Réservation</dt><dd>{{ receipt.reservation_number }}</dd></div>
        <div v-if="receipt.customer"><dt>Client</dt><dd>{{ receipt.customer }}</dd></div>
        <div v-if="receipt.vehicle"><dt>Véhicule</dt><dd>{{ receipt.vehicle }}</dd></div>
      </dl>

      <div class="receipt-item">
        <span>{{ receipt.kind === 'rental' ? 'Location de véhicule' : 'Dépôt de garantie' }}</span>
        <strong>{{ formatMoney(receipt.amount, receipt.currency) }}</strong>
      </div>
      <div class="receipt-total">
        <span>TOTAL PAYÉ</span>
        <strong>{{ formatMoney(receipt.amount, receipt.currency) }}</strong>
      </div>
      <dl class="receipt-lines">
        <div><dt>Mode</dt><dd>{{ methodLabels[receipt.method] ?? receipt.method }}</dd></div>
        <template v-if="converted">
          <div><dt>Taux</dt><dd>{{ formatRate(converted.exchange_rate_htg_per_usd) }}</dd></div>
          <div><dt>Équivalent</dt><dd>{{ formatMoney(converted.amount_in_reservation_currency, converted.reservation_currency) }}</dd></div>
        </template>
        <template v-if="copy === 'administration'">
          <div v-if="receipt.cashier"><dt>Approuvé par</dt><dd>{{ receipt.cashier }}</dd></div>
          <div v-if="receipt.bank_reference"><dt>Réf. virement</dt><dd>{{ receipt.bank_reference }}</dd></div>
          <div><dt>Impression</dt><dd>n° {{ receipt.print_count + 1 }}</dd></div>
        </template>
      </dl>

      <!-- eslint-disable-next-line vue/no-v-html -- SVG produit localement par la bibliothèque QR à partir de l'adresse de vérification. -->
      <div v-if="qrSvg" class="receipt-qr" v-html="qrSvg"></div>
      <p class="receipt-foot">Scannez le code pour vérifier ce reçu.</p>
      <p class="receipt-foot">Conservez ce reçu.</p>
    </article>
    <div v-else-if="loading.busy.value" class="skeleton no-print" style="height: 480px; width: 302px"></div>
  </div>
</template>

<style scoped>
.receipt-page {
  display: grid;
  justify-items: center;
  gap: 16px;
  min-height: 100vh;
  padding: 16px;
  background: var(--surface-sunken);
}

.receipt-actions {
  display: flex;
  flex-wrap: wrap;
  justify-content: center;
  gap: 8px;
}

/* 80 mm de papier, environ 72 mm imprimables. */
.receipt {
  width: 80mm;
  max-width: 100%;
  padding: 4mm;
  background: #ffffff;
  color: #000000;
  font-family: 'Segoe UI', Roboto, Arial, sans-serif;
  font-size: 11.5px;
  line-height: 1.35;
  box-shadow: var(--shadow-4);
}

.receipt-banner {
  margin-bottom: 6px;
  padding: 3px 0;
  border: 1.5px solid #000000;
  text-align: center;
  font-weight: 700;
  letter-spacing: 0.08em;
}

.receipt-banner.light {
  border-style: dashed;
  font-weight: 600;
}

.receipt-head,
.receipt-title {
  display: grid;
  justify-items: center;
  gap: 1px;
  text-align: center;
}

.receipt-company {
  font-size: 15px;
}

.receipt-title {
  margin: 8px 0;
  padding: 6px 0;
  border-top: 1px dashed #000000;
  border-bottom: 1px dashed #000000;
}

.receipt-number {
  font-size: 22px;
  letter-spacing: 0.06em;
  font-variant-numeric: tabular-nums;
}

.receipt-lines {
  display: grid;
  gap: 1px;
  margin: 6px 0;
}

.receipt-lines div {
  display: flex;
  justify-content: space-between;
  gap: 8px;
}

.receipt-lines dd {
  margin: 0;
  text-align: right;
  overflow-wrap: anywhere;
}

.receipt-item,
.receipt-total {
  display: flex;
  justify-content: space-between;
  gap: 8px;
}

.receipt-total {
  margin-top: 4px;
  padding-top: 4px;
  border-top: 1px solid #000000;
  font-size: 14px;
  font-weight: 700;
}

.receipt-qr {
  width: 34mm;
  margin: 8px auto 4px;
}

.receipt-qr :deep(svg) {
  display: block;
  width: 100%;
  height: auto;
}

.receipt-foot {
  text-align: center;
  font-size: 10.5px;
}

@media print {
  @page {
    size: 80mm auto;
    margin: 0;
  }

  .no-print {
    display: none !important;
  }

  .receipt-page {
    display: block;
    min-height: 0;
    padding: 0;
    background: #ffffff;
  }

  .receipt {
    width: 80mm;
    box-shadow: none;
  }
}
</style>
