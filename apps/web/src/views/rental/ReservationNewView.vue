<script setup lang="ts">
import { nextTick, ref } from 'vue'
import { useRouter } from 'vue-router'
import { createReservation } from '../../api/carRental'
import type { CarRentalReservation, RentalVehicle } from '../../api/types'
import { useSessionStore } from '../../stores/session'
import { useRequest } from '../../composables/useRequest'
import { formatMoney } from '../../lib/money'
import { formatDateTime } from '../../lib/time'
import { vehicleName } from '../../lib/text'
import PageHeader from '../../components/ui/PageHeader.vue'
import SheetDialog from '../../components/ui/SheetDialog.vue'
import ReservationEditor from '../../components/rental/ReservationEditor.vue'
import { newReservationValues, toCreatePayload, type ReservationFormValues } from '../../components/rental/reservationForm'

/*
 * Nouvelle réservation : valeurs du jour proposées, véhicules disponibles
 * affichés immédiatement, tarif repris de la fiche véhicule.
 */
const session = useSessionStore()
const router = useRouter()
const request = useRequest()

const initial = ref(newReservationValues(session.officeSiteId))
const editorKey = ref(0)
const created = ref<CarRentalReservation | null>(null)
const createdNotice = ref('')

async function save(values: ReservationFormValues, vehicle: RentalVehicle | null): Promise<void> {
  const result = await request.run(() => createReservation(toCreatePayload(values, vehicle?.category ?? 'suv')))
  if (!result) return
  created.value = result.data
  createdNotice.value = result.customer_notification_sent
    ? 'Le courriel de confirmation a été envoyé au client.'
    : 'La réservation est confirmée et journalisée.'
}

async function startAnother(): Promise<void> {
  created.value = null
  request.reset()
  initial.value = newReservationValues(session.officeSiteId)
  editorKey.value += 1
  await nextTick()
  window.scrollTo({ top: 0 })
}

async function openCreated(): Promise<void> {
  const reservation = created.value
  if (!reservation) return
  created.value = null
  await router.push({ name: 'rental.reservation', params: { reservationId: reservation.id } })
}

async function backToList(): Promise<void> {
  created.value = null
  await router.push({ name: 'rental.reservations' })
}
</script>

<template>
  <PageHeader
    title="Nouvelle réservation"
    description="Les valeurs du jour sont proposées. Les champs marqués * sont obligatoires."
    :back="{ name: 'rental.reservations' }"
    back-label="Réservations"
  />

  <ReservationEditor
    :key="editorKey"
    mode="create"
    :initial="initial"
    :busy="request.busy.value"
    :error="request.error.value"
    :field-errors="request.fieldErrors.value"
    @submit="save"
  />

  <SheetDialog :open="Boolean(created)" title="Réservation enregistrée" :description="createdNotice" @close="openCreated">
    <template v-if="created">
      <p class="display display-xl">{{ created.number }}</p>
      <dl class="facts">
        <div>
          <dt>Client</dt>
          <dd>{{ created.customer?.display_name ?? 'Non disponible' }}</dd>
        </div>
        <div>
          <dt>Véhicule</dt>
          <dd>{{ vehicleName(created.vehicle) }}</dd>
        </div>
        <div>
          <dt>Prise en charge</dt>
          <dd>{{ formatDateTime(created.pickup_at) }}</dd>
        </div>
        <div>
          <dt>Retour prévu</dt>
          <dd>{{ formatDateTime(created.due_at) }}</dd>
        </div>
        <div>
          <dt>Tarif journalier</dt>
          <dd>{{ formatMoney(created.daily_rate, created.currency) }}</dd>
        </div>
        <div>
          <dt>Dépôt minimum</dt>
          <dd>{{ formatMoney(created.minimum_security_deposit_usd, 'USD') }}</dd>
        </div>
      </dl>
    </template>
    <template #footer>
      <button class="btn btn-secondary" type="button" @click="backToList">Retour à la liste</button>
      <button class="btn btn-secondary" type="button" @click="startAnother">Nouvelle réservation</button>
      <button class="btn btn-primary" type="button" @click="openCreated">Voir la réservation</button>
    </template>
  </SheetDialog>
</template>
