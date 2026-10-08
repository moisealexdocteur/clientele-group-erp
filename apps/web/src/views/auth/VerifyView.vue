<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useSessionStore } from '../../stores/session'
import { useAppStore } from '../../stores/app'
import { useRequest } from '../../composables/useRequest'
import FormField from '../../components/ui/FormField.vue'
import InlineAlert from '../../components/ui/InlineAlert.vue'

const session = useSessionStore()
const app = useAppStore()
const router = useRouter()
const route = useRoute()
const request = useRequest()
const code = ref('')

onMounted(() => {
  // Sans demande de code en cours, on revient à la première étape.
  if (!session.challengeId) void router.replace({ name: 'sign-in' })
})

async function submit(): Promise<void> {
  const done = await request.run(async () => {
    await session.verifyLogin(code.value)
    return true
  })
  if (!done) return

  const next = typeof route.query.suite === 'string' && route.query.suite.startsWith('/') ? route.query.suite : null
  if (session.companies.length === 1) {
    await session.selectCompany(session.companies[0].id)
    await router.replace(next ?? { name: 'rental.today' })
  } else {
    await router.replace({ name: 'companies', query: next ? { suite: next } : {} })
  }
}
</script>

<template>
  <header class="stack">
    <h1 class="title-page">Vérifier votre identité</h1>
    <p class="text-secondary">Saisissez le code envoyé à {{ session.pendingEmail }}. Il est valable 10 minutes.</p>
  </header>

  <form class="form" novalidate @submit.prevent="submit">
    <FormField label="Code à six chiffres" :error="request.fieldErrors.value.code" v-slot="field">
      <input
        v-model.trim="code"
        v-bind="field.attrs"
        class="input input-code"
        inputmode="numeric"
        autocomplete="one-time-code"
        pattern="[0-9]{6}"
        maxlength="6"
        required
        :disabled="request.busy.value"
      />
    </FormField>
    <InlineAlert :message="request.error.value" />
    <button class="btn btn-primary btn-block" type="submit" :disabled="request.busy.value || code.length !== 6 || !app.canReachServer">
      {{ request.busy.value ? 'Vérification' : 'Vérifier le code' }}
    </button>
    <RouterLink class="btn btn-ghost" :to="{ name: 'sign-in' }">Revenir à la connexion</RouterLink>
  </form>
</template>
