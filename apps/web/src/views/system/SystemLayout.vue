<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { useSystemStore } from '../../stores/system'
import AppShell from '../../components/layout/AppShell.vue'
import type { NavItem } from '../../components/layout/nav'

const system = useSystemStore()

onMounted(() => {
  void system.ensureLoaded().catch(() => undefined)
})

const nav = computed<NavItem[]>(() => {
  const items: NavItem[] = [
    { to: { name: 'system.companies' }, label: 'Sociétés', icon: 'building', match: 'system.companies' },
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
