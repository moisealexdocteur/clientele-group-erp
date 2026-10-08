<script setup lang="ts">
import { computed } from 'vue'
import type { RentalVehicle } from '../../api/types'
import PrivateImage from '../ui/PrivateImage.vue'

/*
 * Vignette du véhicule, par ordre de priorité :
 * 1. la photo réelle téléversée dans la fiche ;
 * 2. la photo de référence publique choisie dans le catalogue ;
 * 3. une illustration de catégorie, annoncée comme telle.
 */
const props = defineProps<{
  vehicle: Pick<RentalVehicle, 'category' | 'photo' | 'reference_photo'>
  alt: string
  size?: 'sm' | 'lg'
}>()

const illustrations = {
  suv: '/vehicle-images/car-rental-suv.svg',
  mid_suv: '/vehicle-images/car-rental-mid-suv.svg',
  pickup: '/vehicle-images/car-rental-pickup.svg',
} as const

const referenceUrl = computed(() => props.vehicle.reference_photo?.url ?? null)
const illustration = computed(() => illustrations[props.vehicle.category])
</script>

<template>
  <span class="thumb" :class="[`thumb-${size ?? 'sm'}`, { illustration: !vehicle.photo && !referenceUrl }]">
    <PrivateImage v-if="vehicle.photo" :path="vehicle.photo.url" :alt="alt">
      <template #fallback>
        <img :src="referenceUrl ?? illustration" :alt="referenceUrl ? alt : `Illustration de catégorie - ${alt}`" />
      </template>
    </PrivateImage>
    <img v-else-if="referenceUrl" :src="referenceUrl" :alt="alt" loading="lazy" decoding="async" />
    <img v-else :src="illustration" :alt="`Illustration de catégorie - ${alt}`" loading="lazy" decoding="async" />
  </span>
</template>

<style scoped>
.thumb {
  display: block;
  overflow: hidden;
  border-radius: 4px;
  background: var(--surface-sunken);
}

.thumb :deep(img) {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.thumb.illustration :deep(img) {
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
  border-radius: 8px;
}
</style>
