<script setup lang="ts">
import { onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useSessionStore } from '../stores/session'
import { useRequest } from '../composables/useRequest'
import { roleLabel } from '../lib/labels'
import InlineAlert from '../components/ui/InlineAlert.vue'

const session = useSessionStore()
const router = useRouter()
const route = useRoute()
const request = useRequest()

onMounted(() => {
  void request.run(() => session.loadMe())
})

async function choose(companyId: string): Promise<void> {
  const done = await request.run(async () => {
    await session.selectCompany(companyId)
    return true
  })
  if (!done) return
  const next = typeof route.query.suite === 'string' && route.query.suite.startsWith('/location') ? route.query.suite : null
  await router.replace(next ?? { name: 'rental.today' })
}

async function signOut(): Promise<void> {
  await session.logout()
  await router.replace({ name: 'sign-in' })
}
</script>

<template>
  <main class="choice">
    <img class="choice-logo" src="/brand/clientele-group-logo.webp" alt="Clientèle Group" width="128" height="40" />
    <header class="stack">
      <p class="text-secondary">Connecté : {{ session.user?.name }}</p>
      <h1 class="title-page">Choisir une société</h1>
      <p class="text-secondary">Les données et les fonctions disponibles dépendent de cette sélection.</p>
    </header>

    <InlineAlert :message="request.error.value" />

    <div v-if="session.companies.length" class="list">
      <button
        v-for="company in session.companies"
        :key="company.id"
        class="list-row choice-row"
        type="button"
        :disabled="request.busy.value"
        @click="choose(company.id)"
      >
        <span class="choice-text">
          <strong class="choice-name">{{ company.name }}</strong>
          <span class="text-muted text-small">{{ company.code }} - {{ roleLabel(company.role_key) }}</span>
        </span>
        <span class="chevron" aria-hidden="true"></span>
      </button>
    </div>
    <p v-else-if="!request.busy.value" class="empty">
      {{ session.isOwner ? 'Aucune société n’est configurée.' : 'Aucune société n’est attribuée à votre compte. Contactez le propriétaire du système.' }}
    </p>

    <div class="stack">
      <RouterLink v-if="session.isOwner" class="btn btn-secondary btn-block" :to="{ name: 'system.companies' }">
        Ouvrir la configuration système
      </RouterLink>
      <button class="btn btn-ghost" type="button" @click="signOut">Fermer la session</button>
    </div>
  </main>
</template>

<style scoped>
.choice {
  display: grid;
  gap: 24px;
  width: 100%;
  max-width: 560px;
  margin: 0 auto;
  padding: calc(28px + env(safe-area-inset-top)) var(--gutter) 40px;
}

.choice-logo {
  width: 120px;
  height: auto;
}

.choice-row {
  min-height: 84px;
}

.choice-text {
  display: grid;
  gap: 4px;
}

.choice-name {
  font-size: var(--text-xl);
  font-weight: 760;
  font-stretch: 112%;
  line-height: 1.1;
}
</style>
