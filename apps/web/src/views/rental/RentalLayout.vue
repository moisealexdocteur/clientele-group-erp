<script setup lang="ts">
import { computed } from 'vue'
import { useSessionStore } from '../../stores/session'
import AppShell from '../../components/layout/AppShell.vue'
import type { NavItem } from '../../components/layout/nav'

const session = useSessionStore()

/* Le menu ne contient que les fonctions autorisées pour le rôle actif. */
const nav = computed<NavItem[]>(() => {
  const items: NavItem[] = [
    { to: { name: 'rental.today' }, label: 'Aujourd’hui', icon: 'today', match: 'rental.today' },
  ]
  if (session.can('rental.reservations.read')) {
    items.push({ to: { name: 'rental.reservations' }, label: 'Réservations', icon: 'list', match: 'rental.reservations' })
  }
  if (session.can('rental.calendar.read')) {
    items.push({ to: { name: 'rental.planning' }, label: 'Planning', icon: 'calendar', match: 'rental.planning' })
  }
  if (session.can('rental.vehicles.read') || session.can('rental.vehicles.manage')) {
    items.push({ to: { name: 'rental.vehicles' }, label: 'Véhicules', icon: 'car', match: 'rental.vehicles' })
  }
  return items
})
</script>

<template>
  <AppShell area="Car Rental" :nav="nav">
    <RouterView />
  </AppShell>
</template>
