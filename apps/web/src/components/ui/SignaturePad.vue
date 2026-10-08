<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'

/*
 * Signature au doigt ou au stylet. Le tracé est conservé en PNG
 * transparent, sans arrière-plan, pour être placé sur le contrat.
 */
const props = withDefaults(defineProps<{
  label: string
  disabled?: boolean
}>(), { disabled: false })

const emit = defineEmits<{ change: [signed: boolean] }>()

const canvas = ref<HTMLCanvasElement | null>(null)
const signed = ref(false)
let drawing = false
let last: { x: number; y: number } | null = null
let resizeObserver: ResizeObserver | null = null

function context(): CanvasRenderingContext2D | null {
  return canvas.value?.getContext('2d') ?? null
}

function setup(): void {
  const element = canvas.value
  if (!element) return
  const ratio = Math.max(1, window.devicePixelRatio || 1)
  const { width, height } = element.getBoundingClientRect()
  if (!width || !height) return
  element.width = Math.round(width * ratio)
  element.height = Math.round(height * ratio)
  const ctx = context()
  if (!ctx) return
  ctx.setTransform(ratio, 0, 0, ratio, 0, 0)
  ctx.lineCap = 'round'
  ctx.lineJoin = 'round'
  ctx.lineWidth = 2.4
  ctx.strokeStyle = '#111827'
  // Redimensionner efface le tracé : la signature doit être refaite.
  if (signed.value) {
    signed.value = false
    emit('change', false)
  }
}

function point(event: PointerEvent): { x: number; y: number } {
  const rect = (canvas.value as HTMLCanvasElement).getBoundingClientRect()
  return { x: event.clientX - rect.left, y: event.clientY - rect.top }
}

function start(event: PointerEvent): void {
  if (props.disabled || !canvas.value) return
  event.preventDefault()
  canvas.value.setPointerCapture(event.pointerId)
  drawing = true
  last = point(event)
  const ctx = context()
  if (!ctx) return
  ctx.beginPath()
  ctx.arc(last.x, last.y, 1.1, 0, Math.PI * 2)
  ctx.fillStyle = '#111827'
  ctx.fill()
}

function move(event: PointerEvent): void {
  if (!drawing || !last) return
  event.preventDefault()
  const next = point(event)
  const ctx = context()
  if (!ctx) return
  ctx.beginPath()
  ctx.moveTo(last.x, last.y)
  ctx.lineTo(next.x, next.y)
  ctx.stroke()
  last = next
  if (!signed.value) {
    signed.value = true
    emit('change', true)
  }
}

function end(): void {
  drawing = false
  last = null
}

function clear(): void {
  const element = canvas.value
  const ctx = context()
  if (!element || !ctx) return
  ctx.save()
  ctx.setTransform(1, 0, 0, 1, 0, 0)
  ctx.clearRect(0, 0, element.width, element.height)
  ctx.restore()
  signed.value = false
  emit('change', false)
}

/** Image PNG de la signature, ou null si rien n'a été tracé. */
function toBlob(): Promise<Blob | null> {
  return new Promise((resolve) => {
    if (!canvas.value || !signed.value) {
      resolve(null)
      return
    }
    canvas.value.toBlob((blob) => resolve(blob), 'image/png')
  })
}

onMounted(() => {
  setup()
  if (canvas.value && 'ResizeObserver' in window) {
    let width = canvas.value.getBoundingClientRect().width
    resizeObserver = new ResizeObserver((entries) => {
      const next = entries[0]?.contentRect.width ?? width
      if (Math.abs(next - width) > 1) {
        width = next
        setup()
      }
    })
    resizeObserver.observe(canvas.value)
  }
})

onBeforeUnmount(() => resizeObserver?.disconnect())

defineExpose({ toBlob, clear })
</script>

<template>
  <div class="signature">
    <div class="signature-head">
      <span class="field-label">{{ label }}<span class="required" aria-hidden="true">*</span></span>
      <button class="btn btn-ghost" type="button" :disabled="disabled || !signed" @click="clear">Effacer</button>
    </div>
    <div class="signature-area" :class="{ signed, disabled }">
      <canvas
        ref="canvas"
        role="img"
        :aria-label="signed ? `${label} : signée` : `${label} : signez dans ce cadre`"
        @pointerdown="start"
        @pointermove="move"
        @pointerup="end"
        @pointercancel="end"
        @pointerleave="end"
      ></canvas>
      <span v-if="!signed" class="signature-hint" aria-hidden="true">Signez ici avec le doigt</span>
      <span class="signature-line" aria-hidden="true"></span>
    </div>
  </div>
</template>

<style scoped>
.signature {
  display: grid;
  gap: 6px;
}

.signature-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}

.signature-area {
  position: relative;
  height: 180px;
  border: 1px solid var(--line-strong);
  border-radius: var(--radius-control);
  background: var(--surface);
}

.signature-area.signed {
  border-color: var(--accent);
}

.signature-area.disabled {
  opacity: 0.6;
}

canvas {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  touch-action: none;
  cursor: crosshair;
}

.signature-hint {
  position: absolute;
  inset: 0;
  display: grid;
  place-items: center;
  color: var(--ink-3, var(--ink-2));
  font-size: var(--text-sm);
  pointer-events: none;
}

.signature-line {
  position: absolute;
  left: 16px;
  right: 16px;
  bottom: 36px;
  border-bottom: 1px dashed var(--line-strong);
  pointer-events: none;
}
</style>
