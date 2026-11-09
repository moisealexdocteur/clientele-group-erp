<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { createSale, fetchProducts } from '../../api/parts'
import type { ContextSite, PartsPriceList, PartsProduct } from '../../api/types'
import { useRequest } from '../../composables/useRequest'
import { useSessionStore } from '../../stores/session'
import { useAppStore } from '../../stores/app'
import { formatMoney, fromCents, toCents } from '../../lib/money'
import { addToCart, availableAt, cartTotalCents, itemCount, lineProblem, lineTotalCents, unitPriceCents, type CartLine } from '../../lib/partsCart'
import { plural } from '../../lib/text'
import PageHeader from '../../components/ui/PageHeader.vue'
import InlineAlert from '../../components/ui/InlineAlert.vue'
import SheetDialog from '../../components/ui/SheetDialog.vue'
import FormField from '../../components/ui/FormField.vue'
import ProductRow from '../../components/parts/ProductRow.vue'
import ScanButton from '../../components/ui/ScanButton.vue'

/*
 * Vente au comptoir : chercher, ajouter au panier, puis encaisser en
 * espèces HTG. Les prix affichés viennent du catalogue ; le serveur les
 * recalcule. Une pièce peut sortir du magasin ou d'un entrepôt.
 */
const router = useRouter()
const session = useSessionStore()
const app = useAppStore()
const search = useRequest()
const checkout = useRequest()

const stores = computed<ContextSite[]>(() => session.sites.filter((site) => site.kind === 'store'))
const stockSites = computed(() => session.sites.filter((site) => site.kind === 'store' || site.kind === 'warehouse'))
const canWholesale = computed(() => session.can('parts.sales.wholesale'))

const storeId = ref('')
const registerId = ref('')
const priceList = ref<PartsPriceList>('retail')
const query = ref('')
const results = ref<PartsProduct[]>([])
const total = ref(0)
const lines = ref<CartLine[]>([])
const checkoutOpen = ref(false)
const payment = reactive({ amount_received: '', customer_name: '' })
// Identifiant de vente gardé jusqu'au succès : un nouvel essai ne crée pas de doublon.
const saleId = ref(crypto.randomUUID())

const store = computed(() => stores.value.find((site) => site.id === storeId.value) ?? null)
const registers = computed(() => store.value?.cash_registers.filter((register) => register.is_active) ?? [])
const register = computed(() => registers.value.find((item) => item.id === registerId.value) ?? null)
const totalCents = computed(() => cartTotalCents(lines.value, priceList.value))
const count = computed(() => itemCount(lines.value))
const problems = computed(() => lines.value.map((line) => lineProblem(line, priceList.value)))
const hasProblem = computed(() => problems.value.some(Boolean))
const receivedCents = computed(() => toCents(payment.amount_received))
const changeCents = computed(() => (receivedCents.value === null ? null : receivedCents.value - totalCents.value))

function pickDefaults(): void {
  // Magasin proposé : le bureau actif s'il est un magasin, sinon le premier magasin autorisé.
  if (!stores.value.some((site) => site.id === storeId.value)) {
    storeId.value = stores.value.find((site) => site.id === session.officeSiteId)?.id ?? stores.value[0]?.id ?? ''
  }
  if (!registers.value.some((item) => item.id === registerId.value)) {
    registerId.value = registers.value.find((item) => item.is_open)?.id ?? registers.value[0]?.id ?? ''
  }
}

let timer: number | undefined
async function runSearch(): Promise<void> {
  const result = await search.run(() => fetchProducts({ q: query.value.trim() || undefined }))
  results.value = result?.data ?? []
  total.value = result?.meta.total ?? 0
}

watch(query, () => {
  window.clearTimeout(timer)
  timer = window.setTimeout(() => void runSearch(), 250)
})
watch(storeId, () => {
  if (!registers.value.some((item) => item.id === registerId.value)) {
    registerId.value = registers.value.find((item) => item.is_open)?.id ?? registers.value[0]?.id ?? ''
  }
})

onMounted(async () => {
  // L'état des caisses peut avoir changé depuis l'ouverture de la session.
  await session.refreshContext().catch(() => undefined)
  pickDefaults()
  await runSearch()
})
onBeforeUnmount(() => window.clearTimeout(timer))

function add(product: PartsProduct): void {
  lines.value = addToCart(lines.value, product, storeId.value)
}

function setQuantity(index: number, quantity: number): void {
  if (quantity < 1) {
    lines.value = lines.value.filter((_, position) => position !== index)
    return
  }
  lines.value = lines.value.map((line, position) => (position === index ? { ...line, quantity: Math.min(quantity, 10000) } : line))
}

function setSite(index: number, siteId: string): void {
  lines.value = lines.value.map((line, position) => (position === index ? { ...line, stock_site_id: siteId } : line))
}

function siteName(siteId: string): string {
  return session.sites.find((site) => site.id === siteId)?.name ?? 'Emplacement'
}

function openCheckout(): void {
  payment.amount_received = fromCents(totalCents.value)
  checkout.reset()
  checkoutOpen.value = true
}

async function confirm(): Promise<void> {
  if (receivedCents.value === null) {
    checkout.fail('Vérifiez les champs signalés.', { amount_received: 'Saisissez le montant reçu, par exemple 1500 ou 1500,50.' })
    return
  }
  if (receivedCents.value < totalCents.value) {
    checkout.fail('Vérifiez les champs signalés.', { amount_received: `Le montant reçu ne couvre pas le total de ${formatMoney(fromCents(totalCents.value), 'HTG')}.` })
    return
  }
  const result = await checkout.run(() => createSale({
    id: saleId.value,
    site_id: storeId.value,
    cash_register_id: registerId.value,
    price_list: priceList.value,
    customer_name: payment.customer_name.trim() || null,
    amount_received: fromCents(receivedCents.value ?? 0),
    lines: lines.value.map((line) => ({ product_id: line.product.id, stock_site_id: line.stock_site_id, quantity: line.quantity })),
  }))
  if (!result) return
  checkoutOpen.value = false
  lines.value = []
  saleId.value = crypto.randomUUID()
  await router.push({ name: 'parts.receipt', params: { saleId: result.data.id } })
}
</script>

<template>
  <PageHeader title="Vente au comptoir" :description="store ? `${store.name}${register ? ` - ${register.name}` : ''} - espèces HTG` : undefined">
    <template v-if="session.can('parts.sales.read')" #actions>
      <RouterLink class="btn btn-ghost" :to="{ name: 'parts.sales' }">Ventes du jour</RouterLink>
    </template>
  </PageHeader>

  <div v-if="!stores.length" class="empty">
    <p>Aucun magasin n’est accessible. Une adresse de type Magasin, avec une caisse, est nécessaire pour vendre.</p>
  </div>

  <div v-else class="sale">
    <section class="stack search" aria-labelledby="search-title">
      <h2 id="search-title" class="visually-hidden">Articles</h2>
      <div class="controls">
        <select v-if="stores.length > 1" v-model="storeId" class="select" aria-label="Magasin">
          <option v-for="site in stores" :key="site.id" :value="site.id">{{ site.name }}</option>
        </select>
        <select v-if="registers.length > 1" v-model="registerId" class="select" aria-label="Caisse">
          <option v-for="item in registers" :key="item.id" :value="item.id">{{ item.name }}{{ item.is_open ? '' : ' (fermée)' }}</option>
        </select>
        <div v-if="canWholesale" class="segmented" role="group" aria-label="Prix appliqué">
          <button type="button" :aria-pressed="priceList === 'retail'" @click="priceList = 'retail'">Détail</button>
          <button type="button" :aria-pressed="priceList === 'wholesale'" @click="priceList = 'wholesale'">Gros</button>
        </div>
      </div>

      <p v-if="!registers.length" class="alert alert-warning">Ce magasin n’a pas de caisse. Ajoutez-en une dans Configuration.</p>
      <p v-else-if="register && !register.is_open" class="alert alert-warning">
        La caisse « {{ register.name }} » est fermée.
        <RouterLink :to="{ name: 'cash' }">Ouvrir la caisse</RouterLink>
      </p>

      <div class="input-action">
        <input v-model="query" class="input" type="search" inputmode="search" placeholder="Référence, désignation, code-barres ou modèle de moto" aria-label="Rechercher un article" autocomplete="off" />
        <ScanButton label="code-barres de l’article" @scanned="(value) => (query = value)" />
      </div>
    </section>

    <section class="stack results" aria-label="Résultats">
      <InlineAlert :message="search.error.value" />
      <p class="text-muted text-small">
        {{ search.busy.value ? 'Recherche' : total > results.length ? `${results.length} premiers articles sur ${total}` : plural(results.length, 'article') }}
      </p>
      <div v-if="results.length" class="list">
        <ProductRow v-for="product in results" :key="product.id" :product="product" :price-list="priceList" :site-id="storeId" action @select="add(product)" />
      </div>
      <div v-else-if="!search.busy.value" class="empty">
        <p>{{ query ? 'Aucun article ne correspond à cette recherche.' : 'Le catalogue est vide.' }}</p>
      </div>
    </section>

    <section class="panel cart" :class="{ 'cart-empty': !lines.length }" aria-labelledby="cart-title">
      <div class="panel-header">
        <h2 id="cart-title" class="title-section">Panier</h2>
        <span class="text-secondary text-small">{{ plural(count, 'article') }}</span>
      </div>
      <p v-if="!lines.length" class="text-secondary">Touchez un article pour l’ajouter.</p>
      <ul v-else class="cart-lines">
        <li v-for="(line, index) in lines" :key="`${line.product.id}-${line.stock_site_id}`" class="cart-line">
          <div class="cart-line-head">
            <div class="cart-line-name">
              <strong>{{ line.product.name }}</strong>
              <span class="text-small text-muted">{{ line.product.sku }} - {{ formatMoney(fromCents(unitPriceCents(line.product, priceList) ?? 0), 'HTG') }}</span>
            </div>
            <strong class="cart-line-total">{{ formatMoney(fromCents(lineTotalCents(line, priceList)), 'HTG') }}</strong>
          </div>
          <div class="cart-line-controls">
            <div class="stepper" role="group" :aria-label="`Quantité de ${line.product.name}`">
              <button class="btn btn-secondary" type="button" :aria-label="line.quantity === 1 ? 'Retirer' : 'Diminuer'" @click="setQuantity(index, line.quantity - 1)">{{ line.quantity === 1 ? '×' : '−' }}</button>
              <input class="input" type="number" inputmode="numeric" min="1" :value="line.quantity" aria-label="Quantité" @change="setQuantity(index, Number(($event.target as HTMLInputElement).value) || 0)" />
              <button class="btn btn-secondary" type="button" aria-label="Augmenter" @click="setQuantity(index, line.quantity + 1)">+</button>
            </div>
            <select v-if="stockSites.length > 1" class="select" :value="line.stock_site_id" aria-label="Sortie du stock" @change="setSite(index, ($event.target as HTMLSelectElement).value)">
              <option v-for="site in stockSites" :key="site.id" :value="site.id">
                {{ site.name }}{{ availableAt(line.product, site.id) === null ? '' : ` (${availableAt(line.product, site.id)})` }}
              </option>
            </select>
            <span v-else class="text-small text-muted">{{ siteName(line.stock_site_id) }}</span>
          </div>
          <p v-if="problems[index]" class="field-error" role="alert">{{ problems[index] }}</p>
        </li>
      </ul>
      <div v-if="lines.length" class="cart-total">
        <span>Total</span>
        <strong class="display display-sm">{{ formatMoney(fromCents(totalCents), 'HTG') }}</strong>
      </div>
      <button class="btn btn-primary btn-block desktop-only" type="button" :disabled="!lines.length || hasProblem || !register || !app.canReachServer" @click="openCheckout">
        Encaisser
      </button>
    </section>

    <div class="action-bar">
      <span class="action-bar-text">
        <span class="text-small text-secondary">{{ plural(count, 'article') }}</span>
        <strong class="display display-sm">{{ formatMoney(fromCents(totalCents), 'HTG') }}</strong>
      </span>
      <button class="btn btn-primary" type="button" :disabled="!lines.length || hasProblem || !register || !app.canReachServer" @click="openCheckout">Encaisser</button>
    </div>
  </div>

  <SheetDialog :open="checkoutOpen" title="Encaisser en espèces" :description="register ? `${register.name} - ${priceList === 'wholesale' ? 'prix de gros' : 'prix de détail'}` : undefined" :locked="checkout.busy.value" @close="checkoutOpen = false">
    <form id="checkout-form" class="form" novalidate @submit.prevent="confirm">
      <dl class="facts">
        <div><dt>Total à payer</dt><dd class="display display-sm">{{ formatMoney(fromCents(totalCents), 'HTG') }}</dd></div>
      </dl>
      <FormField label="Montant reçu (HTG)" required :error="checkout.fieldErrors.value.amount_received" v-slot="field">
        <input v-model.trim="payment.amount_received" v-bind="field.attrs" class="input" inputmode="decimal" autocomplete="off" @input="checkout.clearField('amount_received')" />
      </FormField>
      <dl class="facts">
        <div><dt>Monnaie à rendre</dt><dd>{{ changeCents === null || changeCents < 0 ? '-' : formatMoney(fromCents(changeCents), 'HTG') }}</dd></div>
      </dl>
      <FormField label="Nom du client" help="Facultatif. Imprimé sur le reçu, jamais dans les listes." :error="checkout.fieldErrors.value.customer_name" v-slot="field">
        <input v-model="payment.customer_name" v-bind="field.attrs" class="input" maxlength="120" autocomplete="off" />
      </FormField>
      <InlineAlert :message="checkout.error.value" />
    </form>
    <template #footer>
      <button class="btn btn-secondary" type="button" :disabled="checkout.busy.value" @click="checkoutOpen = false">Annuler</button>
      <button class="btn btn-primary" type="submit" form="checkout-form" :disabled="checkout.busy.value || !app.canReachServer">
        {{ checkout.busy.value ? 'Encaissement' : `Encaisser ${formatMoney(fromCents(totalCents), 'HTG')}` }}
      </button>
    </template>
  </SheetDialog>
</template>

<style scoped>
.sale {
  display: grid;
  grid-template-areas: 'search' 'cart' 'results';
  gap: 16px;
  align-items: start;
  padding-bottom: 80px;
}

.search {
  grid-area: search;
}

.results {
  grid-area: results;
}

.cart {
  grid-area: cart;
}

/* Téléphone : le panier vide n'occupe pas d'espace ; plein, il reste sous la recherche. */
@media (max-width: 1099px) {
  .cart-empty {
    display: none;
  }
}

.controls {
  display: grid;
  gap: 10px;
}

.cart-lines {
  display: grid;
  gap: 0;
  list-style: none;
}

.cart-line {
  display: grid;
  gap: 8px;
  padding: 12px 0;
  border-top: 1px solid var(--line);
}

.cart-line:first-child {
  border-top: 0;
  padding-top: 0;
}

.cart-line-head {
  display: flex;
  justify-content: space-between;
  gap: 12px;
}

.cart-line-name {
  display: grid;
  min-width: 0;
}

.cart-line-total {
  white-space: nowrap;
  font-variant-numeric: tabular-nums;
}

.cart-line-controls {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
}

.cart-line-controls .select {
  flex: 1 1 160px;
  min-width: 0;
}

.stepper {
  display: grid;
  grid-template-columns: 48px 64px 48px;
  gap: 4px;
}

.stepper .btn {
  min-width: 48px;
  padding: 0;
  font-size: 1.2rem;
}

.stepper .input {
  text-align: center;
  font-variant-numeric: tabular-nums;
}

.cart-total {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  padding-top: 12px;
  border-top: 1px solid var(--line-strong);
}

.action-bar {
  position: fixed;
  z-index: 15;
  left: 0;
  right: 0;
  bottom: calc(var(--tabbar-h) + env(safe-area-inset-bottom));
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 8px var(--gutter);
  border-top: 1px solid var(--line);
  background: var(--surface);
  box-shadow: var(--shadow-16);
}

.action-bar-text {
  display: grid;
  min-width: 0;
}

.action-bar .btn {
  min-width: 140px;
}

.desktop-only {
  display: none;
}

@media (min-width: 720px) {
  .controls {
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  }
}

@media (min-width: 1100px) {
  .sale {
    grid-template-areas: 'search cart' 'results cart';
    grid-template-columns: minmax(0, 1fr) 400px;
    grid-template-rows: auto 1fr;
    padding-bottom: 0;
  }

  .cart {
    position: sticky;
    top: 24px;
  }

  .action-bar {
    display: none;
  }

  .desktop-only {
    display: flex;
  }
}

@media (min-width: 960px) and (max-width: 1099px) {
  .action-bar {
    left: var(--rail-w);
    bottom: 0;
  }
}
</style>
