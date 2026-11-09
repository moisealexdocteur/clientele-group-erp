<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { allocateMotos, fetchMotoOrders, fetchMotoStock } from '../../../api/motos'
import type { MotoOrder, MotoOrderFilter } from '../../../api/types'
import { useRequest } from '../../../composables/useRequest'
import { useSessionStore } from '../../../stores/session'
import { useAppStore } from '../../../stores/app'
import { useUiStore } from '../../../stores/ui'
import { plural } from '../../../lib/text'
import PageHeader from '../../../components/ui/PageHeader.vue'
import InlineAlert from '../../../components/ui/InlineAlert.vue'
import AppIcon from '../../../components/ui/AppIcon.vue'
import MotoOrderRow from '../../../components/motos/MotoOrderRow.vue'

/*
 * Vente de motocyclettes : commandes payées d'avance, de la vérification du
 * dépôt à la remise. « À appeler » est la liste des clients dont la moto est
 * arrivée ; « En attente » suit l'ordre de paiement.
 */
const session = useSessionStore()
const app = useAppStore()
const ui = useUiStore()
const route = useRoute()
const router = useRouter()
const request = useRequest()
const action = useRequest()

const tabs: Array<{ value: MotoOrderFilter; label: string }> = [
  { value: 'assigned', label: 'À appeler' },
  { value: 'paid', label: 'En attente' },
  { value: 'submitted', label: 'Dépôts à vérifier' },
  { value: 'customs_pending', label: 'Dédouanement' },
  { value: 'delivered', label: 'Livrées' },
  { value: 'cancelled', label: 'Annulées' },
]

const status = ref<MotoOrderFilter>(tabs.some((tab) => tab.value === route.query.etat) ? (route.query.etat as MotoOrderFilter) : 'assigned')
const query = ref('')
const orders = ref<MotoOrder[]>([])
const counts = ref<Partial<Record<MotoOrderFilter, number>>>({})

const canCreate = computed(() => session.can('parts.motos.orders.create'))
const canReceive = computed(() => session.can('parts.motos.receive'))
const canAssign = computed(() => session.can('parts.motos.assign'))
const empty: Record<MotoOrderFilter, string> = {
  assigned: 'Aucune moto attribuée en attente de remise.',
  paid: 'Aucune commande payée en attente de moto.',
  submitted: 'Aucun dépôt à vérifier.',
  customs_pending: 'Aucun document de dédouanement à remettre.',
  delivered: 'Aucune moto livrée.',
  cancelled: 'Aucune commande annulée.',
}

// Résumé du stock, mis à jour à chaque réception et à chaque remise.
const stock = ref<{ available: number; reserved: number; waiting: number } | null>(null)
async function loadStock(): Promise<void> {
  const result = await fetchMotoStock().catch(() => null)
  stock.value = result?.meta ?? null
}

async function load(): Promise<void> {
  const result = await request.run(() => fetchMotoOrders({ status: status.value, q: query.value.trim() || undefined }))
  if (!result) return
  orders.value = result.data
  counts.value = result.meta.counts
}

function setStatus(value: MotoOrderFilter): void {
  status.value = value
  void router.replace({ query: { ...route.query, etat: value === 'assigned' ? undefined : value } })
  void load()
}

let timer: number | undefined
watch(query, () => {
  window.clearTimeout(timer)
  timer = window.setTimeout(() => void load(), 300)
})

async function allocate(): Promise<void> {
  const result = await action.run(() => allocateMotos())
  if (!result) {
    ui.toast(action.error.value, 'danger')
    return
  }
  ui.toast(result.data.assigned ? `${plural(result.data.assigned, 'moto attribuée', 'motos attribuées')}.` : 'Aucune moto disponible pour les commandes en attente.')
  await load()
}

onMounted(() => {
  void load()
  void loadStock()
})
onBeforeUnmount(() => window.clearTimeout(timer))
</script>

<template>
  <PageHeader title="Motos" description="Commandes payées d’avance, liste d’attente et remise.">
    <template #actions>
      <RouterLink v-if="canCreate" class="btn btn-primary" :to="{ name: 'parts.moto.order.new' }"><AppIcon name="plus" />Nouvelle commande</RouterLink>
      <RouterLink v-if="canReceive" class="btn btn-secondary" :to="{ name: 'parts.moto.batch.new' }">Réception d’un lot</RouterLink>
      <RouterLink class="btn btn-ghost" :to="{ name: 'parts.moto.receipt.check' }"><AppIcon name="scan" />Vérifier un reçu</RouterLink>
      <RouterLink class="btn btn-ghost" :to="{ name: 'parts.moto.models' }">Modèles et prix</RouterLink>
    </template>
  </PageHeader>

  <div class="stack">
    <RouterLink v-if="stock" class="stock-summary" :to="{ name: 'parts.moto.stock' }">
      <span><strong class="stock-number">{{ stock.available }}</strong> {{ stock.available > 1 ? 'motos disponibles' : 'moto disponible' }} à la vente</span>
      <span class="text-small text-secondary">{{ stock.reserved }} attribuée{{ stock.reserved > 1 ? 's' : '' }} - {{ stock.waiting }} client{{ stock.waiting > 1 ? 's' : '' }} payé{{ stock.waiting > 1 ? 's' : '' }} sans moto - voir le stock par modèle</span>
    </RouterLink>
    <div class="segmented" role="group" aria-label="État des commandes">
      <button v-for="tab in tabs" :key="tab.value" type="button" :aria-pressed="status === tab.value" @click="setStatus(tab.value)">
        {{ tab.label }} <span class="count">{{ counts[tab.value] ?? 0 }}</span>
      </button>
    </div>
    <input v-model="query" class="input" type="search" inputmode="search" placeholder="Client, téléphone, n° de commande ou de dépôt" aria-label="Rechercher une commande" autocomplete="off" />

    <InlineAlert :message="request.error.value" />
    <div class="list-head">
      <p class="text-muted text-small">{{ request.busy.value ? 'Chargement' : plural(orders.length, 'commande') }}</p>
      <button v-if="status === 'paid' && canAssign && orders.length" class="btn btn-ghost" type="button" :disabled="action.busy.value || !app.canReachServer" @click="allocate">
        Attribuer les motos disponibles
      </button>
    </div>

    <div v-if="request.busy.value && !orders.length" class="skeleton" style="height: 280px"></div>
    <div v-else-if="orders.length" class="list">
      <MotoOrderRow v-for="order in orders" :key="order.id" :order="order" />
    </div>
    <div v-else-if="!request.busy.value" class="empty"><p>{{ query ? 'Aucune commande ne correspond à cette recherche.' : empty[status] }}</p></div>
  </div>
</template>

<style scoped>
.stock-summary {
  display: grid;
  gap: 2px;
  padding: 12px 16px;
  border: 1px solid var(--accent);
  border-radius: var(--radius-control);
  background: var(--accent-soft);
  color: inherit;
  text-decoration: none;
  min-height: 48px;
}

.stock-number {
  font-size: 24px;
  font-variant-numeric: tabular-nums;
}

.count {
  margin-left: 4px;
  color: var(--ink-3);
  font-variant-numeric: tabular-nums;
}

[aria-pressed='true'] .count,
[aria-selected='true'] .count {
  color: inherit;
  opacity: 0.85;
}

.list-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}
</style>
