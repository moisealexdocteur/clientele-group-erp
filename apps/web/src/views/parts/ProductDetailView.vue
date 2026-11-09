<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { fetchProduct, updateProduct, updateProductActive, updateProductPhoto } from '../../api/parts'
import type { PartsProduct, PartsProductInput, PartsStockAction as StockAction } from '../../api/types'
import { useRequest } from '../../composables/useRequest'
import { useSessionStore } from '../../stores/session'
import { useAppStore } from '../../stores/app'
import { useUiStore } from '../../stores/ui'
import { formatMoney } from '../../lib/money'
import { formatDateTime } from '../../lib/time'
import { siteKindLabels } from '../../lib/labels'
import { cleanProductInput, productInputFrom } from '../../lib/partsForm'
import PageHeader from '../../components/ui/PageHeader.vue'
import InlineAlert from '../../components/ui/InlineAlert.vue'
import StatusPill from '../../components/ui/StatusPill.vue'
import SheetDialog from '../../components/ui/SheetDialog.vue'
import ToggleSwitch from '../../components/ui/ToggleSwitch.vue'
import FileCapture from '../../components/ui/FileCapture.vue'
import ProductFields from '../../components/parts/ProductFields.vue'
import ProductThumb from '../../components/parts/ProductThumb.vue'
import FitmentsDialog from '../../components/parts/FitmentsDialog.vue'
import StockActionDialog from '../../components/parts/StockActionDialog.vue'

/*
 * Fiche article : prix, compatibilités, stock par emplacement et derniers
 * mouvements. Chaque modification est une tâche séparée, dans son dialogue.
 */
const props = defineProps<{ productId: string }>()

const session = useSessionStore()
const app = useAppStore()
const ui = useUiStore()
const loading = useRequest()
const action = useRequest()

const product = ref<PartsProduct | null>(null)
const task = ref<null | 'edit' | 'photo' | 'fitments'>(null)
const stockAction = ref<StockAction | null>(null)
const editForm = ref<PartsProductInput | null>(null)

const canManage = computed(() => session.can('parts.catalog.manage'))
const canStock = computed(() => session.can('parts.stock.manage'))
const uploadSiteId = computed(() => session.officeSiteId || session.sites[0]?.id || '')

async function load(): Promise<void> {
  const result = await loading.run(() => fetchProduct(props.productId))
  if (result) product.value = result.data
}

onMounted(load)

function apply(next: PartsProduct): void {
  product.value = { ...next, movements: product.value?.movements }
}

function openEdit(): void {
  if (!product.value) return
  editForm.value = productInputFrom(product.value)
  action.reset()
  task.value = 'edit'
}

async function saveEdit(): Promise<void> {
  if (!editForm.value) return
  const form = editForm.value
  const result = await action.run(() => updateProduct(props.productId, cleanProductInput(form)))
  if (!result) return
  apply(result.data)
  task.value = null
  ui.toast('Article enregistré.')
}

async function setActive(next: boolean): Promise<void> {
  if (!product.value || product.value.is_active === next) return
  const confirmed = await ui.confirm({
    title: next ? 'Remettre en vente' : 'Retirer de la vente',
    message: next
      ? `${product.value.sku} sera de nouveau proposé à la vente.`
      : `${product.value.sku} ne sera plus proposé à la vente. Son stock et son historique sont conservés.`,
    confirmLabel: next ? 'Remettre en vente' : 'Retirer',
  })
  if (!confirmed) return
  const result = await action.run(() => updateProductActive(props.productId, next))
  if (!result) {
    ui.toast(action.error.value, 'danger')
    return
  }
  apply(result.data)
  ui.toast(next ? 'Article remis en vente.' : 'Article retiré de la vente.')
}

async function savePhoto(fileId: string | null): Promise<void> {
  const result = await action.run(() => updateProductPhoto(props.productId, fileId))
  if (!result) {
    ui.toast(action.error.value, 'danger')
    return
  }
  apply(result.data)
  task.value = null
  ui.toast(fileId ? 'Photo enregistrée.' : 'Photo retirée.')
}

async function stockDone(message: string): Promise<void> {
  stockAction.value = null
  ui.toast(message)
  await load()
}
</script>

<template>
  <PageHeader
    :title="product?.name ?? 'Article'"
    :description="product ? `${product.sku}${product.brand ? ` - ${product.brand}` : ''}${product.category ? ` - ${product.category}` : ''}` : undefined"
    :back="{ name: 'parts.products' }"
    back-label="Articles"
  >
    <template v-if="product" #before-title>
      <StatusPill v-if="!product.is_active" tone="neutral" label="Retiré de la vente" />
      <StatusPill v-else-if="product.is_low" tone="warning" label="Stock bas" />
    </template>
    <template v-if="product && canManage" #actions>
      <button class="btn btn-secondary" type="button" :disabled="!app.canReachServer" @click="openEdit">Modifier</button>
    </template>
  </PageHeader>

  <InlineAlert :message="loading.error.value" />
  <div v-if="loading.busy.value && !product" class="skeleton" style="height: 320px"></div>

  <div v-else-if="product" class="detail-grid">
    <div class="stack-lg">
      <section class="panel" aria-labelledby="price-title">
        <div class="panel-header">
          <h2 id="price-title" class="title-section">Prix</h2>
          <ToggleSwitch
            v-if="canManage"
            :model-value="product.is_active"
            on-label="En vente"
            off-label="Retiré"
            :disabled="action.busy.value || !app.canReachServer"
            @update:model-value="setActive"
          />
        </div>
        <dl class="facts">
          <div><dt>Prix de détail</dt><dd>{{ formatMoney(product.retail_price_htg, 'HTG') }} par {{ product.unit }}</dd></div>
          <div v-if="product.wholesale_price_htg !== null"><dt>Prix de gros</dt><dd>{{ formatMoney(product.wholesale_price_htg, 'HTG') }}</dd></div>
          <div v-else-if="!product.has_wholesale_price"><dt>Prix de gros</dt><dd class="text-muted">Aucun</dd></div>
          <div v-if="product.barcode"><dt>Code-barres</dt><dd>{{ product.barcode }}</dd></div>
          <div><dt>Seuil de réapprovisionnement</dt><dd>{{ product.reorder_level }}</dd></div>
        </dl>
        <p v-if="product.notes" class="text-secondary text-small">{{ product.notes }}</p>
      </section>

      <section v-if="product.stock" class="panel" aria-labelledby="stock-title">
        <div class="panel-header">
          <h2 id="stock-title" class="title-section">Stock</h2>
          <strong>{{ product.total_stock }} au total</strong>
        </div>
        <p v-if="!product.stock.length" class="text-secondary">Aucun magasin ni entrepôt n’est accessible.</p>
        <dl v-else class="facts">
          <div v-for="entry in product.stock" :key="entry.site_id">
            <dt>{{ entry.site_name }} <span class="text-small">({{ siteKindLabels[entry.kind] }})</span></dt>
            <dd>{{ entry.quantity }}</dd>
          </div>
        </dl>
        <div v-if="canStock && product.stock.length" class="btn-row">
          <button class="btn btn-primary" type="button" :disabled="!app.canReachServer" @click="stockAction = 'receipt'">Entrée de stock</button>
          <button v-if="product.stock.length > 1" class="btn btn-secondary" type="button" :disabled="!app.canReachServer" @click="stockAction = 'transfer'">Transférer</button>
          <button class="btn btn-ghost" type="button" :disabled="!app.canReachServer" @click="stockAction = 'adjust'">Ajuster</button>
        </div>
      </section>

      <section v-if="product.movements?.length" class="panel" aria-labelledby="movements-title">
        <h2 id="movements-title" class="title-section">Derniers mouvements</h2>
        <ul class="movements">
          <li v-for="movement in product.movements" :key="movement.id">
            <div>
              <strong>{{ movement.label }}</strong>
              <span class="text-small text-muted">
                {{ movement.occurred_at ? formatDateTime(movement.occurred_at) : '' }} - {{ movement.site.name }}<template v-if="movement.reference"> - {{ movement.reference }}</template><template v-if="movement.recorded_by"> - {{ movement.recorded_by }}</template>
              </span>
              <span v-if="movement.note" class="text-small text-secondary">{{ movement.note }}</span>
            </div>
            <span class="movement-qty">
              <strong :class="movement.quantity < 0 ? 'negative' : 'positive'">{{ movement.quantity > 0 ? '+' : '' }}{{ movement.quantity }}</strong>
              <span class="text-small text-muted">solde {{ movement.balance_after }}</span>
            </span>
          </li>
        </ul>
      </section>
    </div>

    <div class="stack-lg">
      <section class="panel" aria-labelledby="photo-title">
        <div class="panel-header">
          <h2 id="photo-title" class="title-section">Photo</h2>
          <button v-if="canManage" class="btn btn-ghost" type="button" :disabled="!app.canReachServer" @click="task = 'photo'; action.reset()">{{ product.photo_url ? 'Remplacer' : 'Ajouter' }}</button>
        </div>
        <div class="photo">
          <ProductThumb :path="product.photo_url" :alt="product.name" />
        </div>
        <button v-if="canManage && product.photo_url" class="btn btn-ghost" type="button" :disabled="action.busy.value" @click="savePhoto(null)">Retirer la photo</button>
      </section>

      <section class="panel" aria-labelledby="fit-title">
        <div class="panel-header">
          <h2 id="fit-title" class="title-section">Compatibilités</h2>
          <button v-if="canManage" class="btn btn-ghost" type="button" :disabled="!app.canReachServer" @click="task = 'fitments'">Modifier</button>
        </div>
        <ul v-if="product.fitments.length" class="fitments">
          <li v-for="fitment in product.fitments" :key="fitment.id">{{ fitment.label }}</li>
        </ul>
        <p v-else class="text-secondary text-small">Aucune compatibilité saisie.</p>
      </section>
    </div>
  </div>

  <SheetDialog :open="task === 'edit'" title="Modifier l’article" description="Un changement de prix s’applique aux prochaines ventes et est journalisé." :locked="action.busy.value" @close="task = null">
    <form v-if="editForm" id="edit-form" class="form" novalidate @submit.prevent="saveEdit">
      <ProductFields v-model="editForm" :errors="action.fieldErrors.value" :disabled="action.busy.value" @clear="action.clearField" />
      <InlineAlert :message="action.error.value" />
    </form>
    <template #footer>
      <button class="btn btn-secondary" type="button" :disabled="action.busy.value" @click="task = null">Annuler</button>
      <button class="btn btn-primary" type="submit" form="edit-form" :disabled="action.busy.value || !app.canReachServer">
        {{ action.busy.value ? 'Enregistrement' : 'Enregistrer' }}
      </button>
    </template>
  </SheetDialog>

  <SheetDialog :open="task === 'photo'" title="Photo de l’article" description="Une photo réelle de la pièce aide le client à la reconnaître au comptoir." :locked="action.busy.value" @close="task = null">
    <div class="form">
      <FileCapture
        purpose="parts_product_photo"
        :site-id="uploadSiteId"
        label="Photo"
        help="JPEG ou PNG, 10 Mo au plus."
        :disabled="action.busy.value"
        @uploaded="(file) => savePhoto(file.id)"
      />
      <InlineAlert :message="action.error.value" />
    </div>
    <template #footer>
      <button class="btn btn-secondary" type="button" :disabled="action.busy.value" @click="task = null">Fermer</button>
    </template>
  </SheetDialog>

  <FitmentsDialog v-if="product" :product="product" :open="task === 'fitments'" @close="task = null" @saved="(next) => { apply(next); task = null; ui.toast('Compatibilités enregistrées.') }" />
  <StockActionDialog v-if="product" :product="product" :action="stockAction" @close="stockAction = null" @done="stockDone" />
</template>

<style scoped>
.detail-grid {
  display: grid;
  gap: 24px;
  align-items: start;
}

@media (min-width: 1100px) {
  .detail-grid {
    grid-template-columns: minmax(0, 1fr) 340px;
  }
}

.photo :deep(.thumb) {
  width: 100%;
  height: auto;
  aspect-ratio: 4 / 3;
}

.photo :deep(.icon) {
  width: 48px;
  height: 48px;
}

.fitments {
  display: grid;
  gap: 6px;
  padding-left: 18px;
}

.movements {
  display: grid;
  list-style: none;
}

.movements li {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  padding: 10px 0;
  border-top: 1px solid var(--line);
}

.movements li:first-child {
  border-top: 0;
}

.movements li > div {
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
