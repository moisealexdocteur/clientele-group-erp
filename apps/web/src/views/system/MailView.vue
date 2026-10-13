<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { fetchMailSettings, sendTestMail, type MailSettings } from '../../api/system'
import { useAppStore } from '../../stores/app'
import { useRequest } from '../../composables/useRequest'
import PageHeader from '../../components/ui/PageHeader.vue'
import InlineAlert from '../../components/ui/InlineAlert.vue'
import StatusPill from '../../components/ui/StatusPill.vue'

/*
 * Serveur de courriel, propriétaire seulement. L'expéditeur et l'hôte
 * masqué sont affichés ; le mot de passe reste dans l'environnement du
 * serveur et ne se modifie pas ici. Le courriel d'essai part vers
 * l'adresse du propriétaire.
 */
const app = useAppStore()
const loading = useRequest()
const sending = useRequest()

const settings = ref<MailSettings | null>(null)
const sent = ref('')

onMounted(async () => {
  const result = await loading.run(() => fetchMailSettings())
  if (result) settings.value = result.data
})

async function send(): Promise<void> {
  sent.value = ''
  const result = await sending.run(() => sendTestMail())
  if (result) sent.value = result.message
}
</script>

<template>
  <PageHeader title="Serveur de courriel" description="Expéditeur des codes de sécurité, des comptes et des courriels client. Le serveur et le mot de passe se règlent dans l’environnement du serveur." />

  <InlineAlert :message="loading.error.value" />
  <div v-if="loading.busy.value && !settings" class="skeleton" style="height: 220px"></div>

  <div v-else-if="settings" class="stack-lg">
    <section class="panel" aria-labelledby="sender-title">
      <div class="panel-header">
        <h2 id="sender-title" class="title-section">Expéditeur</h2>
        <StatusPill :tone="settings.configured ? 'success' : 'warning'" :label="settings.configured ? 'Configuré' : 'Incomplet'" />
      </div>
      <dl class="facts">
        <div><dt>Adresse</dt><dd>{{ settings.from_address ?? 'Non configurée' }}</dd></div>
        <div><dt>Nom affiché</dt><dd>{{ settings.from_name ?? 'Non configuré' }}</dd></div>
        <div v-if="settings.host"><dt>Serveur</dt><dd>{{ settings.host }}<template v-if="settings.port"> - port {{ settings.port }}</template></dd></div>
        <div v-else><dt>Envoi</dt><dd>{{ settings.mailer === 'log' ? 'Journal du serveur seulement' : settings.mailer }}</dd></div>
      </dl>
      <p v-if="!settings.configured" class="alert alert-warning">
        Le serveur de courriel n’est pas complet. Renseignez SMTP_HOST et SMTP_FROM_ADDRESS sur le serveur, puis redéployez.
      </p>
    </section>

    <section class="panel" aria-labelledby="test-title">
      <h2 id="test-title" class="title-section">Courriel d’essai</h2>
      <p class="text-secondary">Un courriel d’essai est envoyé à {{ settings.test_recipient }}.</p>
      <InlineAlert :message="sending.error.value" />
      <p v-if="sent" class="alert alert-success" role="status">{{ sent }}</p>
      <div class="btn-row">
        <button class="btn btn-primary" type="button" :disabled="sending.busy.value || !app.canReachServer" @click="send">
          {{ sending.busy.value ? 'Envoi en cours' : 'Envoyer un courriel d’essai' }}
        </button>
      </div>
    </section>
  </div>
</template>
