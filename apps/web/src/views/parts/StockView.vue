<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { fetchStockMovements } from '../../api/parts'
import type { PartsMovement, PartsMovementKind } from '../../api/types'
import { useRequest } from '../../composables/useRequest'
import { useSessionStore } from '../../stores/session'
import { useAppStore } from '../../stores/app'
import { useUiStore } from '../../stores/ui'
import { formatDateTime, toDateInput } from '../../lib/time'
import { plural } from '../../lib/text'
import PageHeader from '../../components/ui/PageHeader.vue'
import InlineAlert from '../../components/ui/InlineAlert.vue'
import AppIcon from '../../components/ui/AppIcon.vue'
import ReceiptDialog from '../../components/parts/ReceiptDialog.vue'

/*
 * Journal de stock des emplacements autorisés, chargé tout de suite pour
 * les sept derniers jours. Les entrées de stock se saisissent ici ; les
 * transferts et ajustements depuis la fiche article.
 */
const session = useSessionStore()
const app = useAppStore()
const ui = useUiStore()
const request = useRequest()

const kinds: Array<{ value: '' | PartsMovementKind; label: string }> = [
  { value: '', label: 'Tous' },
  { value: 'receipt', label: 'Entrées' },
  { value: 'sale', label: 'Ventes' },
  { value: 'transfer_out', label: 'Transferts' },
  { value: 'adjustment', label: 'Ajustements' },
]

const today = toDateInput(app.now)
const weekAgo = toDateInput(new Date(new Date(`${today}T12:00:00`).getTime() - 6 * 86_400_000))
const filters = reactive({ site_id: '', kind: '' as '' | PartsMovementKind, from: weekAgo, to: today })
const movements = ref<PartsMovement[]>([])
const receiptOpen = ref(false)

const sites = computed(() => session.sites.filter((site) => site.kind === 'store' || site.kind === 'warehouse'))
const canManage = computed(() => session.can('parts.stock.manage'))

async function load(): Promise<void> {
  const result = await request.run(() => fetchStockMovements({
    site_id: filters.site_id || undefined,
    kind: filters.kind || undefined,
    from: filters.from || undefined,
    to: filters.to || undefined,
  }))
  if (!result) return
  movements.value = result.data
}

watch(() => [filters.site_id, filters.kind, filters.from, filters.to], load)
onMounted(load)

async function received(message: string): Promise<void> {
  receiptOpen.value = false
  ui.toast(message)
  await load()
}
</script>

<template>
  <PageHeader title="Stock" description="Mouvements des magasins et entrepôts autorisés.">
    <template #actions>
      <RouterLink class="btn btn-secondary" :to="{ name: 'parts.products', query: { vue: 'stock-bas' } }">Stock bas</RouterLink>
      <button v-if="canManage" class="btn btn-primary" type="button" :disabled="!sites.length || !app.canReachServer" @click="receiptOpen = true">
        <AppIcon name="plus" />Entrée de stock
      </button>
    </template>
  </PageHeader>

  <div class="stack">
    <div class="filters">
      <select v-if="sites.length > 1" v-model="filters.site_id" class="select" aria-label="Emplacement">
        <option value="">Tous les emplacements</option>
        <option v-for="site in sites" :key="site.id" :value="site.id">{{ site.name }}</option>
      </select>
      <label class="field">
        <span class="field-label">Du</span>
        <input v-model="filters.from" class="input" type="date" :max="filters.to" />
      </label>
      <label class="field">
        <span class="field-label">Au</span>
        <input v-model="filters.to" class="input" type="date" :max="today" />
      </label>
    </div>
    <div class="segmented" role="group" aria-label="Nature du mouvement">
      <button v-for="item in kinds" :key="item.value" type="button" :aria-pressed="filters.kind === item.value" @click="filters.kind = item.value">{{ item.label }}</button>
    </div>

    <InlineAlert :message="request.error.value" />
    <p class="text-muted text-small">{{ request.busy.value ? 'Chargement' : plural(movements.length, 'mouvement') }}</p>

    <div v-if="request.busy.value && !movements.length" class="skeleton" style="height: 320px"></div>
    <div v-else-if="movements.length" class="list">
      <RouterLink v-for="movement in movements" :key="movement.id" class="list-row" :to="{ name: 'parts.product', params: { productId: movement.product?.id } }">
        <span class="movement-main">
          <strong>{{ movement.product?.name }}</strong>
          <span class="text-small text-muted">{{ movement.product?.sku }} - {{ movement.label }} - {{ movement.site.name }}</span>
          <span class="text-small text-muted">
            {{ movement.occurred_at ? formatDateTime(movement.occurred_at) : '' }}<template v-if="movement.reference"> - {{ movement.reference }}</template><template v-if="movement.recorded_by"> - {{ movement.recorded_by }}</template>
          </span>
        </span>
        <span class="movement-qty">
          <strong :class="movement.quantity < 0 ? 'negative' : 'positive'">{{ movement.quantity > 0 ? '+' : '' }}{{ movement.quantity }}</strong>
          <span class="text-small text-muted">solde {{ movement.balance_after }}</span>
        </span>
      </RouterLink>
    </div>
    <div v-else-if="!request.busy.value" class="empty">
      <p>Aucun mouvement sur cette période.</p>
    </div>
  </div>

  <ReceiptDialog :open="receiptOpen" @close="receiptOpen = false" @done="received" />
</template>

<style scoped>
.filters {
  display: grid;
  gap: 10px;
  grid-template-columns: repeat(2, minmax(0, 1fr));
}

.filters .select {
  grid-column: 1 / -1;
}

@media (min-width: 720px) {
  .filters {
    grid-template-columns: minmax(0, 1fr) 180px 180px;
  }

  .filters .select {
    grid-column: auto;
  }
}

.movement-main {
  display: grid;
  gap: 2px;
  min-width: 0;
}

.movement-qty {
  display: grid;
  justify-items: end;
  font-variant-numeric: tabular-nums;
}

.negative {
  color: var(--danger, #c50f1f);
}

.positive {
  color: var(--success, #0f7b0f);
}
</style>
