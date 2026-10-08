<script setup lang="ts">
import { ref, watch } from 'vue'
import { privateFileUrl } from '../../api/client'

/*
 * Image d'un fichier privé : elle est lue avec la session courante, car
 * une balise <img> ne peut pas transmettre le jeton d'accès.
 */
const props = defineProps<{
  path: string
  alt: string
}>()

const src = ref('')
const failed = ref(false)

watch(
  () => props.path,
  async (path) => {
    src.value = ''
    failed.value = false
    try {
      src.value = await privateFileUrl(path)
    } catch {
      failed.value = true
    }
  },
  { immediate: true },
)
</script>

<template>
  <img v-if="src" :src="src" :alt="alt" decoding="async" />
  <slot v-else-if="failed" name="fallback" />
  <span v-else class="private-image-loading" aria-hidden="true"></span>
</template>

<style scoped>
.private-image-loading {
  display: block;
  width: 100%;
  height: 100%;
  background: var(--surface-sunken);
}
</style>
