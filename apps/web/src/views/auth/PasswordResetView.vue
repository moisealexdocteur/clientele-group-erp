<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useSessionStore } from '../../stores/session'
import { useAppStore } from '../../stores/app'
import { useRequest } from '../../composables/useRequest'
import { isStrongPassword, PASSWORD_RULE } from '../../lib/text'
import FormField from '../../components/ui/FormField.vue'
import InlineAlert from '../../components/ui/InlineAlert.vue'

const session = useSessionStore()
const app = useAppStore()
const router = useRouter()
const route = useRoute()
const request = useRequest()

const code = ref('')
const password = ref('')
const confirmation = ref('')
const info = typeof route.query.info === 'string' ? route.query.info : ''

onMounted(() => {
  if (!session.challengeId) void router.replace({ name: 'password.request' })
})

async function submit(): Promise<void> {
  if (!isStrongPassword(password.value)) {
    request.fail('Vérifiez les champs signalés.', { password: PASSWORD_RULE })
    return
  }
  if (password.value !== confirmation.value) {
    request.fail('Vérifiez les champs signalés.', { password_confirmation: 'Les deux mots de passe ne correspondent pas.' })
    return
  }

  const message = await request.run(() => session.resetPassword(code.value, password.value, confirmation.value))
  password.value = ''
  confirmation.value = ''
  if (message !== undefined) {
    session.setFlashMessage(message)
    await router.replace({ name: 'sign-in' })
  }
}
</script>

<template>
  <header class="stack">
    <h1 class="title-page">Nouveau mot de passe</h1>
    <p class="text-secondary">{{ info || 'Saisissez le code reçu par courriel.' }}</p>
  </header>

  <form class="form" novalidate @submit.prevent="submit">
    <FormField label="Code à six chiffres" :error="request.fieldErrors.value.code" v-slot="field">
      <input v-model.trim="code" v-bind="field.attrs" class="input input-code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required :disabled="request.busy.value" />
    </FormField>
    <FormField label="Nouveau mot de passe" :help="PASSWORD_RULE" :error="request.fieldErrors.value.password" v-slot="field">
      <input v-model="password" v-bind="field.attrs" class="input" type="password" autocomplete="new-password" required :disabled="request.busy.value" @input="request.clearField('password')" />
    </FormField>
    <FormField label="Confirmer le nouveau mot de passe" :error="request.fieldErrors.value.password_confirmation" v-slot="field">
      <input v-model="confirmation" v-bind="field.attrs" class="input" type="password" autocomplete="new-password" required :disabled="request.busy.value" @input="request.clearField('password_confirmation')" />
    </FormField>
    <InlineAlert :message="request.error.value" />
    <button class="btn btn-primary btn-block" type="submit" :disabled="request.busy.value || code.length !== 6 || !app.canReachServer">
      {{ request.busy.value ? 'Enregistrement' : 'Réinitialiser le mot de passe' }}
    </button>
    <RouterLink class="btn btn-ghost" :to="{ name: 'sign-in' }">Revenir à la connexion</RouterLink>
  </form>
</template>
