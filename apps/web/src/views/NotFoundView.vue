<template>
  <main class="not-found">
    <p class="display display-xxl">404</p>
    <h1 class="title-page">Page introuvable</h1>
    <p class="text-secondary">Cette adresse n’existe pas ou n’est plus disponible.</p>
    <RouterLink class="btn btn-primary" :to="{ name: 'home' }">Revenir à l’accueil</RouterLink>
  </main>
</template>

<style scoped>
.not-found {
  display: grid;
  justify-items: start;
  gap: 16px;
  max-width: 560px;
  margin: 0 auto;
  padding: 64px var(--gutter);
}
</style>
