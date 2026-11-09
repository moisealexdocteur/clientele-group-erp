<script setup lang="ts">
import { computed, nextTick, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import QRCode from 'qrcode'
import { fetchSale, recordSaleReceiptPrint } from '../../api/parts'
import type { PartsSale } from '../../api/types'
import { useRequest } from '../../composables/useRequest'
import { useSessionStore } from '../../stores/session'
import { useUiStore } from '../../stores/ui'
import { formatMoney } from '../../lib/money'
import { formatDateTime } from '../../lib/time'
import InlineAlert from '../../components/ui/InlineAlert.vue'
import '../../styles/receipt80.css'

/*
 * Reçu d'une vente au comptoir, format thermique 80 mm. Chaque impression
 * est journalisée ; une seconde impression porte la mention « Réimpression ».
 */
const props = defineProps<{ saleId: string }>()

const route = useRoute()
const router = useRouter()
const session = useSessionStore()
const ui = useUiStore()
const loading = useRequest()

const sale = ref<PartsSale | null>(null)
const qrSvg = ref('')
const copy = ref<'client' | 'administration'>(route.query.copie === 'administration' ? 'administration' : 'client')
const printing = ref(false)

const isReprint = computed(() => (sale.value?.print_count ?? 0) > 0)
const canSell = computed(() => session.can('parts.sales.create'))

onMounted(async () => {
  const result = await loading.run(() => fetchSale(props.saleId))
  if (!result) return
  sale.value = result.data
  if (result.data.verification_url) {
    qrSvg.value = await QRCode.toString(result.data.verification_url, { type: 'svg', margin: 0, errorCorrectionLevel: 'M' })
  }
})

async function print(target: 'client' | 'administration'): Promise<void> {
  if (!sale.value || printing.value) return
  printing.value = true
  copy.value = target
  try {
    const result = await recordSaleReceiptPrint(props.saleId, target)
    const before = sale.value.print_count
    sale.value = { ...result.data, print_count: before }
    await nextTick()
    window.print()
    sale.value = result.data
  } catch (error) {
    ui.toast(error instanceof Error ? error.message : 'L’impression n’a pas pu être enregistrée.', 'danger')
  } finally {
    printing.value = false
  }
}
</script>

<template>
  <div class="receipt-page">
    <div class="receipt-actions no-print">
      <RouterLink v-if="canSell" class="btn btn-secondary" :to="{ name: 'parts.sale' }">Nouvelle vente</RouterLink>
      <button v-else class="btn btn-secondary" type="button" @click="router.push({ name: 'parts.sales' })">Ventes du jour</button>
      <button class="btn btn-primary" type="button" :disabled="!sale || printing" @click="print('client')">Imprimer le reçu client</button>
      <button class="btn btn-secondary" type="button" :disabled="!sale || printing" @click="print('administration')">Copie Administration</button>
    </div>

    <InlineAlert class="no-print" :message="loading.error.value" />

    <article v-if="sale" class="receipt" :class="{ admin: copy === 'administration' }" aria-label="Reçu de vente">
      <p v-if="copy === 'administration'" class="receipt-banner">COPIE ADMINISTRATION</p>
      <p v-if="isReprint" class="receipt-banner light">RÉIMPRESSION</p>

      <header class="receipt-head">
        <strong class="receipt-company">{{ sale.company.display_name }}</strong>
        <span>{{ sale.company.name }}</span>
        <span v-if="sale.site_address">{{ sale.site_address }}</span>
        <span v-if="sale.company.phone_numbers">Tél. {{ sale.company.phone_numbers }}</span>
        <span v-if="sale.company.tax_identification_number">NIF {{ sale.company.tax_identification_number }}</span>
      </header>

      <div class="receipt-title">
        <span>REÇU DE VENTE</span>
        <strong class="receipt-number">{{ sale.number }}</strong>
        <span>{{ sale.sold_at ? formatDateTime(sale.sold_at) : '' }}</span>
        <span>Heure de Cap-Haïtien</span>
      </div>

      <dl class="receipt-lines">
        <div v-if="sale.site.name"><dt>Magasin</dt><dd>{{ sale.site.name }}</dd></div>
        <div v-if="sale.cash_register.name"><dt>Caisse</dt><dd>{{ sale.cash_register.name }}</dd></div>
        <div><dt>Prix</dt><dd>{{ sale.price_list_label }}</dd></div>
        <div v-if="sale.customer_name"><dt>Client</dt><dd>{{ sale.customer_name }}</dd></div>
      </dl>

      <ul class="sale-lines">
        <li v-for="line in sale.lines" :key="line.id">
          <span class="sale-line-name">{{ line.name }}</span>
          <span class="sale-line-detail">
            <span>{{ line.sku }} - {{ line.quantity }} x {{ formatMoney(line.unit_price, 'HTG') }}</span>
            <strong>{{ formatMoney(line.line_total, 'HTG') }}</strong>
          </span>
        </li>
      </ul>

      <dl class="receipt-lines">
        <div><dt>Articles</dt><dd>{{ sale.item_count }}</dd></div>
      </dl>
      <div class="receipt-total">
        <span>TOTAL</span>
        <strong>{{ formatMoney(sale.total, 'HTG') }}</strong>
      </div>
      <dl class="receipt-lines">
        <div><dt>Espèces reçues</dt><dd>{{ formatMoney(sale.amount_received, 'HTG') }}</dd></div>
        <div><dt>Monnaie rendue</dt><dd>{{ formatMoney(sale.change_given, 'HTG') }}</dd></div>
        <template v-if="copy === 'administration'">
          <div v-if="sale.seller"><dt>Vendeur</dt><dd>{{ sale.seller }}</dd></div>
          <div v-for="line in sale.lines.filter((item) => item.stock_site.id !== sale?.site.id)" :key="`stock-${line.id}`">
            <dt>Sortie {{ line.sku }}</dt><dd>{{ line.stock_site.name }}</dd>
          </div>
          <div><dt>Impression</dt><dd>n° {{ sale.print_count + 1 }}</dd></div>
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
.sale-lines {
  display: grid;
  gap: 4px;
  margin: 6px 0;
  padding: 6px 0 0;
  border-top: 1px dashed #000000;
  list-style: none;
}

.sale-lines li {
  display: grid;
}

.sale-line-name {
  font-weight: 600;
  overflow-wrap: anywhere;
}

.sale-line-detail {
  display: flex;
  justify-content: space-between;
  gap: 8px;
}

.sale-line-detail strong {
  white-space: nowrap;
}
</style>
