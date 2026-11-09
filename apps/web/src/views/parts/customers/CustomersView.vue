<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { fetchPartsCustomers } from '../../../api/parts'
import type { PartsCustomer } from '../../../api/types'
import { useRequest } from '../../../composables/useRequest'
import { plural } from '../../../lib/text'
import PageHeader from '../../../components/ui/PageHeader.vue'
import InlineAlert from '../../../components/ui/InlineAlert.vue'

/*
 * Base clientèle d'Auto Parts. Une fiche est créée à la commande d'une moto
 * ou à une vente au comptoir avec nom et téléphone ; le même téléphone
 * retrouve toujours la même fiche. Aucune vente à crédit.
 */
const request = useRequest()
const customers = ref<PartsCustomer[]>([])
const query = ref('')

async function load(): Promise<void> {
  const result = await request.run(() => fetchPartsCustomers(query.value.trim()))
  if (result) customers.value = result.data
}

let timer: number | undefined
watch(query, () => {
  window.clearTimeout(timer)
  timer = window.setTimeout(() => void load(), 300)
})

onMounted(load)
onBeforeUnmount(() => window.clearTimeout(timer))
</script>

<template>
  <PageHeader title="Clients" description="Clients identifiés par leur téléphone, avec leurs achats." />

  <div class="stack">
    <input v-model="query" class="input" type="search" inputmode="search" placeholder="Nom, téléphone ou courriel" aria-label="Rechercher un client" autocomplete="off" />
    <InlineAlert :message="request.error.value" />
    <p class="text-muted text-small">{{ request.busy.value ? 'Chargement' : query ? plural(customers.length, 'client trouvé', 'clients trouvés') : 'Clients récents' }}</p>

    <div v-if="customers.length" class="list-card">
      <RouterLink v-for="customer in customers" :key="customer.id" class="list-row" :to="{ name: 'parts.customer', params: { customerId: customer.id } }">
        <span class="row-main">
          <strong>{{ customer.name }}</strong>
          <span class="text-small text-secondary">{{ customer.phone }}<template v-if="customer.address"> - {{ customer.address }}</template></span>
        </span>
        <span class="row-side text-small text-muted">
          {{ customer.moto_order_count ? plural(customer.moto_order_count, 'moto') : '' }}{{ customer.moto_order_count && customer.sale_count ? ' - ' : '' }}{{ customer.sale_count ? plural(customer.sale_count, 'achat') : '' }}
          <span class="chevron" aria-hidden="true"></span>
        </span>
      </RouterLink>
    </div>
    <div v-else-if="!request.busy.value" class="empty">
      <p>{{ query ? 'Aucun client ne correspond.' : 'Aucun client pour le moment. Les fiches se créent à la commande d’une moto ou à une vente avec téléphone.' }}</p>
    </div>
  </div>
</template>

<style scoped>
.row-main {
  display: grid;
  gap: 2px;
  min-width: 0;
}

.row-side {
  display: flex;
  gap: 8px;
  align-items: center;
}
</style>
