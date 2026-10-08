<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { fetchReservation, updateReservation } from '../../api/carRental'
import type { CarRentalReservation } from '../../api/types'
import { useUiStore } from '../../stores/ui'
import { useRequest } from '../../composables/useRequest'
import PageHeader from '../../components/ui/PageHeader.vue'
import InlineAlert from '../../components/ui/InlineAlert.vue'
import ReservationEditor from '../../components/rental/ReservationEditor.vue'
import { reservationValues, toUpdatePayload, type ReservationFormValues } from '../../components/rental/reservationForm'

/*
 * Modification complète d'une réservation non remise : client,
 * coordonnées, véhicule (toute catégorie), dates, lieux et conditions.
 * Un changement de véhicule applique le tarif et le dépôt de sa fiche.
 */
const props = defineProps<{ reservationId: string }>()

const router = useRouter()
const ui = useUiStore()
const loading = useRequest()
const saving = useRequest()
const reservation = ref<CarRentalReservation | null>(null)
const initial = ref<ReservationFormValues | null>(null)

onMounted(async () => {
  const result = await loading.run(() => fetchReservation(props.reservationId))
  if (!result) return
  reservation.value = result.data
  if (result.data.state !== 'reserved') {
    loading.fail('Seule une réservation qui n’a pas encore été remise peut être modifiée.')
    return
  }
  initial.value = reservationValues(result.data)
})

async function save(values: ReservationFormValues): Promise<void> {
  if (!reservation.value) return
  const current = reservation.value
  const result = await saving.run(() => updateReservation(current, toUpdatePayload(values)))
  if (!result) return
  ui.toast(result.customer_notification_sent
    ? 'Réservation mise à jour. La confirmation a été envoyée au client.'
    : 'Réservation mise à jour. Les paiements existants n’ont pas été modifiés.')
  await router.replace({ name: 'rental.reservation', params: { reservationId: props.reservationId } })
}
</script>

<template>
  <PageHeader
    :title="reservation ? `Modifier la réservation ${reservation.number}` : 'Modifier la réservation'"
    description="Les paiements déjà enregistrés ne sont pas modifiés. Les champs marqués * sont obligatoires."
    :back="{ name: 'rental.reservation', params: { reservationId } }"
    back-label="Réservation"
  />

  <InlineAlert :message="loading.error.value" />
  <div v-if="loading.busy.value" class="skeleton" style="height: 420px"></div>

  <ReservationEditor
    v-else-if="initial && reservation"
    mode="edit"
    :initial="initial"
    :current-vehicle="reservation.vehicle"
    :rate-overridden="reservation.rate_overridden"
    :busy="saving.busy.value"
    :error="saving.error.value"
    :field-errors="saving.fieldErrors.value"
    @submit="save"
  />
</template>
