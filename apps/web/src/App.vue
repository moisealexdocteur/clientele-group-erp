<script setup lang="ts">
import { onBeforeUnmount, onMounted } from 'vue'
import { useAppStore } from './stores/app'
import ConfirmHost from './components/ui/ConfirmHost.vue'
import ToastHost from './components/ui/ToastHost.vue'

/*
 * Racine de l'application. Les écrans sont dans src/views, la navigation
 * dans src/router, l'état partagé dans src/stores.
 */
const app = useAppStore()

onMounted(() => app.start())
onBeforeUnmount(() => app.stop())
</script>

<template>
  <RouterView />
  <ConfirmHost />
  <ToastHost />
</template>
