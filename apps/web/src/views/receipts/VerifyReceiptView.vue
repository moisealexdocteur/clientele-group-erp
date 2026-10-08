<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { verifyReceipt, type ReceiptVerification } from '../../api/finance'
import { formatMoney } from '../../lib/money'
import { formatDateTime } from '../../lib/time'

/*
 * Page publique ouverte par le QR d'un reçu. Elle confirme seulement la
 * société, le numéro, la date et le montant : aucune donnée client.
 */
const props = defineProps<{ code: string; number: string }>()
const route = useRoute()

const result = ref<ReceiptVerification | null>(null)
const busy = ref(true)

onMounted(async () => {
  result.value = await verifyReceipt(props.code, props.number, String(route.query.s ?? ''))
  busy.value = false
})
</script>

<template>
  <main class="verify">
    <img src="/brand/clientele-group-logo.webp" alt="Clientèle Group" width="160" height="50" />
    <section class="panel verify-card" aria-live="polite">
      <h1 class="title-section">Vérification d’un reçu</h1>
      <div v-if="busy" class="skeleton" style="height: 120px"></div>
      <template v-else-if="result?.valid">
        <p class="alert alert-success">Reçu authentique</p>
        <dl class="facts">
          <div><dt>Société</dt><dd>{{ result.company }}</dd></div>
          <div><dt>Numéro</dt><dd class="mono">{{ result.number }}</dd></div>
          <div v-if="result.issued_at"><dt>Date</dt><dd>{{ formatDateTime(result.issued_at) }}</dd></div>
          <div v-if="result.amount && result.currency"><dt>Montant</dt><dd>{{ formatMoney(result.amount, result.currency) }}</dd></div>
          <div v-if="result.status && result.status !== 'approved'"><dt>État</dt><dd>Ce paiement a été annulé ou refusé.</dd></div>
        </dl>
      </template>
      <p v-else class="alert alert-danger">{{ result?.message ?? 'Ce reçu ne peut pas être vérifié.' }}</p>
    </section>
  </main>
</template>

<style scoped>
.verify {
  display: grid;
  justify-items: center;
  align-content: start;
  gap: 24px;
  min-height: 100vh;
  padding: 32px 16px;
  background: var(--surface-sunken);
}

.verify-card {
  display: grid;
  gap: 12px;
  width: min(100%, 440px);
}
</style>
