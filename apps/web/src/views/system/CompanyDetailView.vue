<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { createCashRegister, createSite, updateCompany } from '../../api/system'
import { useSystemStore } from '../../stores/system'
import { useAppStore } from '../../stores/app'
import { useUiStore } from '../../stores/ui'
import { useRequest } from '../../composables/useRequest'
import { normalizeCode } from '../../lib/text'
import { CLIENTELE_CAR_RENTAL_FLEET_ADDRESS } from '../../data/clienteleFleetCatalog'
import PageHeader from '../../components/ui/PageHeader.vue'
import FormField from '../../components/ui/FormField.vue'
import InlineAlert from '../../components/ui/InlineAlert.vue'
import SheetDialog from '../../components/ui/SheetDialog.vue'
import StatusPill from '../../components/ui/StatusPill.vue'

const props = defineProps<{ companyId: string }>()

const system = useSystemStore()
const app = useAppStore()
const ui = useUiStore()
const loading = useRequest()
const siteRequest = useRequest()
const registerRequest = useRequest()
const legalRequest = useRequest()
const termsRequest = useRequest()
const termsOpen = ref(false)
const termsDraft = ref('')

const company = computed(() => system.company(props.companyId))
const siteOpen = ref(false)
const registerOpen = ref(false)
const legalOpen = ref(false)

const legalForm = reactive({
  legal_name: '',
  display_name: '',
  legal_representative: '',
  tax_identification_number: '',
  legal_address: '',
  phone_numbers: '',
})

const legalMissing = computed(() => {
  const items: string[] = []
  if (!legalForm.legal_name.trim()) items.push('La raison sociale')
  if (!legalForm.display_name.trim()) items.push('Le nom affiché')
  return items
})

const siteForm = reactive({ code: '', name: '', address: CLIENTELE_CAR_RENTAL_FLEET_ADDRESS })
const registerForm = reactive({ site_id: '', code: '', name: '' })

onMounted(() => {
  system.remember(props.companyId)
  void loading.run(() => system.load())
})

function openSite(): void {
  Object.assign(siteForm, { code: '', name: '', address: CLIENTELE_CAR_RENTAL_FLEET_ADDRESS })
  siteRequest.reset()
  siteOpen.value = true
}

function openRegister(siteId?: string): void {
  Object.assign(registerForm, { site_id: siteId ?? company.value?.sites[0]?.id ?? '', code: '', name: '' })
  registerRequest.reset()
  registerOpen.value = true
}

function openLegal(): void {
  const current = company.value
  if (!current) return
  Object.assign(legalForm, {
    legal_name: current.legal_name,
    display_name: current.display_name,
    legal_representative: current.legal_representative ?? '',
    tax_identification_number: current.tax_identification_number ?? '',
    legal_address: current.legal_address ?? '',
    phone_numbers: current.phone_numbers ?? '',
  })
  legalRequest.reset()
  legalOpen.value = true
}

async function saveLegal(): Promise<void> {
  if (legalMissing.value.length) return
  const result = await legalRequest.run(() => updateCompany(props.companyId, {
    legal_name: legalForm.legal_name.trim(),
    display_name: legalForm.display_name.trim(),
    legal_representative: legalForm.legal_representative.trim() || null,
    tax_identification_number: legalForm.tax_identification_number.trim() || null,
    legal_address: legalForm.legal_address.trim() || null,
    phone_numbers: legalForm.phone_numbers.trim() || null,
  }))
  if (!result) return
  await system.load()
  legalOpen.value = false
  ui.toast('Identité légale enregistrée.')
}

function openTerms(): void {
  termsDraft.value = company.value?.rental_contract_terms ?? ''
  termsRequest.reset()
  termsOpen.value = true
}

async function saveTerms(): Promise<void> {
  const current = company.value
  if (!current || !termsDraft.value.trim()) return
  const result = await termsRequest.run(() => updateCompany(props.companyId, {
    legal_name: current.legal_name,
    display_name: current.display_name,
    legal_representative: current.legal_representative ?? null,
    tax_identification_number: current.tax_identification_number ?? null,
    legal_address: current.legal_address ?? null,
    phone_numbers: current.phone_numbers ?? null,
    rental_contract_terms: termsDraft.value.trim(),
  }))
  if (!result) return
  await system.load()
  termsOpen.value = false
  ui.toast('Conditions du contrat enregistrées. Elles s’appliquent aux prochaines remises.')
}

async function saveSite(): Promise<void> {
  siteForm.code = normalizeCode(siteForm.code)
  const result = await siteRequest.run(() => createSite(props.companyId, { ...siteForm }))
  if (!result) return
  await system.load()
  siteOpen.value = false
  ui.toast('Adresse créée.')
}

async function saveRegister(): Promise<void> {
  registerForm.code = normalizeCode(registerForm.code)
  const result = await registerRequest.run(() => createCashRegister(props.companyId, { ...registerForm }))
  if (!result) return
  await system.load()
  registerOpen.value = false
  ui.toast('Caisse créée.')
}
</script>

<template>
  <PageHeader
    :title="company?.display_name ?? 'Société'"
    :description="company ? `${company.legal_name} - ${company.code}` : undefined"
    :back="{ name: 'system.companies' }"
    back-label="Sociétés"
  >
    <template #actions>
      <RouterLink class="btn btn-secondary" :to="{ name: 'system.users', params: { companyId } }">Utilisateurs</RouterLink>
      <button class="btn btn-primary" type="button" :disabled="!company" @click="openSite">Ajouter une adresse</button>
    </template>
  </PageHeader>

  <InlineAlert :message="loading.error.value" />
  <div v-if="!company && loading.busy.value" class="skeleton" style="height: 240px"></div>

  <div v-else-if="company" class="stack-lg">
    <dl class="panel facts">
      <div>
        <dt>Devise de base</dt>
        <dd>{{ company.base_currency }}</dd>
      </div>
      <div>
        <dt>Fuseau horaire</dt>
        <dd>{{ company.timezone_label }}</dd>
      </div>
      <div>
        <dt>État</dt>
        <dd><StatusPill :tone="company.is_active ? 'success' : 'neutral'" :label="company.is_active ? 'Active' : 'Inactive'" /></dd>
      </div>
    </dl>

    <section class="panel" aria-labelledby="legal-title">
      <div class="panel-header">
        <div>
          <h2 id="legal-title" class="title-section">Identité légale</h2>
          <p class="text-secondary text-small">Reprise en en-tête des contrats et des factures.</p>
        </div>
        <button class="btn btn-ghost" type="button" :disabled="!app.canReachServer" @click="openLegal">Modifier</button>
      </div>
      <dl class="facts">
        <div>
          <dt>Raison sociale</dt>
          <dd>{{ company.legal_name }}</dd>
        </div>
        <div>
          <dt>Représentant légal</dt>
          <dd>{{ company.legal_representative || 'Non renseigné' }}</dd>
        </div>
        <div>
          <dt>NIF</dt>
          <dd>{{ company.tax_identification_number || 'Non renseigné' }}</dd>
        </div>
        <div>
          <dt>Adresse du siège</dt>
          <dd>{{ company.legal_address || 'Non renseignée' }}</dd>
        </div>
        <div>
          <dt>Téléphones</dt>
          <dd>{{ company.phone_numbers || 'Non renseignés' }}</dd>
        </div>
      </dl>
    </section>

    <section class="panel" aria-labelledby="terms-title">
      <div class="panel-header">
        <div>
          <h2 id="terms-title" class="title-section">Conditions du contrat de location</h2>
          <p class="text-secondary text-small">Imprimées dans chaque contrat. Un contrat déjà signé conserve sa version.</p>
        </div>
        <button class="btn btn-ghost" type="button" :disabled="!app.canReachServer" @click="openTerms">Modifier</button>
      </div>
      <p v-if="!company.rental_contract_terms" class="alert alert-warning">
        Non configurées. Aucune location ne peut être remise tant que les articles du contrat ne sont pas saisis.
      </p>
      <div v-else class="terms-preview">{{ company.rental_contract_terms }}</div>
    </section>

    <section class="stack" aria-labelledby="sites-title">
      <h2 id="sites-title" class="title-section">Adresses et caisses</h2>

      <div v-if="!company.sites.length" class="empty">
        <p>Aucune adresse créée pour cette société.</p>
        <button class="btn btn-primary" type="button" @click="openSite">Ajouter une adresse</button>
      </div>

      <article v-for="site in company.sites" :key="site.id" class="panel">
        <div class="panel-header">
          <div>
            <h3 class="title-section">{{ site.name }}</h3>
            <p class="text-muted text-small">{{ site.code }} - {{ site.address }}</p>
          </div>
          <StatusPill v-if="!site.is_active" tone="neutral" label="Inactive" />
        </div>
        <ul v-if="site.cash_registers.length" class="registers">
          <li v-for="register in site.cash_registers" :key="register.id">
            <strong>{{ register.name }}</strong>
            <span class="text-muted text-small">{{ register.code }}</span>
          </li>
        </ul>
        <p v-else class="text-muted text-small">Aucune caisse pour cette adresse.</p>
        <button class="btn btn-secondary" type="button" @click="openRegister(site.id)">Ajouter une caisse</button>
      </article>
    </section>
  </div>

  <SheetDialog :open="legalOpen" title="Identité légale" description="Ces informations figurent sur les contrats. Les champs marqués * sont obligatoires." :locked="legalRequest.busy.value" @close="legalOpen = false">
    <form id="legal-form" class="form" novalidate @submit.prevent="saveLegal">
      <FormField label="Raison sociale" required :error="legalRequest.fieldErrors.value.legal_name" v-slot="field">
        <input v-model="legalForm.legal_name" v-bind="field.attrs" class="input" maxlength="255" />
      </FormField>
      <FormField label="Nom affiché" required :error="legalRequest.fieldErrors.value.display_name" v-slot="field">
        <input v-model="legalForm.display_name" v-bind="field.attrs" class="input" maxlength="255" />
      </FormField>
      <FormField label="Représentant légal" :error="legalRequest.fieldErrors.value.legal_representative" v-slot="field">
        <input v-model="legalForm.legal_representative" v-bind="field.attrs" class="input" maxlength="160" autocomplete="off" />
      </FormField>
      <FormField label="NIF" help="Numéro d’identification fiscale de la société." :error="legalRequest.fieldErrors.value.tax_identification_number" v-slot="field">
        <input v-model="legalForm.tax_identification_number" v-bind="field.attrs" class="input mono" maxlength="64" autocomplete="off" />
      </FormField>
      <FormField label="Adresse du siège" :error="legalRequest.fieldErrors.value.legal_address" v-slot="field">
        <textarea v-model="legalForm.legal_address" v-bind="field.attrs" class="textarea" rows="3" maxlength="1000"></textarea>
      </FormField>
      <FormField label="Téléphones" help="Séparez plusieurs numéros par une barre oblique." :error="legalRequest.fieldErrors.value.phone_numbers" v-slot="field">
        <input v-model="legalForm.phone_numbers" v-bind="field.attrs" class="input" type="tel" maxlength="160" autocomplete="off" />
      </FormField>
      <div v-if="legalMissing.length" class="missing" role="status">
        <strong>À compléter</strong>
        <ul>
          <li v-for="item in legalMissing" :key="item">{{ item }}</li>
        </ul>
      </div>
      <InlineAlert :message="legalRequest.error.value" />
    </form>
    <template #footer>
      <button class="btn btn-secondary" type="button" :disabled="legalRequest.busy.value" @click="legalOpen = false">Annuler</button>
      <button class="btn btn-primary" type="submit" form="legal-form" :disabled="legalRequest.busy.value || legalMissing.length > 0 || !app.canReachServer">
        {{ legalRequest.busy.value ? 'Enregistrement' : 'Enregistrer' }}
      </button>
    </template>
  </SheetDialog>

  <SheetDialog :open="termsOpen" title="Conditions du contrat de location" description="Saisissez les articles du contrat papier, un article par paragraphe. Une ligne qui commence par « Article » est imprimée en gras." :locked="termsRequest.busy.value" @close="termsOpen = false">
    <form id="terms-form" class="form" novalidate @submit.prevent="saveTerms">
      <FormField label="Articles du contrat" required :help="`${termsDraft.length.toLocaleString('fr-FR')} caractères sur 40 000`" :error="termsRequest.fieldErrors.value.rental_contract_terms" v-slot="field">
        <textarea v-model="termsDraft" v-bind="field.attrs" class="textarea terms-input" rows="16" maxlength="40000" placeholder="Article 1 - ..."></textarea>
      </FormField>
      <InlineAlert :message="termsRequest.error.value" />
    </form>
    <template #footer>
      <button class="btn btn-secondary" type="button" :disabled="termsRequest.busy.value" @click="termsOpen = false">Annuler</button>
      <button class="btn btn-primary" type="submit" form="terms-form" :disabled="termsRequest.busy.value || !termsDraft.trim() || !app.canReachServer">
        {{ termsRequest.busy.value ? 'Enregistrement' : 'Enregistrer' }}
      </button>
    </template>
  </SheetDialog>

  <SheetDialog :open="siteOpen" title="Ajouter une adresse" :locked="siteRequest.busy.value" @close="siteOpen = false">
    <form id="site-form" class="form" novalidate @submit.prevent="saveSite">
      <FormField label="Code d’adresse" help="Les espaces et accents sont convertis automatiquement." :error="siteRequest.fieldErrors.value.code" v-slot="field">
        <input v-model.trim="siteForm.code" v-bind="field.attrs" class="input" maxlength="32" autocapitalize="characters" placeholder="Ex. CAP-01" required @blur="siteForm.code = normalizeCode(siteForm.code)" />
      </FormField>
      <FormField label="Nom de l’adresse" :error="siteRequest.fieldErrors.value.name" v-slot="field">
        <input v-model.trim="siteForm.name" v-bind="field.attrs" class="input" maxlength="255" required />
      </FormField>
      <FormField label="Adresse complète" :error="siteRequest.fieldErrors.value.address" v-slot="field">
        <textarea v-model.trim="siteForm.address" v-bind="field.attrs" class="textarea" rows="3" maxlength="1000" required></textarea>
      </FormField>
      <InlineAlert :message="siteRequest.error.value" />
    </form>
    <template #footer>
      <button class="btn btn-secondary" type="button" :disabled="siteRequest.busy.value" @click="siteOpen = false">Annuler</button>
      <button class="btn btn-primary" type="submit" form="site-form" :disabled="siteRequest.busy.value || !app.canReachServer">
        {{ siteRequest.busy.value ? 'Enregistrement' : 'Créer l’adresse' }}
      </button>
    </template>
  </SheetDialog>

  <SheetDialog :open="registerOpen" title="Ajouter une caisse" :locked="registerRequest.busy.value" @close="registerOpen = false">
    <form id="register-form" class="form" novalidate @submit.prevent="saveRegister">
      <FormField label="Adresse" :error="registerRequest.fieldErrors.value.site_id" v-slot="field">
        <select v-model="registerForm.site_id" v-bind="field.attrs" class="select" required>
          <option v-for="site in company?.sites ?? []" :key="site.id" :value="site.id">{{ site.name }}</option>
        </select>
      </FormField>
      <FormField label="Code de caisse" help="Les espaces et accents sont convertis automatiquement." :error="registerRequest.fieldErrors.value.code" v-slot="field">
        <input v-model.trim="registerForm.code" v-bind="field.attrs" class="input" maxlength="32" autocapitalize="characters" placeholder="Ex. POS-01" required @blur="registerForm.code = normalizeCode(registerForm.code)" />
      </FormField>
      <FormField label="Nom de la caisse" :error="registerRequest.fieldErrors.value.name" v-slot="field">
        <input v-model.trim="registerForm.name" v-bind="field.attrs" class="input" maxlength="255" required />
      </FormField>
      <InlineAlert :message="registerRequest.error.value" />
    </form>
    <template #footer>
      <button class="btn btn-secondary" type="button" :disabled="registerRequest.busy.value" @click="registerOpen = false">Annuler</button>
      <button class="btn btn-primary" type="submit" form="register-form" :disabled="registerRequest.busy.value || !registerForm.site_id || !app.canReachServer">
        {{ registerRequest.busy.value ? 'Enregistrement' : 'Créer la caisse' }}
      </button>
    </template>
  </SheetDialog>
</template>

<style scoped>
.terms-preview {
  max-height: 240px;
  overflow-y: auto;
  padding: 12px 14px;
  border-radius: var(--radius-control);
  background: var(--surface-sunken);
  font-size: var(--text-sm);
  line-height: 1.5;
  white-space: pre-wrap;
}

.terms-input {
  min-height: 320px;
  font-size: var(--text-sm);
  line-height: 1.5;
}

.registers {
  display: grid;
  gap: 8px;
}

.registers li {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 12px;
  padding: 12px 14px;
  border-radius: var(--radius-control);
  background: var(--surface-sunken);
}
</style>
