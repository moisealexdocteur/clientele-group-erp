<script setup lang="ts">
import PrivateImage from '../ui/PrivateImage.vue'
import AppIcon from '../ui/AppIcon.vue'
import type { IconName } from '../ui/icons'

/* Vignette d'un article ou d'un modèle : sa photo, sinon un pictogramme neutre. */
withDefaults(defineProps<{ path: string | null; alt: string; icon?: IconName; fit?: 'cover' | 'contain' }>(), { icon: 'tag', fit: 'cover' })
</script>

<template>
  <span class="thumb" :class="`fit-${fit}`">
    <PrivateImage v-if="path" :path="path" :alt="alt">
      <template #fallback><AppIcon :name="icon" /></template>
    </PrivateImage>
    <AppIcon v-else :name="icon" />
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

.fit-contain {
  background: #fff;
}

.fit-contain :deep(img) {
  object-fit: contain;
}
</style>
