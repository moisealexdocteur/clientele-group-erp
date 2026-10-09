<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue'
import { searchCustomers, type KnownCustomer } from '../../api/carRental'
import { formatDate } from '../../lib/time'

/*
 * Suggestions de clients connus pendant la saisie du nom, du courriel ou
 * du téléphone. Les coordonnées sont masquées : elles servent seulement à
 * reconnaître le client.
 */
const props = defineProps<{ query: string; disabled?: boolean }>()
const emit = defineEmits<{ select: [customer: KnownCustomer] }>()

const results = ref<KnownCustomer[]>([])
const dismissed = ref('')
let timer: number | undefined
let token = 0

watch(() => props.query, (value) => {
  window.clearTimeout(timer)
  const text = value.trim()
  if (props.disabled || text.length < 2 || text === dismissed.value) {
    results.value = []
    return
  }
  timer = window.setTimeout(async () => {
    const current = ++token
    try {
      const response = await searchCustomers(text)
      if (current === token) results.value = response.data
    } catch {
      if (current === token) results.value = []
    }
  }, 300)
})

onBeforeUnmount(() => window.clearTimeout(timer))

function choose(customer: KnownCustomer): void {
  results.value = []
  dismissed.value = customer.display_name
  emit('select', customer)
}

function hide(): void {
  dismissed.value = props.query.trim()
  results.value = []
}
</script>

<template>
  <div v-if="results.length" class="lookup" role="listbox" aria-label="Clients connus">
    <div class="lookup-head">
      <span class="text-small text-secondary">Clients connus</span>
      <button class="btn btn-ghost" type="button" @click="hide">Nouveau client</button>
    </div>
    <button v-for="customer in results" :key="customer.id" class="lookup-item" type="button" role="option" :aria-selected="false" @click="choose(customer)">
      <strong>{{ customer.display_name }}</strong>
      <span class="text-small text-secondary">
        {{ [customer.email_hint, customer.phone_hint].filter(Boolean).join(' - ') || 'Sans coordonnées' }}
      </span>
      <span v-if="customer.reservation_count" class="text-small text-secondary">
        {{ customer.reservation_count }} réservation{{ customer.reservation_count > 1 ? 's' : '' }}<template v-if="customer.last_pickup_at">, dernière le {{ formatDate(customer.last_pickup_at) }}</template>
      </span>
    </button>
  </div>
</template>

<style scoped>
.lookup {
  display: grid;
  border: 1px solid var(--line-strong);
  border-radius: var(--radius-control);
  background: var(--surface);
  box-shadow: var(--shadow-4);
  overflow: hidden;
}

.lookup-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  padding: 4px 4px 4px 12px;
  border-bottom: 1px solid var(--line);
}

.lookup-item {
  display: grid;
  justify-items: start;
  gap: 2px;
  min-height: 56px;
  padding: 8px 12px;
  border: 0;
  border-top: 1px solid var(--line);
  background: transparent;
  text-align: left;
  cursor: pointer;
}

.lookup-item:first-of-type {
  border-top: 0;
}

.lookup-item:hover {
  background: var(--surface-hover);
}
</style>
