<script setup lang="ts">
/* Icônes de navigation, tracées au trait pour rester lisibles à 24 px. */
import type { IconName } from './icons'

defineProps<{ name: IconName }>()
</script>

<template>
  <svg class="icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
    <template v-if="name === 'today'">
      <circle cx="12" cy="12" r="8.5" />
      <path d="M12 7.5V12l3 2" />
    </template>
    <template v-else-if="name === 'list'">
      <path d="M8 6.5h11M8 12h11M8 17.5h11" />
      <path d="M4.5 6.5h.01M4.5 12h.01M4.5 17.5h.01" stroke-width="3" />
    </template>
    <template v-else-if="name === 'calendar'">
      <rect x="3.5" y="5" width="17" height="15" rx="2.5" />
      <path d="M3.5 10h17M8 3v4M16 3v4" />
    </template>
    <template v-else-if="name === 'car'">
      <path d="M4 15.5V12l2-5h12l2 5v3.5" />
      <path d="M3 15.5h18v2.5H3z" />
      <circle cx="7.5" cy="18.5" r="1.5" />
      <circle cx="16.5" cy="18.5" r="1.5" />
      <path d="M4.5 12h15" />
    </template>
    <template v-else-if="name === 'building'">
      <path d="M4 20.5V5.5l8-2.5v17.5M12 8.5l8 2.5v9.5M2.5 20.5h19" />
      <path d="M7.5 9h1M7.5 13h1M7.5 17h1M15.5 13.5h1M15.5 17h1" />
    </template>
    <template v-else-if="name === 'users'">
      <circle cx="9" cy="8.5" r="3.5" />
      <path d="M2.5 20c.6-3.4 3.3-5.5 6.5-5.5s5.9 2.1 6.5 5.5" />
      <path d="M16 5.2a3.5 3.5 0 0 1 0 6.6M18 14.8c1.8.8 3 2.6 3.5 5.2" />
    </template>
    <template v-else-if="name === 'plus'">
      <path d="M12 5v14M5 12h14" />
    </template>
    <template v-else-if="name === 'cash'">
      <rect x="2.5" y="6" width="19" height="12" rx="2" />
      <circle cx="12" cy="12" r="2.75" />
      <path d="M6 9.5v5M18 9.5v5" />
    </template>
    <template v-else-if="name === 'search'">
      <circle cx="10.5" cy="10.5" r="6.5" />
      <path d="M15.5 15.5 20 20" />
    </template>
  </svg>
</template>

<style scoped>
.icon {
  width: 24px;
  height: 24px;
  flex: none;
}
</style>
