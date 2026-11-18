<script setup lang="ts">
/**
 * 計算用の書き込み欄（数学の例題の出題中に、問題の下の余白へ手書きで計算する）。
 * 保存はしない（その出題の間だけ。前へ／次へで戻っても同じカードの書き込みは残る）。
 * 道具: ペン（黒・青・赤、太さのスライダー。端末に記憶）／消しゴム（線ごと）／戻す／進む／全部消す／欄を広げる／等倍に戻す。
 * 拡縮: 2 本指のピンチ（＋そのまま移動）、PC は Ctrl＋ホイール。指書きが OFF のときは 1 本指で移動。
 * 図形補正: 線を引いたまま 0.6 秒止めると直線・円／楕円・長方形に整える（間違いノートと同じ lib/inkShapes.ts。直線はそのまま動かすと終点が追従。「図形」で OFF）。
 * Apple Pencil・マウスは常に書ける。指は既定で書けるが、切り替えで「指はスクロール／移動」にできる（端末に記憶）。
 * iPad 対策（間違いノートの編集画面と同じ）: installInkTouchGuard（touchstart の既定動作を止めて Pencil の反応を良くし、Pencil 中〜1 秒の手のひらを無視）、
 * blockUiTextSelection（書いている最中に文字が選択されて「ペースト」などのメニューが出るのを防ぐ。出題画面全体）、blockDoubleTapZoom（欄の中のダブルタップ拡大を止める）。
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { strokeHits } from '@/lib/inkErase'
import { drawStroke } from '@/lib/inkRender'
import { recognizeShape, straightLine, type InkShape } from '@/lib/inkShapes'
import { blockDoubleTapZoom, blockUiTextSelection, haptic, installInkTouchGuard } from '@/lib/native'
import type { MistakeStroke } from '@/types'

const props = defineProps<{
  /** 書き込みを保持する鍵（カードごと）。変わると表示を切り替える */
  padKey: string
}>()

interface PadState {
  strokes: MistakeStroke[]
  height: number
  view: { scale: number; tx: number; ty: number }
}
/** 出題中の書き込み（カードごと）。ページを離れても再表示できるようにモジュールで持つ */
const store: Map<string, PadState> = ((window as unknown as { __scratchStore?: Map<string, PadState> }).__scratchStore ??= new Map())

const BASE_H = 280
const STEP_H = 220
const FINGER_KEY = 'sm_scratch_finger'
const WIDTH_KEY = 'sm_scratch_width'
const SHAPE_KEY = 'sm_scratch_shape'
const HOLD_MS = 600
const DEFAULT_WIDTH = 1.6
const MIN_SCALE = 0.5
const MAX_SCALE = 8
const COLORS = [
  { key: '#1c2024', label: '黒' },
  { key: '#2f5fc4', label: '青' },
  { key: '#cf5563', label: '赤' },
]

const root = ref<HTMLElement | null>(null)
const wrap = ref<HTMLElement | null>(null)
const canvas = ref<HTMLCanvasElement | null>(null)
const strokes = ref<MistakeStroke[]>([])
const height = ref(BASE_H)
const view = ref({ scale: 1, tx: 0, ty: 0 })
const tool = ref<'pen' | 'eraser'>('pen')
const color = ref(COLORS[0]!.key)
const penWidth = ref(DEFAULT_WIDTH)
const finger = ref(localStorage.getItem(FINGER_KEY) !== 'off')
const shapeSnap = ref(localStorage.getItem(SHAPE_KEY) !== '0')
const collapsed = ref(false)
const undone: MistakeStroke[] = []
try {
  const w = Number(localStorage.getItem(WIDTH_KEY))
  if (Number.isFinite(w) && w >= 0.6 && w <= 8) penWidth.value = w
} catch {
  // ignore
}
watch(penWidth, (w) => {
  try {
    localStorage.setItem(WIDTH_KEY, String(w))
  } catch {
    // ignore
  }
})

function loadKey() {
  const s = store.get(props.padKey)
  strokes.value = s ? s.strokes : []
  height.value = s ? s.height : BASE_H
  view.value = s ? { ...s.view } : { scale: 1, tx: 0, ty: 0 }
  undone.length = 0
  requestAnimationFrame(redraw)
}
function persist() {
  store.set(props.padKey, { strokes: strokes.value, height: height.value, view: { ...view.value } })
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
  const v = view.value
  ctx.setTransform(1, 0, 0, 1, 0, 0)
  ctx.clearRect(0, 0, c.width, c.height)
  ctx.setTransform(dpr * v.scale, 0, 0, dpr * v.scale, dpr * v.tx, dpr * v.ty)
  // 見えている範囲（欄の座標）
  const x0 = -v.tx / v.scale
  const y0 = -v.ty / v.scale
  const x1 = x0 + cssW / v.scale
  const y1 = y0 + height.value / v.scale
  // 方眼（薄い線。拡縮しても同じ間隔）
  ctx.save()
  ctx.strokeStyle = '#e6e9ef'
  ctx.lineWidth = 1 / v.scale
  const g = 28
  ctx.beginPath()
  for (let x = Math.floor(x0 / g) * g; x <= x1; x += g) {
    ctx.moveTo(x, y0)
    ctx.lineTo(x, y1)
  }
  for (let y = Math.floor(y0 / g) * g; y <= y1; y += g) {
    ctx.moveTo(x0, y)
    ctx.lineTo(x1, y)
  }
  ctx.stroke()
  ctx.restore()
  for (const st of strokes.value) drawStroke(ctx, st)
  if (live) drawStroke(ctx, live)
}
let ro: ResizeObserver | null = null
const disposers: (() => void)[] = []
onMounted(() => {
  loadKey()
  resize()
  if (typeof ResizeObserver !== 'undefined' && wrap.value) {
    ro = new ResizeObserver(() => resize())
    ro.observe(wrap.value)
  }
  if (root.value) {
    disposers.push(installInkTouchGuard(root.value), blockDoubleTapZoom(root.value))
    // 文字の選択は出題画面全体で止める（手のひらが問題文やボタンに触れて「ペースト」のメニューが出るのを防ぐ）
    disposers.push(blockUiTextSelection(root.value.closest<HTMLElement>('.qf') ?? root.value))
  }
})
onBeforeUnmount(() => {
  clearHold()
  ro?.disconnect()
  for (const d of disposers) d()
})
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
/** 画面に触れている指（ピンチ・移動用） */
const touches = new Map<number, { x: number; y: number }>()
let pinch: { dist: number; cx: number; cy: number; scale: number; tx: number; ty: number } | null = null
let pan: { id: number; x: number; y: number; tx: number; ty: number } | null = null

function local(e: { clientX: number; clientY: number }): [number, number] {
  const r = canvas.value!.getBoundingClientRect()
  return [e.clientX - r.left, e.clientY - r.top]
}
/** 欄の座標（拡縮・移動を戻した座標） */
function pos(e: { clientX: number; clientY: number }): [number, number] {
  const [x, y] = local(e)
  const v = view.value
  return [(x - v.tx) / v.scale, (y - v.ty) / v.scale]
}
// ---- 図形補正: ペンを止めたまま HOLD_MS 経つと、書きかけの線を直線・円／楕円・長方形に整える ----
let holdTimer: number | undefined
let holdAt = { x: 0, y: 0 }
let holdSince = 0
let snapped: InkShape | null = null
function armHold(e: PointerEvent) {
  holdAt = { x: e.clientX, y: e.clientY }
  holdSince = performance.now()
  window.clearTimeout(holdTimer)
  holdTimer = shapeSnap.value ? window.setTimeout(snapLiveStroke, HOLD_MS) : undefined
}
function clearHold() {
  window.clearTimeout(holdTimer)
  holdTimer = undefined
}
function snapLiveStroke() {
  holdTimer = undefined
  if (!live || snapped) return
  const shape = recognizeShape(live.points)
  if (!shape) return
  live.points = shape.points
  snapped = shape.kind
  haptic()
  redraw()
}
function toggleShape() {
  shapeSnap.value = !shapeSnap.value
  try {
    localStorage.setItem(SHAPE_KEY, shapeSnap.value ? '1' : '0')
  } catch {
    // ignore
  }
}

function endLive(commit: boolean) {
  clearHold()
  snapped = null
  if (!live) return
  if (commit) {
    strokes.value = [...strokes.value, live]
    undone.length = 0
    persist()
  }
  live = null
  redraw()
}
function startPinch() {
  const [a, b] = Array.from(touches.values())
  if (!a || !b) return
  pinch = { dist: Math.max(10, Math.hypot(a.x - b.x, a.y - b.y)), cx: (a.x + b.x) / 2, cy: (a.y + b.y) / 2, scale: view.value.scale, tx: view.value.tx, ty: view.value.ty }
  pan = null
}
function updatePinch() {
  if (!pinch) return
  const [a, b] = Array.from(touches.values())
  if (!a || !b) return
  const dist = Math.max(10, Math.hypot(a.x - b.x, a.y - b.y))
  const cx = (a.x + b.x) / 2
  const cy = (a.y + b.y) / 2
  const scale = Math.min(MAX_SCALE, Math.max(MIN_SCALE, (pinch.scale * dist) / pinch.dist))
  const k = scale / pinch.scale
  // ピンチの中心（欄の中の位置）を固定したまま拡縮し、中心の移動分だけずらす
  const r = canvas.value!.getBoundingClientRect()
  const px = pinch.cx - r.left
  const py = pinch.cy - r.top
  view.value = { scale, tx: px - (px - pinch.tx) * k + (cx - pinch.cx), ty: py - (py - pinch.ty) * k + (cy - pinch.cy) }
  redraw()
}
function penWidthFor(e: PointerEvent): number {
  return e.pointerType === 'touch' ? penWidth.value * 1.15 : penWidth.value
}
function down(e: PointerEvent) {
  if (e.pointerType === 'pen') lastPenAt = performance.now()
  if (e.pointerType === 'touch') {
    // 手のひら（接触が大きい。iOS の指は width 40〜60px 程度で届くので 70px 超を手のひらとする）は無視
    if (e.width > 70 || e.height > 70) return
    touches.set(e.pointerId, { x: e.clientX, y: e.clientY })
    canvas.value?.setPointerCapture(e.pointerId)
    e.preventDefault()
    if (touches.size >= 2) {
      // 2 本目の指: 書きかけの線は捨ててピンチに
      if (activeId !== null && touches.has(activeId)) {
        activeId = null
        endLive(false)
      }
      startPinch()
      return
    }
    // Pencil で書いた直後（0.6 秒）の 1 本指は手のひらの可能性が高いので、書かず・動かさず（2 本目が来ればピンチにはなる）
    if (performance.now() - lastPenAt < 600) return
    if (!finger.value) {
      pan = { id: e.pointerId, x: e.clientX, y: e.clientY, tx: view.value.tx, ty: view.value.ty }
      return
    }
  } else if (e.pointerType === 'mouse' && e.button !== 0) {
    return
  }
  if (activeId !== null || pinch) return
  e.preventDefault()
  activeId = e.pointerId
  if (e.pointerType !== 'touch') canvas.value?.setPointerCapture(e.pointerId)
  const [x, y] = pos(e)
  if (tool.value === 'eraser') {
    eraseAt(x, y)
    return
  }
  live = { color: color.value, width: penWidthFor(e), kind: 'pen', points: [x, y] }
  snapped = null
  armHold(e)
  redraw()
}
function move(e: PointerEvent) {
  if (e.pointerType === 'touch' && touches.has(e.pointerId)) {
    touches.set(e.pointerId, { x: e.clientX, y: e.clientY })
    if (pinch) {
      e.preventDefault()
      updatePinch()
      return
    }
    if (pan && pan.id === e.pointerId) {
      e.preventDefault()
      view.value = { ...view.value, tx: pan.tx + (e.clientX - pan.x), ty: pan.ty + (e.clientY - pan.y) }
      redraw()
      return
    }
  }
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
  if (snapped) {
    // 直線に整えた後はそのまま動かすと終点が追従する（円・長方形はそのまま）
    if (snapped === 'line') {
      const [x, y] = pts[pts.length - 1]!
      live.points = straightLine({ x: live.points[0]!, y: live.points[1]! }, { x, y })
      redraw()
    }
    return
  }
  const eps = 0.5 / view.value.scale
  for (const [x, y] of pts) {
    const n = live.points.length
    if (n >= 2 && Math.abs(live.points[n - 2]! - x) < eps && Math.abs(live.points[n - 1]! - y) < eps) continue
    live.points.push(x, y)
  }
  // 画面上で 5px 以上動いたら「止まっている」時間を測り直す（Pencil のわずかな揺れでは測り直さない）。
  // 止まったまま HOLD_MS 経っていればここでも整える（タイマーが遅れる端末向けの保険）
  if (Math.hypot(e.clientX - holdAt.x, e.clientY - holdAt.y) > 5) armHold(e)
  else if (shapeSnap.value && !snapped && performance.now() - holdSince >= HOLD_MS) snapLiveStroke()
  redraw()
}
function up(e: PointerEvent) {
  if (e.pointerType === 'pen') lastPenAt = performance.now()
  if (e.pointerType === 'touch' && touches.has(e.pointerId)) {
    touches.delete(e.pointerId)
    if (pinch) {
      if (touches.size >= 2) startPinch()
      else {
        pinch = null
        persist()
        // 残った 1 本の指は touches に残るだけで、書きも動かしもしない（離してから書き直す）
      }
    }
    if (pan && pan.id === e.pointerId) {
      pan = null
      persist()
    }
  }
  try {
    canvas.value?.releasePointerCapture(e.pointerId)
  } catch {
    // ignore
  }
  if (e.pointerId !== activeId) return
  activeId = null
  endLive(true)
}
/** PC: Ctrl（⌘）＋ホイールで拡縮（マウスの位置を中心に） */
function wheel(e: WheelEvent) {
  if (!e.ctrlKey && !e.metaKey) return
  e.preventDefault()
  const v = view.value
  const k = Math.exp(-e.deltaY * 0.002)
  const scale = Math.min(MAX_SCALE, Math.max(MIN_SCALE, v.scale * k))
  const kk = scale / v.scale
  const [px, py] = local(e)
  view.value = { scale, tx: px - (px - v.tx) * kk, ty: py - (py - v.ty) * kk }
  persist()
  redraw()
}
function resetView() {
  view.value = { scale: 1, tx: 0, ty: 0 }
  persist()
  redraw()
}
function eraseAt(x: number, y: number) {
  const r = 10 / view.value.scale
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
const zoomed = computed(() => Math.abs(view.value.scale - 1) > 0.01 || view.value.tx !== 0 || view.value.ty !== 0)
const zoomLabel = computed(() => `${Math.round(view.value.scale * 100)}%`)
</script>

<template>
  <div ref="root" class="sp" :class="{ collapsed }">
    <div class="sp-bar">
      <button class="sp-title" :aria-expanded="!collapsed" @click="collapsed = !collapsed">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9" /><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z" /></svg>
        計算メモ
        <span class="sp-caret" :class="{ open: !collapsed }">▾</span>
      </button>
      <template v-if="!collapsed">
        <div class="sp-tools">
          <button v-for="c in COLORS" :key="c.key" class="sp-col" :class="{ on: tool === 'pen' && color === c.key }" :style="{ '--c': c.key }" :title="c.label" :aria-label="`${c.label}のペン`" @click="(tool = 'pen'), (color = c.key)"></button>
          <label class="sp-width" title="ペンの太さ">
            <span class="sp-dot" :style="{ width: `${Math.max(3, Math.min(14, penWidth * 2.2))}px`, height: `${Math.max(3, Math.min(14, penWidth * 2.2))}px`, background: color }"></span>
            <input v-model.number="penWidth" type="range" min="0.6" max="6" step="0.2" aria-label="ペンの太さ" />
          </label>
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
          <button class="sp-tl txt" :class="{ on: shapeSnap }" :title="shapeSnap ? '図形補正 ON（線を引いたまま止めると直線・円・長方形に）' : '図形補正 OFF'" @click="toggleShape">図形</button>
          <button class="sp-tl txt" :class="{ on: finger }" :title="finger ? '指でも書ける（押すと指は移動）' : '指は移動（押すと指でも書ける）'" @click="toggleFinger">指</button>
        </div>
      </template>
    </div>
    <div v-show="!collapsed" ref="wrap" class="sp-wrap">
      <canvas ref="canvas" class="sp-canvas" :class="{ eraser: tool === 'eraser' }" @pointerdown="down" @pointermove="move" @pointerup="up" @pointercancel="up" @wheel="wheel" @contextmenu.prevent></canvas>
      <button v-if="zoomed" class="sp-zoom" title="等倍に戻す" @click="resetView">{{ zoomLabel }} ↺</button>
      <div class="sp-foot">
        <button class="sp-tl txt" @click="expand">＋ 欄を広げる</button>
        <button v-if="canShrink" class="sp-tl txt" @click="shrink">－ 縮める</button>
        <span class="sp-hint">2 本指で拡縮・移動／保存はされません</span>
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
  -webkit-user-select: none;
  user-select: none;
  -webkit-touch-callout: none;
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
.sp-width {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  height: 30px;
  padding: 0 8px;
  border: 1px solid #e3e6ea;
  border-radius: 8px;
  background: #fff;
}
.sp-width input {
  width: 72px;
  margin: 0;
  accent-color: #3b50cc;
  touch-action: pan-x;
}
.sp-dot {
  display: inline-block;
  border-radius: 50%;
  flex-shrink: 0;
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
.sp-zoom {
  position: absolute;
  right: 10px;
  top: 10px;
  height: 28px;
  padding: 0 10px;
  border: 1px solid #c9d6f2;
  border-radius: 99px;
  background: rgba(255, 255, 255, 0.92);
  color: #2f5fc4;
  font-size: 12px;
  font-weight: 700;
  cursor: pointer;
  font-family: inherit;
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
