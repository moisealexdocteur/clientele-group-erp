<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue'
import { uploadFile, type FilePurpose, type UploadedFileRef } from '../../api/carRental'
import { errorMessage } from '../../api/client'

/*
 * Ajout d'une photo ou d'un fichier, envoyé immédiatement au serveur.
 * « Prendre une photo » ouvre l'appareil photo du téléphone ou de la
 * tablette ; « Choisir un fichier » ouvre la galerie ou le disque.
 */
const props = withDefaults(defineProps<{
  purpose: FilePurpose
  siteId: string
  label: string
  accept?: string
  help?: string
  error?: string
  disabled?: boolean
}>(), {
  accept: 'image/jpeg,image/png,image/webp',
  help: '',
  error: '',
  disabled: false,
})

const emit = defineEmits<{
  uploaded: [file: UploadedFileRef]
  cleared: []
}>()

const busy = ref(false)
const uploadError = ref('')
const preview = ref('')
const fileName = ref('')
const done = ref(false)
const cameraInput = ref<HTMLInputElement | null>(null)
const fileInput = ref<HTMLInputElement | null>(null)

const acceptsPdf = computed(() => props.accept.includes('pdf'))

async function onSelected(event: Event): Promise<void> {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  input.value = ''
  if (!file) return

  if (file.size > 10 * 1024 * 1024) {
    uploadError.value = 'Le fichier dépasse 10 Mo.'
    return
  }

  busy.value = true
  uploadError.value = ''
  done.value = false
  resetPreview()
  fileName.value = file.type === 'application/pdf' ? 'Document PDF' : ''
  if (file.type.startsWith('image/')) preview.value = URL.createObjectURL(file)

  try {
    const result = await uploadFile(props.purpose, props.siteId, file)
    done.value = true
    emit('uploaded', result.data)
  } catch (error) {
    uploadError.value = errorMessage(error)
    resetPreview()
  } finally {
    busy.value = false
  }
}

function clear(): void {
  resetPreview()
  fileName.value = ''
  done.value = false
  emit('cleared')
}

function resetPreview(): void {
  if (preview.value) URL.revokeObjectURL(preview.value)
  preview.value = ''
}

onBeforeUnmount(resetPreview)
</script>

<template>
  <div class="field capture">
    <span class="field-label">{{ label }}</span>

    <div v-if="preview || fileName" class="capture-preview">
      <img v-if="preview" :src="preview" alt="Aperçu du fichier ajouté" />
      <span v-else class="capture-file">{{ fileName }}</span>
      <span class="capture-state" :class="{ ok: done }">
        {{ busy ? 'Envoi en cours' : done ? 'Ajouté' : '' }}
      </span>
      <button v-if="!busy" class="btn btn-ghost" type="button" :disabled="disabled" @click="clear">Retirer</button>
    </div>

    <div v-else class="btn-row">
      <button class="btn btn-secondary" type="button" :disabled="disabled || busy" @click="cameraInput?.click()">
        Prendre une photo
      </button>
      <button class="btn btn-secondary" type="button" :disabled="disabled || busy" @click="fileInput?.click()">
        {{ acceptsPdf ? 'Choisir un fichier' : 'Choisir une image' }}
      </button>
    </div>

    <input ref="cameraInput" class="visually-hidden" type="file" accept="image/*" capture="environment" tabindex="-1" @change="onSelected" />
    <input ref="fileInput" class="visually-hidden" type="file" :accept="accept" tabindex="-1" @change="onSelected" />

    <span v-if="help" class="field-help">{{ help }}</span>
    <span v-if="uploadError || error" class="field-error" role="alert">{{ uploadError || error }}</span>
  </div>
</template>

<style scoped>
.capture-preview {
  display: grid;
  grid-template-columns: 96px minmax(0, 1fr) auto;
  align-items: center;
  gap: 12px;
  padding: 8px;
  border: 1px solid var(--line-strong);
  border-radius: var(--radius-control);
  background: var(--surface);
}

.capture-preview img,
.capture-file {
  width: 96px;
  height: 72px;
  border-radius: 2px;
  object-fit: cover;
  background: var(--surface-sunken);
}

.capture-file {
  display: grid;
  place-items: center;
  font-size: var(--text-xs);
  font-weight: 600;
}

.capture-state {
  font-size: var(--text-sm);
  color: var(--ink-3);
}

.capture-state.ok {
  color: var(--success);
  font-weight: 600;
}
</style>
