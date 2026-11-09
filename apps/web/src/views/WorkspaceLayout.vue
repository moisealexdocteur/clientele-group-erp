<script setup lang="ts">
import { computed } from 'vue'
import { useSessionStore } from '../stores/session'
import AppShell from '../components/layout/AppShell.vue'
import type { NavItem } from '../components/layout/nav'

/*
 * Espace de travail de la société active. Le menu dépend de l'activité
 * (Car Rental ou Auto Parts) et ne contient que les fonctions autorisées
 * pour le rôle. La caisse est commune aux deux activités.
 */
const session = useSessionStore()

const activity = computed(() => session.context?.company.activity ?? 'car_rental')
const area = computed(() => (activity.value === 'auto_parts' ? 'Auto Parts' : 'Car Rental'))

const nav = computed<NavItem[]>(() => {
  const items: NavItem[] = []

  if (activity.value === 'auto_parts') {
    if (session.can('parts.sales.create')) {
      items.push({ to: { name: 'parts.sale' }, label: 'Vente', icon: 'cart', match: 'parts.sale' })
    } else if (session.can('parts.sales.read')) {
      items.push({ to: { name: 'parts.sales' }, label: 'Ventes', icon: 'receipt', match: 'parts.sale' })
    }
    if (session.can('parts.catalog.read')) {
      items.push({ to: { name: 'parts.products' }, label: 'Articles', icon: 'tag', match: 'parts.products' })
    }
    if (session.can('parts.stock.read')) {
      items.push({ to: { name: 'parts.stock' }, label: 'Stock', icon: 'box', match: 'parts.stock' })
    }
    if (session.can('parts.motos.read')) {
      items.push({ to: { name: 'parts.motos' }, label: 'Motos', icon: 'moto', match: 'parts.motos' })
    }
  } else {
    items.push({ to: { name: 'rental.today' }, label: 'Aujourd’hui', icon: 'today', match: 'rental.today' })
    if (session.can('rental.reservations.read')) {
      items.push({ to: { name: 'rental.reservations' }, label: 'Réservations', icon: 'list', match: 'rental.reservations' })
    }
    if (session.can('rental.calendar.read')) {
      items.push({ to: { name: 'rental.planning' }, label: 'Planning', icon: 'calendar', match: 'rental.planning' })
    }
    if (session.can('rental.vehicles.read') || session.can('rental.vehicles.manage')) {
      items.push({ to: { name: 'rental.vehicles' }, label: 'Véhicules', icon: 'car', match: 'rental.vehicles' })
    }
  }

  if (session.can('cash.sessions.operate') || session.can('cash.reports.read')) {
    items.push({ to: { name: 'cash' }, label: 'Caisse', icon: 'cash', match: 'cash' })
  }
  return items
})
</script>

<template>
  <AppShell :area="area" :nav="nav">
    <RouterView />
  </AppShell>
</template>
