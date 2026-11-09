<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { createProduct, fetchProducts } from '../../api/parts'
import type { PartsProduct } from '../../api/types'
import { useSessionStore } from '../../stores/session'
import { useRequest } from '../../composables/useRequest'
import { plural } from '../../lib/text'
import PageHeader from '../../components/ui/PageHeader.vue'
import InlineAlert from '../../components/ui/InlineAlert.vue'
import AppIcon from '../../components/ui/AppIcon.vue'
import ProductRow from '../../components/parts/ProductRow.vue'
import SheetDialog from '../../components/ui/SheetDialog.vue'
import FormField from '../../components/ui/FormField.vue'
import { useUiStore } from '../../stores/ui'
import { STARTER_LUBRICANTS, STARTER_LUBRICANT_QUANTITY, starterProductInput } from '../../data/starterLubricants'
import { formatMoney } from '../../lib/money'

/*
 * Catalogue : les articles actifs sont listés tout de suite ; la recherche
 * porte sur la référence, le code-barres, la désignation, la marque, la
 * catégorie et la compatibilité (marque et modèle de moto).
 */
const session = useSessionStore()
const route = useRoute()
const router = useRouter()
const request = useRequest()

type Filter = 'active' | 'low' | 'all'
const filter = ref<Filter>(route.query.vue === 'stock-bas' ? 'low' : route.query.vue === 'tous' ? 'all' : 'active')
const query = ref(typeof route.query.q === 'string' ? route.query.q : '')
const products = ref<PartsProduct[]>([])
const total = ref(0)

const canManage = computed(() => session.can('parts.catalog.manage'))
const seesStock = computed(() => session.can('parts.stock.read'))
const officeId = computed(() => session.sites.find((site) => site.id === session.officeSiteId && site.kind !== 'office')?.id ?? '')

async function load(): Promise<void> {
  const result = await request.run(() => fetchProducts({
    q: query.value.trim() || undefined,
    include_inactive: filter.value === 'all',
    low_stock: filter.value === 'low',
  }))
  products.value = result?.data ?? []
  total.value = result?.meta.total ?? 0
}

let timer: number | undefined
watch(query, () => {
  window.clearTimeout(timer)
  timer = window.setTimeout(() => {
    void router.replace({ query: { ...route.query, q: query.value.trim() || undefined } })
    void load()
  }, 250)
})

function setFilter(value: Filter): void {
  filter.value = value
  void router.replace({ query: { ...route.query, vue: value === 'low' ? 'stock-bas' : value === 'all' ? 'tous' : undefined } })
  void load()
}

// Lubrifiants de test demandés pour la recette : proposés tant qu'il en manque.
const ui = useUiStore()
const starter = useRequest()
const existingSkus = ref<string[]>([])
const starterOpen = ref(false)
const stockSites = computed(() => session.sites.filter((site) => site.kind === 'store' || site.kind === 'warehouse'))
const starterSite = ref('')
const missingStarter = computed(() => STARTER_LUBRICANTS.filter((product) => !existingSkus.value.includes(product.sku)))
const canSeed = computed(() => canManage.value && session.can('parts.stock.manage') && stockSites.value.length > 0)

async function loadStarter(): Promise<void> {
  if (!canSeed.value) return
  const result = await fetchProducts({ q: 'LUB-', include_inactive: true }).catch(() => null)
  existingSkus.value = (result?.data ?? []).map((product) => product.sku)
}

function openStarter(): void {
  starter.reset()
  starterSite.value = stockSites.value.find((site) => site.id === officeId.value)?.id ?? stockSites.value[0]?.id ?? ''
  starterOpen.value = true
}

async function addStarter(): Promise<void> {
  let created = 0
  for (const product of missingStarter.value) {
    const result = await starter.run(() => createProduct({ ...starterProductInput(product), initial_site_id: starterSite.value, initial_quantity: STARTER_LUBRICANT_QUANTITY }))
    if (!result) break
    created += 1
    existingSkus.value.push(product.sku)
  }
  if (created) ui.toast(`${plural(created, 'lubrifiant ajouté', 'lubrifiants ajoutés')}, ${STARTER_LUBRICANT_QUANTITY} caisses chacun.`)
  if (!starter.error.value) starterOpen.value = false
  await load()
}

onMounted(() => {
  void load()
  void loadStarter()
})
onBeforeUnmount(() => window.clearTimeout(timer))
</script>

<template>
  <PageHeader title="Articles">
    <template #actions>
      <button v-if="canSeed && missingStarter.length" class="btn btn-secondary" type="button" @click="openStarter">Lubrifiants de test ({{ missingStarter.length }})</button>
      <RouterLink v-if="canManage" class="btn btn-primary" :to="{ name: 'parts.product.new' }">
        <AppIcon name="plus" />Ajouter un article
      </RouterLink>
    </template>
  </PageHeader>

  <div class="stack">
    <input v-model="query" class="input" type="search" inputmode="search" placeholder="Référence, désignation, code-barres ou modèle de moto" aria-label="Rechercher un article" autocomplete="off" />

    <div class="segmented" role="group" aria-label="Articles affichés">
      <button type="button" :aria-pressed="filter === 'active'" @click="setFilter('active')">En vente</button>
      <button v-if="seesStock" type="button" :aria-pressed="filter === 'low'" @click="setFilter('low')">Stock bas</button>
      <button type="button" :aria-pressed="filter === 'all'" @click="setFilter('all')">Tous</button>
    </div>

    <InlineAlert :message="request.error.value" />
    <p class="text-muted text-small">
      {{ request.busy.value ? 'Chargement' : total > products.length ? `${products.length} premiers articles sur ${total}. Affinez la recherche.` : plural(products.length, 'article') }}
    </p>

    <div v-if="request.busy.value && !products.length" class="skeleton" style="height: 320px"></div>
    <div v-else-if="products.length" class="list">
      <ProductRow v-for="product in products" :key="product.id" :product="product" :site-id="officeId" />
    </div>
    <div v-else-if="!request.busy.value" class="empty">
      <p>{{ query || filter !== 'active' ? 'Aucun article ne correspond à ces critères.' : 'Aucun article n’est encore enregistré.' }}</p>
      <RouterLink v-if="canManage && !query && filter === 'active'" class="btn btn-primary" :to="{ name: 'parts.product.new' }">Ajouter un article</RouterLink>
    </div>
  </div>

  <SheetDialog :open="starterOpen" title="Lubrifiants de test" :description="`${missingStarter.length} articles en caisse, ${STARTER_LUBRICANT_QUANTITY} caisses chacun, à ${formatMoney('25000.00', 'HTG')} la caisse (prix de détail et de gros). Prix, grade et contenance à corriger ensuite.`" :locked="starter.busy.value" @close="starterOpen = false">
    <form id="starter-form" class="form" novalidate @submit.prevent="addStarter">
      <FormField label="Emplacement du stock" required v-slot="field">
        <select v-model="starterSite" v-bind="field.attrs" class="select">
          <option v-for="site in stockSites" :key="site.id" :value="site.id">{{ site.name }}</option>
        </select>
      </FormField>
      <ul class="starter-list text-small">
        <li v-for="product in missingStarter" :key="product.sku">{{ product.name }}</li>
      </ul>
      <InlineAlert :message="starter.error.value" />
    </form>
    <template #footer>
      <button class="btn btn-secondary" type="button" :disabled="starter.busy.value" @click="starterOpen = false">Annuler</button>
      <button class="btn btn-primary" type="submit" form="starter-form" :disabled="starter.busy.value || !starterSite">{{ starter.busy.value ? 'Ajout' : 'Ajouter' }}</button>
    </template>
  </SheetDialog>
</template>

<style scoped>
.starter-list {
  display: grid;
  gap: 4px;
  margin: 0;
  padding-left: 20px;
}
</style>
