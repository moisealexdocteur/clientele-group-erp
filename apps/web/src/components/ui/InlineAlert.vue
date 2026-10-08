<script setup lang="ts">
withDefaults(defineProps<{
  message?: string
  tone?: 'danger' | 'success' | 'info' | 'warning'
}>(), {
  message: '',
  tone: 'danger',
})
</script>

<template>
  <p v-if="message" class="alert" :class="`alert-${tone}`" :role="tone === 'danger' ? 'alert' : 'status'">
    {{ message }}
  </p>
</template>
