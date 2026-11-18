<script setup lang="ts">
/**
 * 計算用の書き込み欄（数学の例題の出題中に、問題の下の余白へ手書きで計算する）。
 * 保存はしない（その出題の間だけ。前へ／次へで戻っても同じカードの書き込みは残る）。
 * 道具: ペン（黒・青・赤）／消しゴム（線ごと）／戻す／全部消す／欄を広げる。
 * Apple Pencil・マウスは常に書ける。指は既定で書けるが、切り替えで「指はスクロール」にできる（端末に記憶）。
 * Pencil で書いた直後（1.2 秒）の指のタッチと、接触の大きいタッチ（手のひら）は無視する。
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { strokeHits } from '@/lib/inkErase'
import { drawStroke } from '@/lib/inkRender'
import { haptic } from '@/lib/native'
import type { MistakeStroke } from '@/types'

const props = defineProps<{
  /** 書き込みを保持する鍵（カードごと）。変わると表示を切り替える */
  padKey: string
}>()

/** 出題中の書き込み（カードごと）。ページを離れても再表示できるようにモジュールで持つ */
const store: Map<string, { strokes: MistakeStroke[]; height: number }> = ((window as unknown as { __scratchStore?: Map<string, { strokes: MistakeStroke[]; height: number }> }).__scratchStore ??= new Map())

const BASE_H = 260
const STEP_H = 220
const FINGER_KEY = 'sm_scratch_finger'
const COLORS = [
  { key: '#1c2024', label: '黒' },
  { key: '#2f5fc4', label: '青' },
  { key: '#cf5563', label: '赤' },
]

const wrap = ref<HTMLElement | null>(null)
const canvas = ref<HTMLCanvasElement | null>(null)
const strokes = ref<MistakeStroke[]>([])
const height = ref(BASE_H)
const tool = ref<'pen' | 'eraser'>('pen')
const color = ref(COLORS[0]!.key)
const finger = ref(localStorage.getItem(FINGER_KEY) !== 'off')
const collapsed = ref(false)
const undone: MistakeStroke[] = []

function loadKey() {
  const s = store.get(props.padKey)
  strokes.value = s ? s.strokes : []
  height.value = s ? s.height : BASE_H
  undone.length = 0
  requestAnimationFrame(redraw)
}
function persist() {
  store.set(props.padKey, { strokes: strokes.value, height: height.value })
}
watch(() => props.padKey, loadKey)

// ---- 描画 ----
let cssW = 0
function resize() {
  const c = canvas.value
  const w = wrap.value
  if (!c || !w) return
  cssW = Math.max(100, Math.floor(w.clientWidth))
  const dpr = Math.min(3, window.devicePixelRatio || 1)
  c.width = Math.round(cssW * dpr)
  c.height = Math.round(height.value * dpr)
  c.style.height = `${height.value}px`
  redraw()
}
function redraw() {
  const c = canvas.value
  if (!c) return
  const ctx = c.getContext('2d')
  if (!ctx) return
  const dpr = c.width / Math.max(1, cssW)
  ctx.setTransform(dpr, 0, 0, dpr, 0, 0)
  ctx.clearRect(0, 0, cssW, height.value)
  // 方眼（10mm 相当の薄い線）
  ctx.save()
  ctx.strokeStyle = '#e6e9ef'
  ctx.lineWidth = 1
  const g = 28
  ctx.beginPath()
  for (let x = g; x < cssW; x += g) {
    ctx.moveTo(x + 0.5, 0)
    ctx.lineTo(x + 0.5, height.value)
  }
  for (let y = g; y < height.value; y += g) {
    ctx.moveTo(0, y + 0.5)
    ctx.lineTo(cssW, y + 0.5)
  }
  ctx.stroke()
  ctx.restore()
  for (const st of strokes.value) drawStroke(ctx, st)
  if (live) drawStroke(ctx, live)
}
let ro: ResizeObserver | null = null
onMounted(() => {
  loadKey()
  resize()
  if (typeof ResizeObserver !== 'undefined' && wrap.value) {
    ro = new ResizeObserver(() => resize())
    ro.observe(wrap.value)
  }
})
onBeforeUnmount(() => ro?.disconnect())
watch(height, () => {
  resize()
  persist()
})
watch(collapsed, (v) => {
  if (!v) requestAnimationFrame(resize)
})

// ---- 入力 ----
let live: MistakeStroke | null = null
let activeId: number | null = null
let lastPenAt = 0
function pos(e: PointerEvent): [number, number] {
  const r = canvas.value!.getBoundingClientRect()
  return [e.clientX - r.left, e.clientY - r.top]
}
function accept(e: PointerEvent): boolean {
  if (e.pointerType === 'pen') {
    lastPenAt = performance.now()
    return true
  }
  if (e.pointerType === 'touch') {
    if (!finger.value) return false
    if (performance.now() - lastPenAt < 1200) return false
    if (e.width >= 30 || e.height >= 30) return false // 手のひら
    return true
  }
  return e.button === 0 || e.buttons === 1
}
function down(e: PointerEvent) {
  if (activeId !== null || !accept(e)) return
  e.preventDefault()
  activeId = e.pointerId
  canvas.value?.setPointerCapture(e.pointerId)
  const [x, y] = pos(e)
  if (tool.value === 'eraser') {
    eraseAt(x, y)
    return
  }
  live = { color: color.value, width: e.pointerType === 'pen' ? 2.4 : 2.8, kind: 'pen', points: [x, y] }
  redraw()
}
function move(e: PointerEvent) {
  if (e.pointerId !== activeId) return
  e.preventDefault()
  const pts: [number, number][] = []
  const evs = typeof e.getCoalescedEvents === 'function' ? e.getCoalescedEvents() : []
  for (const ce of evs.length ? evs : [e]) pts.push(pos(ce as PointerEvent))
  if (tool.value === 'eraser') {
    for (const [x, y] of pts) eraseAt(x, y)
    return
  }
  if (!live) return
  for (const [x, y] of pts) {
    const n = live.points.length
    if (n >= 2 && Math.abs(live.points[n - 2]! - x) < 0.7 && Math.abs(live.points[n - 1]! - y) < 0.7) continue
    live.points.push(x, y)
  }
  redraw()
}
function up(e: PointerEvent) {
  if (e.pointerId !== activeId) return
  activeId = null
  try {
    canvas.value?.releasePointerCapture(e.pointerId)
  } catch {
    // ignore
  }
  if (live) {
    strokes.value = [...strokes.value, live]
    live = null
    undone.length = 0
    persist()
    redraw()
  }
}
function eraseAt(x: number, y: number) {
  const r = 10
  const keep = strokes.value.filter((s) => !strokeHits(s.points, x, y, r))
  if (keep.length !== strokes.value.length) {
    strokes.value = keep
    persist()
    redraw()
  }
}
function undo() {
  const s = strokes.value
  if (!s.length) return
  undone.push(s[s.length - 1]!)
  strokes.value = s.slice(0, -1)
  persist()
  redraw()
  haptic()
}
function redo() {
  const s = undone.pop()
  if (!s) return
  strokes.value = [...strokes.value, s]
  persist()
  redraw()
}
function clearAll() {
  if (!strokes.value.length) return
  undone.length = 0
  strokes.value = []
  persist()
  redraw()
  haptic()
}
function expand() {
  height.value += STEP_H
  haptic()
}
function shrink() {
  height.value = Math.max(BASE_H, height.value - STEP_H)
}
function toggleFinger() {
  finger.value = !finger.value
  try {
    localStorage.setItem(FINGER_KEY, finger.value ? 'on' : 'off')
  } catch {
    // ignore
  }
}
const canShrink = computed(() => height.value > BASE_H)
const hasInk = computed(() => strokes.value.length > 0)
</script>

<template>
  <div class="sp" :class="{ collapsed }">
    <div class="sp-bar">
      <button class="sp-title" :aria-expanded="!collapsed" @click="collapsed = !collapsed">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9" /><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z" /></svg>
        計算メモ
        <span class="sp-caret" :class="{ open: !collapsed }">▾</span>
      </button>
      <template v-if="!collapsed">
        <div class="sp-tools">
          <button v-for="c in COLORS" :key="c.key" class="sp-col" :class="{ on: tool === 'pen' && color === c.key }" :style="{ '--c': c.key }" :title="c.label" :aria-label="`${c.label}のペン`" @click="(tool = 'pen'), (color = c.key)"></button>
          <button class="sp-tl" :class="{ on: tool === 'eraser' }" title="消しゴム（線ごと）" aria-label="消しゴム" @click="tool = tool === 'eraser' ? 'pen' : 'eraser'">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 20H7L3 16a2 2 0 0 1 0-2.8L13.2 3a2 2 0 0 1 2.8 0l5 5a2 2 0 0 1 0 2.8L13 19" /><path d="M6.5 11.5l6 6" /></svg>
          </button>
          <button class="sp-tl" :disabled="!hasInk" title="戻す" aria-label="戻す" @click="undo">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 14L4 9l5-5" /><path d="M4 9h10a6 6 0 0 1 0 12h-3" /></svg>
          </button>
          <button class="sp-tl" title="進む" aria-label="進む" @click="redo">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 14l5-5-5-5" /><path d="M20 9H10a6 6 0 0 0 0 12h3" /></svg>
          </button>
          <button class="sp-tl txt" :disabled="!hasInk" @click="clearAll">全消去</button>
          <button class="sp-tl txt" :class="{ on: finger }" :title="finger ? '指でも書ける（押すと指はスクロール）' : '指はスクロール（押すと指でも書ける）'" @click="toggleFinger">指</button>
        </div>
      </template>
    </div>
    <div v-show="!collapsed" ref="wrap" class="sp-wrap">
      <canvas ref="canvas" class="sp-canvas" :class="{ eraser: tool === 'eraser' }" @pointerdown="down" @pointermove="move" @pointerup="up" @pointercancel="up" @contextmenu.prevent></canvas>
      <div class="sp-foot">
        <button class="sp-tl txt" @click="expand">＋ 欄を広げる</button>
        <button v-if="canShrink" class="sp-tl txt" @click="shrink">－ 縮める</button>
        <span class="sp-hint">保存はされません</span>
      </div>
    </div>
  </div>
</template>

<style scoped>
.sp {
  margin-top: 14px;
  border: 1px solid #e3e6ea;
  border-radius: 12px;
  background: #fff;
  overflow: hidden;
}
.sp-bar {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  padding: 6px 8px 6px 10px;
  border-bottom: 1px solid #f0f1f3;
  background: #fafbfc;
}
.sp.collapsed .sp-bar {
  border-bottom: none;
}
.sp-title {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  min-height: 32px;
  padding: 4px 6px;
  border: none;
  background: transparent;
  color: #4b5563;
  font-size: 12.5px;
  font-weight: 700;
  cursor: pointer;
  font-family: inherit;
}
.sp-caret {
  display: inline-block;
  transition: transform 0.18s;
  transform: rotate(-90deg);
  color: #9aa1ab;
}
.sp-caret.open {
  transform: none;
}
.sp-tools {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-left: auto;
  flex-wrap: wrap;
}
.sp-col {
  width: 26px;
  height: 26px;
  border: 2px solid #fff;
  border-radius: 50%;
  background: var(--c);
  box-shadow: 0 0 0 1px #d6dae0;
  cursor: pointer;
  padding: 0;
}
.sp-col.on {
  box-shadow: 0 0 0 2px var(--c);
}
.sp-tl {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 32px;
  height: 30px;
  padding: 0 8px;
  border: 1px solid #e3e6ea;
  border-radius: 8px;
  background: #fff;
  color: #4b5563;
  font-size: 12px;
  font-weight: 700;
  cursor: pointer;
  font-family: inherit;
}
.sp-tl.on {
  border-color: #3b50cc;
  background: #eef1fb;
  color: #2b3fb0;
}
.sp-tl:disabled {
  opacity: 0.4;
  cursor: default;
}
.sp-wrap {
  position: relative;
}
.sp-canvas {
  display: block;
  width: 100%;
  touch-action: none;
  -webkit-user-select: none;
  user-select: none;
  -webkit-touch-callout: none;
  cursor: crosshair;
}
.sp-canvas.eraser {
  cursor: cell;
}
.sp-foot {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 6px 10px;
  border-top: 1px dashed #eceef1;
}
.sp-hint {
  margin-left: auto;
  font-size: 11px;
  color: var(--faint);
}
</style>
