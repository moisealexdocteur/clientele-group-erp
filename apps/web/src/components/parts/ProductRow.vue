<script setup lang="ts">
import { computed } from 'vue'
import type { PartsPriceList, PartsProduct } from '../../api/types'
import { formatMoney } from '../../lib/money'
import { availableAt } from '../../lib/partsCart'
import StatusPill from '../ui/StatusPill.vue'
import ProductThumb from './ProductThumb.vue'

/*
 * Ligne d'article : photo, désignation, référence, compatibilités, prix et
 * stock. En vente, toucher la ligne ajoute l'article au panier ; ailleurs,
 * elle ouvre la fiche.
 */
const props = withDefaults(defineProps<{
  product: PartsProduct
  priceList?: PartsPriceList
  /** Emplacement dont le stock est mis en avant (le magasin vendeur). */
  siteId?: string
  /** Ligne d'action : émet « select » au lieu d'ouvrir la fiche. */
  action?: boolean
}>(), { priceList: 'retail', siteId: '', action: false })

defineEmits<{ select: [] }>()

const price = computed(() => (props.priceList === 'wholesale' ? props.product.wholesale_price_htg : props.product.retail_price_htg))
const here = computed(() => (props.siteId ? availableAt(props.product, props.siteId) : null))
const fitments = computed(() => props.product.fitments.slice(0, 2).map((fitment) => fitment.label).join(', ')
  + (props.product.fitments.length > 2 ? ` et ${props.product.fitments.length - 2} autre${props.product.fitments.length > 3 ? 's' : ''}` : ''))
</script>

<template>
  <component
    :is="action ? 'button' : 'RouterLink'"
    class="list-row product-row"
    v-bind="action ? { type: 'button', onClick: () => $emit('select') } : { to: { name: 'parts.product', params: { productId: product.id } } }"
  >
    <ProductThumb :path="product.photo_url" :alt="product.name" />
    <span class="product-main">
      <strong>{{ product.name }}</strong>
      <span class="text-muted text-small">{{ product.sku }}<template v-if="product.brand"> - {{ product.brand }}</template></span>
      <span v-if="fitments" class="text-small text-secondary">{{ fitments }}</span>
      <span class="product-tags">
        <StatusPill v-if="!product.is_active" tone="neutral" label="Retiré de la vente" />
        <StatusPill v-else-if="product.is_low" tone="warning" label="Stock bas" />
      </span>
    </span>
    <span class="product-side">
      <strong>{{ price === null ? 'Pas de prix de gros' : formatMoney(price, 'HTG') }}</strong>
      <span v-if="product.total_stock !== null" class="text-small" :class="here === 0 ? 'text-warning' : 'text-muted'">
        <template v-if="here !== null">{{ here }} ici - </template>{{ product.total_stock }} au total
      </span>
      <span v-if="action" class="add" aria-hidden="true">+</span>
    </span>
  </component>
</template>

<style scoped>
.product-row {
  grid-template-columns: 56px minmax(0, 1fr) auto;
  gap: 12px;
}

.product-main {
  display: grid;
  gap: 2px;
  min-width: 0;
}

.product-tags:empty {
  display: none;
}

.product-side {
  display: grid;
  justify-items: end;
  gap: 2px;
  text-align: right;
  font-variant-numeric: tabular-nums;
}

.text-warning {
  color: var(--warning);
}

.add {
  display: grid;
  place-items: center;
  width: 32px;
  height: 32px;
  border-radius: 50%;
  background: var(--accent);
  color: #ffffff;
  font-weight: 700;
}
</style>
