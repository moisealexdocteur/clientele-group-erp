<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { fetchMotoStock } from '../../../api/motos'
import type { MotoStockRow } from '../../../api/types'
import { useRequest } from '../../../composables/useRequest'
import { useSessionStore } from '../../../stores/session'
import { formatMoney } from '../../../lib/money'
import { plural } from '../../../lib/text'
import PageHeader from '../../../components/ui/PageHeader.vue'
import InlineAlert from '../../../components/ui/InlineAlert.vue'
import ProductThumb from '../../../components/parts/ProductThumb.vue'

/*
 * Stock de motos par modèle : combien sont disponibles à la vente,
 * combien sont déjà attribuées à des clients payés, et combien de clients
 * payés attendent encore une moto. Mis à jour à chaque réception de lot.
 */
const session = useSessionStore()
const request = useRequest()
const rows = ref<MotoStockRow[]>([])
const totals = ref({ available: 0, reserved: 0, waiting: 0 })
const canReceive = computed(() => session.can('parts.motos.receive'))

async function load(): Promise<void> {
  const result = await request.run(() => fetchMotoStock())
  if (!result) return
  rows.value = result.data
  totals.value = result.meta
}

onMounted(load)
</script>

<template>
  <PageHeader title="Stock de motos" description="Motos disponibles à la vente, par modèle." :back="{ name: 'parts.motos' }" back-label="Motos">
    <template #actions>
      <RouterLink v-if="canReceive" class="btn btn-primary" :to="{ name: 'parts.moto.batch.new' }">Réception d’un lot</RouterLink>
      <RouterLink class="btn btn-ghost" :to="{ name: 'parts.moto.models' }">Modèles et prix</RouterLink>
    </template>
  </PageHeader>

  <InlineAlert :message="request.error.value" />

  <div class="totals" role="list">
    <div class="total total-main" role="listitem">
      <span class="total-value">{{ totals.available }}</span>
      <span class="total-label">Disponibles à la vente</span>
    </div>
    <div class="total" role="listitem">
      <span class="total-value">{{ totals.reserved }}</span>
      <span class="total-label">Attribuées, à remettre</span>
    </div>
    <div class="total" role="listitem">
      <span class="total-value">{{ totals.waiting }}</span>
      <span class="total-label">Clients payés sans moto</span>
    </div>
  </div>

  <div v-if="request.busy.value && !rows.length" class="skeleton" style="height: 240px"></div>
  <div v-else-if="!rows.length" class="empty">
    <p>Aucun modèle. Importez les modèles Haojin dans Modèles et prix.</p>
  </div>
  <div v-else class="list-card">
    <div v-for="row in rows" :key="row.model.id" class="list-row stock-row" :class="{ muted: !row.model.is_active }">
      <ProductThumb :path="row.model.photo_url ?? null" :alt="row.model.label" icon="moto" fit="contain" />
      <span class="row-main">
        <strong>{{ row.model.label }}</strong>
        <span class="text-small text-secondary">{{ formatMoney(row.model.price, row.model.price_currency) }}<template v-if="!row.model.is_active"> - désactivé</template></span>
        <span v-if="row.sites.length" class="text-small">
          <template v-for="(site, index) in row.sites" :key="site.name ?? index">{{ index ? ' - ' : '' }}{{ site.name }} : {{ site.count }}</template>
        </span>
        <span v-if="row.colors.length" class="chips">
          <span v-for="color in row.colors" :key="color.color" class="chip">{{ color.color }} {{ color.count }}</span>
        </span>
        <span v-if="row.reserved || row.waiting" class="text-small text-muted">
          {{ row.reserved ? plural(row.reserved, 'attribuée', 'attribuées') : '' }}{{ row.reserved && row.waiting ? ' - ' : '' }}{{ row.waiting ? plural(row.waiting, 'client payé en attente', 'clients payés en attente') : '' }}
        </span>
      </span>
      <span class="row-side">
        <span class="stock-count" :class="{ zero: !row.available }">{{ row.available }}</span>
        <span class="text-small text-muted">à vendre</span>
      </span>
    </div>
  </div>
</template>

<style scoped>
.totals {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 8px;
  margin-bottom: 16px;
}

.total {
  display: grid;
  gap: 2px;
  padding: 12px;
  border: 1px solid var(--line);
  border-radius: var(--radius-control);
  background: var(--surface);
}

.total-main {
  border-color: var(--accent);
  background: var(--accent-soft);
}

.total-value {
  font-size: 28px;
  font-weight: 600;
  font-variant-numeric: tabular-nums;
}

.total-label {
  font-size: var(--text-sm);
  color: var(--ink-2);
}

.stock-row {
  grid-template-columns: 56px minmax(0, 1fr) auto;
}

.stock-row.muted {
  opacity: 0.6;
}

.row-main {
  display: grid;
  gap: 2px;
  min-width: 0;
}

.row-side {
  display: grid;
  justify-items: end;
}

.stock-count {
  font-size: 28px;
  font-weight: 600;
  color: var(--success, #107c10);
  font-variant-numeric: tabular-nums;
}

.stock-count.zero {
  color: var(--ink-3);
}

.chips {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
}

.chip {
  padding: 2px 8px;
  border-radius: 999px;
  background: var(--surface-sunken);
  font-size: var(--text-xs, 12px);
}
</style>
