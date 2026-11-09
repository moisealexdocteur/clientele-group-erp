<script setup lang="ts">
import type { MotoOrder } from '../../api/types'
import { formatMoney } from '../../lib/money'
import { formatShortDate } from '../../lib/time'
import StatusPill from '../ui/StatusPill.vue'

/* Ligne de commande : client, téléphone à toucher pour appeler, modèle et moto attribuée. */
defineProps<{ order: MotoOrder }>()

const tones: Record<MotoOrder['status'], 'neutral' | 'info' | 'success' | 'warning' | 'danger'> = {
  submitted: 'warning',
  paid: 'info',
  assigned: 'success',
  delivered: 'neutral',
  cancelled: 'neutral',
}
</script>

<template>
  <div class="moto-row">
    <RouterLink class="moto-main" :to="{ name: 'parts.moto.order', params: { orderId: order.id } }">
      <span class="moto-line">
        <strong>{{ order.customer_name }}</strong>
        <StatusPill :tone="order.customs_document_pending ? 'warning' : tones[order.status]" :label="order.customs_document_pending ? 'Dédouanement à remettre' : order.status_label" />
      </span>
      <span class="text-small text-muted">
        N° {{ order.number }} - {{ order.model?.label }}<template v-if="order.preferred_color && !order.unit"> - {{ order.preferred_color }}</template>
      </span>
      <span v-if="order.unit" class="text-small text-secondary">
        {{ order.unit.color }} - châssis {{ order.unit.frame_number }} - à {{ order.unit.site.name }}
      </span>
      <span v-else-if="order.queue_position" class="text-small text-secondary">
        Rang {{ order.queue_position }} pour ce modèle - dépôt du {{ order.deposit_date ? formatShortDate(`${order.deposit_date}T12:00:00`) : '' }}
      </span>
      <span v-else class="text-small text-secondary">{{ formatMoney(order.price, order.price_currency) }} - dépôt du {{ order.deposit_date ? formatShortDate(`${order.deposit_date}T12:00:00`) : '' }}</span>
    </RouterLink>
    <a v-if="order.status === 'assigned' || order.customs_document_pending" class="btn btn-secondary call" :href="`tel:${order.customer_phone.replace(/[^+0-9]/g, '')}`" :aria-label="`Appeler ${order.customer_name}`">
      {{ order.customer_phone }}
    </a>
  </div>
</template>

<style scoped>
.moto-row {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px 12px;
  padding: 12px 16px;
  border-top: 1px solid var(--line);
}

.moto-row:first-child {
  border-top: 0;
}

.moto-main {
  display: grid;
  flex: 1 1 240px;
  gap: 2px;
  min-width: 0;
  color: inherit;
  text-decoration: none;
}

.moto-line {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 6px 8px;
}

.call {
  flex: none;
  font-variant-numeric: tabular-nums;
}
</style>
