<script setup lang="ts">
import type { RouteLocationRaw } from 'vue-router'

/*
 * En-tête d'écran : retour éventuel, titre de tâche (2 à 5 mots),
 * une phrase utile, puis l'action principale de la page.
 */
defineProps<{
  title: string
  description?: string
  back?: RouteLocationRaw
  backLabel?: string
}>()
</script>

<template>
  <header class="page-header">
    <RouterLink v-if="back" :to="back" class="page-back">
      <span aria-hidden="true" class="page-back-arrow"></span>{{ backLabel ?? 'Retour' }}
    </RouterLink>
    <div class="page-header-main">
      <div class="page-header-text">
        <slot name="before-title" />
        <h1 class="title-page">{{ title }}</h1>
        <p v-if="description" class="text-secondary">{{ description }}</p>
      </div>
      <div v-if="$slots.actions" class="page-header-actions">
        <slot name="actions" />
      </div>
    </div>
  </header>
</template>

<style scoped>
.page-header {
  display: grid;
  gap: 10px;
  margin-bottom: 20px;
}

.page-back {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  justify-self: start;
  min-height: 40px;
  margin-left: -4px;
  padding: 0 8px 0 4px;
  border-radius: 4px;
  color: var(--accent);
  font-weight: 600;
  text-decoration: none;
}

.page-back:hover {
  background: var(--accent-soft);
}

.page-back-arrow {
  width: 9px;
  height: 9px;
  border-left: 2.5px solid currentColor;
  border-bottom: 2.5px solid currentColor;
  transform: rotate(45deg);
}

.page-header-main {
  display: grid;
  gap: 16px;
}

.page-header-text {
  display: grid;
  gap: 8px;
  max-width: 64ch;
}

.page-header-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
}

@media (min-width: 720px) {
  .page-header-main {
    grid-template-columns: minmax(0, 1fr) auto;
    align-items: end;
  }
}
</style>
