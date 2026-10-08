<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useSessionStore } from '../../stores/session'
import { useAppStore } from '../../stores/app'
import { useRequest } from '../../composables/useRequest'
import FormField from '../../components/ui/FormField.vue'
import InlineAlert from '../../components/ui/InlineAlert.vue'

const session = useSessionStore()
const app = useAppStore()
const router = useRouter()
const request = useRequest()
const email = ref(session.pendingEmail)

async function submit(): Promise<void> {
  const message = await request.run(() => session.requestPasswordReset(email.value.trim().toLowerCase()))
  if (message !== undefined) await router.push({ name: 'password.reset', query: { info: message } })
}
</script>

<template>
  <header class="stack">
    <h1 class="title-page">Recevoir un code</h1>
    <p class="text-secondary">Un code sera envoyé si cette adresse correspond à un compte actif.</p>
  </header>

  <form class="form" novalidate @submit.prevent="submit">
    <FormField label="Adresse courriel personnelle" :error="request.fieldErrors.value.email" v-slot="field">
      <input v-model.trim="email" v-bind="field.attrs" class="input" type="email" inputmode="email" autocomplete="username" required :disabled="request.busy.value" />
    </FormField>
    <InlineAlert :message="request.error.value" />
    <button class="btn btn-primary btn-block" type="submit" :disabled="request.busy.value || !email || !app.canReachServer">
      {{ request.busy.value ? 'Envoi' : 'Envoyer le code' }}
    </button>
    <RouterLink class="btn btn-ghost" :to="{ name: 'sign-in' }">Revenir à la connexion</RouterLink>
  </form>
</template>
