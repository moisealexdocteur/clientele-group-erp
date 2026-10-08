<script setup lang="ts">
import { useSessionStore } from '../../stores/session'

/* Bureau actif de la session, mémorisé par société sur ce poste. */
const session = useSessionStore()
const emit = defineEmits<{ change: [siteId: string] }>()

function onChange(event: Event): void {
  const siteId = (event.target as HTMLSelectElement).value
  session.setOfficeSite(siteId)
  emit('change', siteId)
}
</script>

<template>
  <label v-if="session.sites.length > 1" class="field office-picker">
    <span class="field-label">Bureau actif</span>
    <select class="select" :value="session.officeSiteId" @change="onChange">
      <option v-for="site in session.sites" :key="site.id" :value="site.id">{{ site.name }}</option>
    </select>
  </label>
  <p v-else-if="session.officeSite" class="office-single text-secondary">
    Bureau : <strong>{{ session.officeSite.name }}</strong>
  </p>
</template>

<style scoped>
.office-picker {
  max-width: 420px;
}
</style>
