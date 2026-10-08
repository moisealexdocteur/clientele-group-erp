<script setup lang="ts">
import type { RentalCategory } from '../../api/types'

/*
 * Vignette du véhicule. Utilise la photo réellement choisie dans la fiche ;
 * sinon une illustration de catégorie, présentée comme telle.
 */
const props = defineProps<{
  photoUrl?: string | null
  category: RentalCategory
  alt: string
  size?: 'sm' | 'lg'
}>()

const illustrations: Record<RentalCategory, string> = {
  suv: '/vehicle-images/car-rental-suv.svg',
  mid_suv: '/vehicle-images/car-rental-mid-suv.svg',
  pickup: '/vehicle-images/car-rental-pickup.svg',
}
</script>

<template>
  <span class="thumb" :class="[`thumb-${props.size ?? 'sm'}`, { illustration: !photoUrl }]">
    <img
      :src="photoUrl || illustrations[category]"
      :alt="photoUrl ? alt : `Illustration de catégorie - ${alt}`"
      loading="lazy"
      decoding="async"
    />
  </span>
</template>

<style scoped>
.thumb {
  display: block;
  overflow: hidden;
  border-radius: 12px;
  background: var(--surface-sunken);
}

.thumb img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.thumb.illustration img {
  object-fit: contain;
  padding: 8%;
}

.thumb-sm {
  width: 72px;
  height: 54px;
}

.thumb-lg {
  width: 100%;
  aspect-ratio: 16 / 9;
  border-radius: var(--radius-panel);
}
</style>
