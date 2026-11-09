<script setup lang="ts">
import { onBeforeUnmount, ref } from 'vue'
import SheetDialog from './SheetDialog.vue'
import AppIcon from './AppIcon.vue'

/*
 * Lecture d'un code-barres ou d'un QR avec l'appareil photo (API
 * BarcodeDetector de Chrome et Edge). Sur un appareil sans cette API, le
 * dialogue l'explique et la saisie au clavier reste possible.
 */
const props = withDefaults(defineProps<{ label: string; disabled?: boolean }>(), { disabled: false })
const emit = defineEmits<{ scanned: [value: string] }>()

interface DetectedBarcode { rawValue: string }
interface Detector { detect(source: HTMLVideoElement): Promise<DetectedBarcode[]> }
type DetectorConstructor = new (options?: { formats: string[] }) => Detector

const FORMATS = ['code_128', 'code_39', 'code_93', 'codabar', 'ean_13', 'ean_8', 'upc_a', 'upc_e', 'itf', 'qr_code', 'data_matrix', 'pdf417']

const open = ref(false)
const message = ref('')
const video = ref<HTMLVideoElement | null>(null)
let stream: MediaStream | null = null
let timer: number | undefined

function detectorClass(): DetectorConstructor | null {
  return (globalThis as unknown as { BarcodeDetector?: DetectorConstructor }).BarcodeDetector ?? null
}

async function start(): Promise<void> {
  open.value = true
  message.value = ''
  const Barcode = detectorClass()
  if (!Barcode || !navigator.mediaDevices?.getUserMedia) {
    message.value = 'Le scan n’est pas disponible sur cet appareil. Utilisez Chrome ou Edge sur téléphone, ou saisissez le numéro.'
    return
  }
  try {
    const supported = await (Barcode as unknown as { getSupportedFormats?: () => Promise<string[]> }).getSupportedFormats?.() ?? FORMATS
    const detector = new Barcode({ formats: FORMATS.filter((format) => supported.includes(format)) })
    stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false })
    if (!video.value) return
    video.value.srcObject = stream
    await video.value.play()
    message.value = 'Placez le code-barres dans le cadre.'
    timer = window.setInterval(async () => {
      if (!video.value || video.value.readyState < 2) return
      const codes = await detector.detect(video.value).catch(() => [])
      const value = codes.map((code) => code.rawValue.trim()).find((raw) => raw.length >= 4)
      if (!value) return
      emit('scanned', value.replace(/\s+/g, '').toUpperCase())
      close()
    }, 250)
  } catch {
    message.value = 'L’appareil photo n’est pas accessible. Autorisez-le dans le navigateur, ou saisissez le numéro.'
    stop()
  }
}

function stop(): void {
  if (timer !== undefined) window.clearInterval(timer)
  timer = undefined
  stream?.getTracks().forEach((track) => track.stop())
  stream = null
}

function close(): void {
  stop()
  open.value = false
}

onBeforeUnmount(stop)
</script>

<template>
  <button class="btn btn-secondary scan-button" type="button" :disabled="props.disabled" :aria-label="`Scanner : ${props.label}`" :title="`Scanner : ${props.label}`" @click="start">
    <AppIcon name="scan" />
  </button>
  <SheetDialog :open="open" :title="`Scanner : ${props.label}`" @close="close">
    <div class="scan">
      <video ref="video" class="scan-video" muted playsinline></video>
      <p class="text-small text-secondary" role="status">{{ message }}</p>
    </div>
    <template #footer>
      <button class="btn btn-secondary" type="button" @click="close">Fermer</button>
    </template>
  </SheetDialog>
</template>

<style scoped>
.scan-button {
  flex: none;
  width: 48px;
  min-width: 48px;
  padding: 0;
}

.scan {
  display: grid;
  gap: 12px;
}

.scan-video {
  width: 100%;
  max-height: 50vh;
  border-radius: var(--radius-control);
  background: #000;
  object-fit: cover;
}
</style>
