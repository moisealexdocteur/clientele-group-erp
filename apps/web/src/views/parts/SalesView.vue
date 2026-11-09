<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { fetchSales } from '../../api/parts'
import type { PartsSalesDayResponse } from '../../api/types'
import { useRequest } from '../../composables/useRequest'
import { useSessionStore } from '../../stores/session'
import { useAppStore } from '../../stores/app'
import { formatMoney } from '../../lib/money'
import { formatTime, toDateInput } from '../../lib/time'
import { plural } from '../../lib/text'
import PageHeader from '../../components/ui/PageHeader.vue'
import InlineAlert from '../../components/ui/InlineAlert.vue'
import StatusPill from '../../components/ui/StatusPill.vue'

/* Ventes du jour (heure de Cap-Haïtien) dans les magasins autorisés, sans nom de client. */
const session = useSessionStore()
const app = useAppStore()
const request = useRequest()

const today = toDateInput(app.now)
const filters = reactive({ date: today, site_id: '' })
const day = ref<PartsSalesDayResponse | null>(null)
const stores = computed(() => session.sites.filter((site) => site.kind === 'store'))

async function load(): Promise<void> {
  const result = await request.run(() => fetchSales({ date: filters.date, site_id: filters.site_id || undefined }))
  if (result) day.value = result
}

watch(() => [filters.date, filters.site_id], load)
onMounted(load)
</script>

<template>
  <PageHeader title="Ventes du jour">
    <template v-if="session.can('parts.sales.create')" #actions>
      <RouterLink class="btn btn-primary" :to="{ name: 'parts.sale' }">Nouvelle vente</RouterLink>
    </template>
  </PageHeader>

  <div class="stack">
    <div class="filters">
      <input v-model="filters.date" class="input" type="date" :max="today" aria-label="Jour" />
      <select v-if="stores.length > 1" v-model="filters.site_id" class="select" aria-label="Magasin">
        <option value="">Tous les magasins autorisés</option>
        <option v-for="site in stores" :key="site.id" :value="site.id">{{ site.name }}</option>
      </select>
    </div>

    <dl v-if="day" class="panel facts">
      <div><dt>Ventes</dt><dd>{{ day.meta.count }}</dd></div>
      <div><dt>Articles vendus</dt><dd>{{ day.meta.item_count }}</dd></div>
      <div><dt>Total encaissé</dt><dd>{{ formatMoney(day.meta.total, 'HTG') }}</dd></div>
    </dl>

    <InlineAlert :message="request.error.value" />
    <div v-if="request.busy.value && !day" class="skeleton" style="height: 240px"></div>
    <div v-else-if="day?.data.length" class="list">
      <RouterLink v-for="sale in day.data" :key="sale.id" class="list-row" :to="{ name: 'parts.receipt', params: { saleId: sale.id } }">
        <span class="sale-main">
          <strong>Reçu {{ sale.number }}</strong>
          <span class="text-small text-muted">
            {{ sale.sold_at ? formatTime(sale.sold_at) : '' }} - {{ plural(sale.item_count, 'article') }} - {{ sale.site.name }}<template v-if="sale.seller"> - {{ sale.seller }}</template>
          </span>
        </span>
        <span class="sale-side">
          <strong>{{ formatMoney(sale.total, 'HTG') }}</strong>
          <StatusPill v-if="sale.price_list === 'wholesale'" tone="info" :label="sale.price_list_label" />
        </span>
      </RouterLink>
    </div>
    <div v-else-if="!request.busy.value" class="empty">
      <p>Aucune vente ce jour.</p>
    </div>
  </div>
</template>

<style scoped>
.filters {
  display: grid;
  gap: 10px;
}

@media (min-width: 720px) {
  .filters {
    grid-template-columns: 220px minmax(0, 1fr);
  }
}

.sale-main {
  display: grid;
  gap: 2px;
  min-width: 0;
}

.sale-side {
  display: grid;
  justify-items: end;
  gap: 4px;
  font-variant-numeric: tabular-nums;
}
</style>
