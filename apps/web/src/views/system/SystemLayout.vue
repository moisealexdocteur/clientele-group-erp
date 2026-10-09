<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { useSystemStore } from '../../stores/system'
import { useSessionStore } from '../../stores/session'
import AppShell from '../../components/layout/AppShell.vue'
import type { NavItem } from '../../components/layout/nav'

const system = useSystemStore()
const session = useSessionStore()

onMounted(() => {
  if (session.isOwner) void system.ensureLoaded().catch(() => undefined)
})

const nav = computed<NavItem[]>(() => {
  const rates: NavItem = { to: { name: 'system.rates' }, label: 'Taux de change', icon: 'list', match: 'system.rates' }
  // Une personne désignée pour le taux ne voit que cet écran de la Configuration.
  if (!session.isOwner) {
    return [rates, { to: { name: 'companies' }, label: 'Activités', icon: 'list', match: 'companies' }]
  }
  const items: NavItem[] = [
    { to: { name: 'system.companies' }, label: 'Sociétés', icon: 'building', match: 'system.companies' },
    rates,
  ]
  if (system.lastCompany) {
    items.push({
      to: { name: 'system.users', params: { companyId: system.lastCompany.id } },
      label: 'Utilisateurs',
      icon: 'users',
      match: 'system.users',
    })
  }
  items.push({ to: { name: 'companies' }, label: 'Activités', icon: 'list', match: 'companies' })
  return items
})
</script>

<template>
  <AppShell area="Configuration système" :nav="nav">
    <RouterView />
  </AppShell>
</template>
