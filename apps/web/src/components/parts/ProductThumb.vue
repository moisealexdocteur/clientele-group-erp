<script setup lang="ts">
import PrivateImage from '../ui/PrivateImage.vue'
import AppIcon from '../ui/AppIcon.vue'

/* Vignette d'un article : sa photo réelle, sinon un pictogramme neutre. */
defineProps<{ path: string | null; alt: string }>()
</script>

<template>
  <span class="thumb">
    <PrivateImage v-if="path" :path="path" :alt="alt">
      <template #fallback><AppIcon name="tag" /></template>
    </PrivateImage>
    <AppIcon v-else name="tag" />
  </span>
</template>

<style scoped>
.thumb {
  display: grid;
  place-items: center;
  width: 56px;
  height: 56px;
  overflow: hidden;
  border-radius: var(--radius-control);
  background: var(--surface-sunken);
  color: var(--ink-3);
}

.thumb :deep(img) {
  width: 100%;
  height: 100%;
  object-fit: cover;
}
</style>
