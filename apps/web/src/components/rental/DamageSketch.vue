<script setup lang="ts">
import { ref } from 'vue'
import {
  clampMark,
  damageKindLabels,
  SKETCH_BODY,
  SKETCH_DETAILS,
  SKETCH_HEIGHT,
  SKETCH_WHEELS,
  SKETCH_WIDTH,
  type DamageKind,
  type DamageMark,
} from '../../lib/damageSketch'

/*
 * Croquis tactile : touchez la silhouette à l'endroit du dommage. Chaque
 * marque est numérotée et peut recevoir une précision ou être retirée.
 */
const props = withDefaults(defineProps<{
  modelValue: DamageMark[]
  readonly?: boolean
  /** Marques du départ, affichées en gris pour comparaison au retour. */
  reference?: DamageMark[]
}>(), { readonly: false, reference: () => [] })

const emit = defineEmits<{ 'update:modelValue': [marks: DamageMark[]] }>()

const kind = ref<DamageKind>('scratch')
const svg = ref<SVGSVGElement | null>(null)
const kinds = Object.keys(damageKindLabels) as DamageKind[]

function add(event: PointerEvent): void {
  if (props.readonly || !svg.value || props.modelValue.length >= 30) return
  const rect = svg.value.getBoundingClientRect()
  const mark: DamageMark = {
    x: clampMark((event.clientX - rect.left) / rect.width),
    y: clampMark((event.clientY - rect.top) / rect.height),
    kind: kind.value,
    note: null,
  }
  emit('update:modelValue', [...props.modelValue, mark])
}

function remove(index: number): void {
  emit('update:modelValue', props.modelValue.filter((_, position) => position !== index))
}

function setNote(index: number, note: string): void {
  emit('update:modelValue', props.modelValue.map((mark, position) => (position === index ? { ...mark, note: note.trim() ? note : null } : mark)))
}
</script>

<template>
  <div class="sketch">
    <div v-if="!readonly" class="segmented kinds" role="group" aria-label="Type de dommage">
      <button v-for="item in kinds" :key="item" type="button" :aria-pressed="kind === item" @click="kind = item">
        {{ damageKindLabels[item] }}
      </button>
    </div>

    <div class="sketch-canvas">
      <span class="sketch-side">Avant</span>
      <svg
        ref="svg"
        :viewBox="`0 0 ${SKETCH_WIDTH} ${SKETCH_HEIGHT}`"
        role="img"
        :aria-label="readonly ? 'Croquis des dommages' : 'Croquis des dommages : touchez la silhouette pour ajouter une marque'"
        :class="{ editable: !readonly }"
        @pointerdown="add"
      >
        <path :d="SKETCH_BODY" class="body" />
        <path v-for="(path, index) in SKETCH_DETAILS" :key="`d${index}`" :d="path" class="detail" />
        <path v-for="(path, index) in SKETCH_WHEELS" :key="`w${index}`" :d="path" class="wheel" />
        <g v-for="(mark, index) in reference" :key="`r${index}`" class="mark reference">
          <circle :cx="mark.x * SKETCH_WIDTH" :cy="mark.y * SKETCH_HEIGHT" r="7" />
        </g>
        <g v-for="(mark, index) in modelValue" :key="`m${index}`" class="mark">
          <circle :cx="mark.x * SKETCH_WIDTH" :cy="mark.y * SKETCH_HEIGHT" r="9" />
          <text :x="mark.x * SKETCH_WIDTH" :y="mark.y * SKETCH_HEIGHT + 3.5" text-anchor="middle">{{ index + 1 }}</text>
        </g>
      </svg>
      <span class="sketch-side">Arrière</span>
    </div>

    <p v-if="!readonly" class="text-secondary text-small">
      Choisissez le type, puis touchez la silhouette.<template v-if="reference.length"> Les points gris sont les dommages notés au départ.</template>
    </p>

    <ol v-if="modelValue.length" class="marks">
      <li v-for="(mark, index) in modelValue" :key="index">
        <span class="mark-number">{{ index + 1 }}</span>
        <strong>{{ damageKindLabels[mark.kind] }}</strong>
        <input
          v-if="!readonly"
          class="input"
          :value="mark.note ?? ''"
          maxlength="120"
          placeholder="Précision facultative"
          :aria-label="`Précision pour la marque ${index + 1}`"
          @change="setNote(index, ($event.target as HTMLInputElement).value)"
        />
        <span v-else class="text-secondary">{{ mark.note }}</span>
        <button v-if="!readonly" class="btn btn-ghost" type="button" @click="remove(index)">Retirer</button>
      </li>
    </ol>
    <p v-else-if="readonly" class="text-muted text-small">Aucun dommage marqué.</p>
  </div>
</template>

<style scoped>
.kinds {
  display: grid;
  grid-template-columns: repeat(5, minmax(0, 1fr));
}

.kinds button {
  padding-inline: 4px;
}

.sketch {
  display: grid;
  gap: 10px;
}

.sketch-canvas {
  display: grid;
  justify-items: center;
  gap: 4px;
  padding: 8px;
  border: 1px solid var(--line-strong);
  border-radius: var(--radius-control);
  background: var(--surface);
}

.sketch-side {
  font-size: var(--text-xs);
  color: var(--ink-2);
}

svg {
  width: min(100%, 220px);
  height: auto;
  touch-action: manipulation;
}

svg.editable {
  cursor: crosshair;
}

.body {
  fill: var(--surface-sunken);
  stroke: var(--ink-2);
  stroke-width: 2;
}

.detail {
  fill: none;
  stroke: var(--ink-2);
  stroke-width: 1.2;
}

.wheel {
  fill: var(--ink-2);
}

.mark circle {
  fill: #c50f1f;
  stroke: #ffffff;
  stroke-width: 2;
}

.mark text {
  fill: #ffffff;
  font-size: 10px;
  font-weight: 700;
  pointer-events: none;
}

.mark.reference circle {
  fill: #8a8886;
}

.marks {
  display: grid;
  gap: 6px;
}

.marks li {
  display: grid;
  grid-template-columns: 24px auto minmax(0, 1fr) auto;
  align-items: center;
  gap: 8px;
}

.mark-number {
  display: grid;
  place-items: center;
  width: 24px;
  height: 24px;
  border-radius: 50%;
  background: #c50f1f;
  color: #ffffff;
  font-size: var(--text-xs);
  font-weight: 700;
}
</style>
