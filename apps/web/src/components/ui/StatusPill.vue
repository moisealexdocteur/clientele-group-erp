<script setup lang="ts">
import type { Tone } from '../../lib/labels'

defineProps<{
  tone: Tone
  label: string
}>()
</script>

<template>
  <span class="pill" :class="`pill-${tone}`">{{ label }}</span>
</template>
