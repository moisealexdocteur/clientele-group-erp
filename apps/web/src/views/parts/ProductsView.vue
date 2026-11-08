<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { fetchProducts } from '../../api/parts'
import type { PartsProduct } from '../../api/types'
import { useSessionStore } from '../../stores/session'
import { useRequest } from '../../composables/useRequest'
import { plural } from '../../lib/text'
import PageHeader from '../../components/ui/PageHeader.vue'
import InlineAlert from '../../components/ui/InlineAlert.vue'
import AppIcon from '../../components/ui/AppIcon.vue'
import ProductRow from '../../components/parts/ProductRow.vue'

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

onMounted(load)
onBeforeUnmount(() => window.clearTimeout(timer))
</script>

<template>
  <PageHeader title="Articles">
    <template #actions>
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
</template>
