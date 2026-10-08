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

const email = ref(session.pendingEmail)
const password = ref('')
const notice = ref('')

onMounted(() => {
  notice.value = session.takeFlashMessage()
})

async function submit(): Promise<void> {
  notice.value = ''
  const done = await request.run(async () => {
    await session.login(email.value.trim().toLowerCase(), password.value)
    return true
  })
  password.value = ''
  if (done) await router.push({ name: 'sign-in.verify', query: route.query })
}
</script>

<template>
  <header class="stack">
    <h1 class="title-page">Se connecter</h1>
    <p class="text-secondary">Utilisez votre courriel personnel. Un code de vérification vous sera envoyé.</p>
  </header>

  <InlineAlert :message="notice" tone="info" />

  <form class="form" novalidate @submit.prevent="submit">
    <FormField label="Adresse courriel personnelle" :error="request.fieldErrors.value.email" v-slot="field">
      <input v-model.trim="email" v-bind="field.attrs" class="input" type="email" inputmode="email" autocomplete="username" required :disabled="request.busy.value" />
    </FormField>
    <FormField label="Mot de passe" :error="request.fieldErrors.value.password" v-slot="field">
      <input v-model="password" v-bind="field.attrs" class="input" type="password" autocomplete="current-password" required :disabled="request.busy.value" />
    </FormField>
    <InlineAlert :message="request.error.value" />
    <button class="btn btn-primary btn-block" type="submit" :disabled="request.busy.value || !email || !password || !app.canReachServer">
      {{ request.busy.value ? 'Vérification' : 'Continuer' }}
    </button>
    <RouterLink class="btn btn-ghost" :to="{ name: 'password.request' }">J’ai oublié mon mot de passe</RouterLink>
  </form>
</template>
