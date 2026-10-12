<script setup lang="ts">
/**
 * 間違いノートのページ編集。問題の写真・説明用の画像を動かす／大きさを変える、文字を入力する、ペンで手書きする。
 * 左に縦型の道具（小テストの添削と同じ並び）、右の残り全部がキャンバス。
 * 方眼の下敷き（SVG のパターン）は画面に固定し、置いたものだけを拡縮・移動して見る。
 * 置ける範囲に壁はなく（ページの外でもよい）、印刷・閲覧のときに全体がページ（NOTE_W × NOTE_H）に収まるよう自動で調整する。
 * - 拡縮: マウスホイール（カーソル位置を中心・1 目盛り約 5%）、ピンチ、「−」「＋」（10% ずつ）「全体」「幅」
 * - 移動: ホイールボタン（中ボタン）のドラッグ、指 1 本（書く設定でないとき）／指 2 本のドラッグ
 * - 選択: 画像・文字の枠をドラッグで移動、右下のつまみで大きさ変更
 * - ペン / 消しゴム: Apple Pencil・マウスで書く。指は既定では移動（「指で書く」を ON にすると指でも書ける）
 * - 文字: 空いている所をクリック（タップ）するとその位置に文字の枠ができ、枠の中で入力
 * 変更はひとまとまりごとに update:layout で親へ渡す（親が自動保存する）。写真の登録・画像の追加は親に頼む（requestProblem / requestExtra）。
 */
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { DEFAULT_FONT, FONT_SIZES, LINE_HEIGHT, NOTE_H, NOTE_W, PEN_COLORS, PEN_WIDTHS, contentBox, newItemId } from '@/lib/mistake'
import { isTouch } from '@/lib/native'
import { useUiStore } from '@/stores/ui'
import type { MistakeLayout, MistakeLayoutItem, MistakeStroke } from '@/types'

type Tool = 'select' | 'pen' | 'eraser' | 'text'

const props = defineProps<{ layout: MistakeLayout; urls: Record<number, string>; hasProblem: boolean; busy?: boolean; canAddProblem?: boolean }>()
const emit = defineEmits<{ 'update:layout': [layout: MistakeLayout]; removeImage: [imageId: number]; requestProblem: []; requestExtra: [] }>()

const ui = useUiStore()
const gid = newItemId('g') // 方眼パターンの id（画面内で一意に）

// ---- 道具 ----
const tool = ref<Tool>(isTouch ? 'pen' : 'select')
const color = ref(localStorage.getItem('sm_mn_color') ?? PEN_COLORS[0]!)
const width = ref(Number(localStorage.getItem('sm_mn_width') ?? PEN_WIDTHS[1]!.width))
const fingerDraw = ref(localStorage.getItem('sm_mn_finger') === '1')
watch(color, (c) => localStorage.setItem('sm_mn_color', c))
watch(width, (w) => localStorage.setItem('sm_mn_width', String(w)))
watch(fingerDraw, (f) => localStorage.setItem('sm_mn_finger', f ? '1' : '0'))
const selectedId = ref<string | null>(null)
const selected = computed(() => props.layout.items.find((i) => i.id === selectedId.value) ?? null)
const canDelete = computed(() => !!selected.value && !(selected.value.type === 'image' && selected.value.role === 'problem'))

// ---- ステージ（表示枠）と拡大縮小・移動 ----
const stage = ref<HTMLElement | null>(null)
const stageW = ref(800)
const stageH = ref(600)
const z = ref(0.5)
const tx = ref(0)
const ty = ref(0)
const Z_MIN = 0.1
const Z_MAX = 6
let fitted = false
let ro: ResizeObserver | null = null

function measure() {
  const el = stage.value
  if (!el) return
  stageH.value = el.clientHeight
  stageW.value = el.clientWidth
  if (!fitted && stageW.value > 0 && stageH.value > 0) {
    fitted = true
    fitAll()
  }
}
/** 置いたもの全体（とページ 1 枚分の広さ）が収まる倍率に */
function fitAll() {
  const b = contentBox(props.layout)
  const x1 = Math.min(0, b?.x ?? 0)
  const y1 = Math.min(0, b?.y ?? 0)
  const x2 = Math.max(NOTE_W, b ? b.x + b.w : 0)
  const y2 = Math.max(NOTE_H, b ? b.y + b.h : 0)
  const nz = Math.min((stageW.value - 32) / (x2 - x1), (stageH.value - 32) / (y2 - y1))
  z.value = clamp(nz, Z_MIN, Z_MAX)
  tx.value = Math.round((stageW.value - (x2 - x1) * z.value) / 2 - x1 * z.value)
  ty.value = Math.round((stageH.value - (y2 - y1) * z.value) / 2 - y1 * z.value)
}
/** 幅いっぱいに */
function fitWidth() {
  z.value = clamp((stageW.value - 32) / NOTE_W, Z_MIN, Z_MAX)
  tx.value = 16
  ty.value = 16
}
/** (cx, cy)（ステージ内の座標）を中心に factor 倍 */
function zoomAt(factor: number, cx: number, cy: number) {
  const nz = clamp(z.value * factor, Z_MIN, Z_MAX)
  tx.value = cx - ((cx - tx.value) * nz) / z.value
  ty.value = cy - ((cy - ty.value) * nz) / z.value
  z.value = nz
}
function zoomStep(dir: 1 | -1) {
  zoomAt(dir > 0 ? 1.1 : 1 / 1.1, stageW.value / 2, stageH.value / 2)
}
function onWheel(e: WheelEvent) {
  const r = stage.value!.getBoundingClientRect()
  // 1 目盛り（deltaY 100）でおよそ 5%。トラックパッドのピンチ（ctrlKey）は少し強めに
  const factor = Math.exp(-e.deltaY * (e.ctrlKey ? 0.002 : 0.0005))
  zoomAt(factor, e.clientX - r.left, e.clientY - r.top)
}
const pageStyle = computed(() => ({
  width: NOTE_W + 'px',
  height: NOTE_H + 'px',
  transform: `translate(${tx.value}px, ${ty.value}px) scale(${z.value})`,
  '--inv': String(1 / z.value),
}))

// ---- 取り消し ----
const undoStack = ref<MistakeLayout[]>([])
function snapshot() {
  undoStack.value.push(JSON.parse(JSON.stringify(props.layout)))
  if (undoStack.value.length > 40) undoStack.value.shift()
}
function undo() {
  const prev = undoStack.value.pop()
  if (prev) emit('update:layout', prev)
}
function commit(next: MistakeLayout) {
  snapshot()
  emit('update:layout', next)
}

// ---- ドラッグ（移動・大きさ・ペン・消しゴム・ステージの移動）とピンチ ----
interface Drag {
  kind: 'move' | 'resize' | 'stroke' | 'erase' | 'pan'
  id: string
  pointerId: number
  x0: number
  y0: number
  ox: number
  oy: number
  ow: number
  oh: number
  aspect: number
  moved: boolean
  lastX: number
  lastY: number
  startX: number
  startY: number
  /** 文字モードで空いている所を押した位置（動かさずに離したら文字の枠を作る） */
  textAt?: { x: number; y: number }
}
let drag: Drag | null = null
const preview = ref<{ id: string; x: number; y: number; w: number; h: number } | null>(null)
const live = ref<MistakeStroke | null>(null)
const erased = ref<Set<number>>(new Set())
const panning = ref(false)

/** タッチ中の指（ピンチ用） */
const touches = new Map<number, { x: number; y: number }>()
let pinch: { d0: number; z0: number; px: number; py: number } | null = null

function stageXY(e: PointerEvent): { x: number; y: number } {
  const r = stage.value!.getBoundingClientRect()
  return { x: e.clientX - r.left, y: e.clientY - r.top }
}
function toPage(e: PointerEvent): { x: number; y: number } {
  const s = stageXY(e)
  return { x: (s.x - tx.value) / z.value, y: (s.y - ty.value) / z.value }
}
function baseDrag(e: PointerEvent, p: { x: number; y: number }) {
  return { pointerId: e.pointerId, x0: p.x, y0: p.y, ox: 0, oy: 0, ow: 0, oh: 0, aspect: 1, moved: false, lastX: e.clientX, lastY: e.clientY, startX: e.clientX, startY: e.clientY }
}
function startPan(e: PointerEvent, textAt?: { x: number; y: number }) {
  stage.value!.setPointerCapture(e.pointerId)
  drag = { kind: 'pan', id: '', ...baseDrag(e, { x: 0, y: 0 }), textAt }
  panning.value = true
}
function cancelDrag() {
  drag = null
  live.value = null
  erased.value = new Set()
  preview.value = null
  panning.value = false
}

function onDown(e: PointerEvent) {
  if (e.pointerType === 'touch') {
    touches.set(e.pointerId, { x: e.clientX, y: e.clientY })
    if (touches.size === 2) {
      // 2 本目の指: 書きかけ・動かしかけは捨ててピンチ（拡縮・移動）にする
      cancelDrag()
      stage.value!.setPointerCapture(e.pointerId)
      const [a, b] = Array.from(touches.values())
      const r = stage.value!.getBoundingClientRect()
      const cx = (a!.x + b!.x) / 2 - r.left
      const cy = (a!.y + b!.y) / 2 - r.top
      pinch = { d0: Math.max(1, Math.hypot(a!.x - b!.x, a!.y - b!.y)), z0: z.value, px: (cx - tx.value) / z.value, py: (cy - ty.value) / z.value }
      return
    }
  }
  if (pinch) return
  if (e.button === 1) {
    // ホイールボタンのドラッグで移動
    e.preventDefault()
    startPan(e)
    return
  }
  if (e.pointerType === 'mouse' && e.button !== 0) return
  const target = e.target as HTMLElement
  if (tool.value === 'text' && target.closest('textarea')) return // 入力中
  const finger = e.pointerType === 'touch'
  const p = toPage(e)

  if (tool.value === 'pen' || tool.value === 'eraser') {
    if (finger && !fingerDraw.value) {
      startPan(e)
      return
    }
    stage.value!.setPointerCapture(e.pointerId)
    if (tool.value === 'pen') {
      live.value = { color: color.value, width: width.value, points: [round(p.x), round(p.y)] }
      drag = { kind: 'stroke', id: '', ...baseDrag(e, p) }
    } else {
      erased.value = new Set()
      drag = { kind: 'erase', id: '', ...baseDrag(e, p) }
      eraseAt(p)
    }
    return
  }

  // 選択・文字: 枠の操作
  const handle = target.closest<HTMLElement>('[data-handle]')
  const itemEl = target.closest<HTMLElement>('[data-item]')
  const id = (handle ?? itemEl)?.dataset.item
  const it = id ? props.layout.items.find((x) => x.id === id) : undefined
  if (it && id) {
    selectedId.value = id
    stage.value!.setPointerCapture(e.pointerId)
    drag = { kind: handle ? 'resize' : 'move', id, ...baseDrag(e, p), ox: it.x, oy: it.y, ow: it.w, oh: it.h, aspect: it.h > 0 ? it.w / it.h : 1 }
    preview.value = { id, x: it.x, y: it.y, w: it.w, h: it.h }
    return
  }
  selectedId.value = null
  // 空いている所: 文字モードならクリック（タップ）で文字の枠を作る。動かした場合はページの移動
  if (tool.value === 'text') {
    startPan(e, p)
    return
  }
  if (finger) startPan(e) // 指で空いている所を動かすとページが動く
}

function onMove(e: PointerEvent) {
  if (e.pointerType === 'touch' && touches.has(e.pointerId)) touches.set(e.pointerId, { x: e.clientX, y: e.clientY })
  if (pinch) {
    if (touches.size < 2) return
    const [a, b] = Array.from(touches.values())
    const r = stage.value!.getBoundingClientRect()
    const cx = (a!.x + b!.x) / 2 - r.left
    const cy = (a!.y + b!.y) / 2 - r.top
    const d = Math.max(1, Math.hypot(a!.x - b!.x, a!.y - b!.y))
    const nz = clamp((pinch.z0 * d) / pinch.d0, Z_MIN, Z_MAX)
    z.value = nz
    tx.value = cx - pinch.px * nz
    ty.value = cy - pinch.py * nz
    return
  }
  if (!drag || drag.pointerId !== e.pointerId) return
  if (drag.kind === 'pan') {
    if (!drag.moved && Math.hypot(e.clientX - drag.startX, e.clientY - drag.startY) < 6) return
    drag.moved = true
    tx.value += e.clientX - drag.lastX
    ty.value += e.clientY - drag.lastY
    drag.lastX = e.clientX
    drag.lastY = e.clientY
    return
  }
  const p = toPage(e)
  const dx = p.x - drag.x0
  const dy = p.y - drag.y0
  if (!drag.moved && Math.abs(dx) * z.value < 2 && Math.abs(dy) * z.value < 2) return
  drag.moved = true
  if (drag.kind === 'stroke' && live.value) {
    const pts = live.value.points
    const lx = pts[pts.length - 2]!
    const ly = pts[pts.length - 1]!
    if (Math.hypot(p.x - lx, p.y - ly) >= 1.5) pts.push(round(p.x), round(p.y))
  } else if (drag.kind === 'erase') {
    eraseAt(p)
  } else if (drag.kind === 'move') {
    // 置ける範囲に壁は設けない（印刷・閲覧のときに全体がページに収まるよう自動で調整する）
    preview.value = { id: drag.id, x: drag.ox + dx, y: drag.oy + dy, w: drag.ow, h: drag.oh }
  } else if (drag.kind === 'resize') {
    const it = props.layout.items.find((x) => x.id === drag!.id)
    if (!it) return
    if (it.type === 'image') {
      const w = Math.max(60, Math.min(NOTE_W * 1.5, drag.ow + dx))
      preview.value = { id: drag.id, x: drag.ox, y: drag.oy, w, h: w / drag.aspect }
    } else {
      preview.value = { id: drag.id, x: drag.ox, y: drag.oy, w: Math.max(140, drag.ow + dx), h: Math.max(60, drag.oh + dy) }
    }
  }
}

function onUp(e: PointerEvent) {
  if (e.pointerType === 'touch') {
    touches.delete(e.pointerId)
    if (pinch && touches.size < 2) pinch = null
  }
  if (!drag || drag.pointerId !== e.pointerId) return
  const d = drag
  drag = null
  panning.value = false
  if (d.kind === 'pan') {
    if (!d.moved && d.textAt && e.type === 'pointerup') createTextAt(d.textAt.x, d.textAt.y)
    return
  }
  if (d.kind === 'stroke') {
    const st = live.value
    live.value = null
    if (!st) return
    if (st.points.length === 2) st.points.push(st.points[0]!, st.points[1]!) // 点
    commit({ items: props.layout.items, ink: [...props.layout.ink, st] })
    return
  }
  if (d.kind === 'erase') {
    const set = erased.value
    erased.value = new Set()
    if (set.size) commit({ items: props.layout.items, ink: props.layout.ink.filter((_, i) => !set.has(i)) })
    return
  }
  const pv = preview.value
  preview.value = null
  if (!d.moved || !pv) return
  commit({
    items: props.layout.items.map((it) => (it.id === pv.id ? { ...it, x: round(pv.x), y: round(pv.y), w: round(pv.w), h: round(pv.h) } : it)),
    ink: props.layout.ink,
  })
}

function eraseAt(p: { x: number; y: number }) {
  const R = 18
  props.layout.ink.forEach((st, i) => {
    if (erased.value.has(i)) return
    const pts = st.points
    const tol = R + st.width / 2
    for (let k = 0; k + 1 < pts.length; k += 2) {
      if (Math.hypot(pts[k]! - p.x, pts[k + 1]! - p.y) <= tol) {
        erased.value = new Set(erased.value).add(i)
        return
      }
    }
  })
}

const round = (n: number) => Math.round(n * 10) / 10
const clamp = (v: number, lo: number, hi: number) => Math.max(lo, Math.min(hi, v))

// ---- 文字 ----
const TEXT_W = 560
const TEXT_H = 220
let textSnapshotFor: string | null = null
function onTextFocus(it: MistakeLayoutItem) {
  if (textSnapshotFor !== it.id) {
    snapshot()
    textSnapshotFor = it.id
  }
}
function onTextInput(it: MistakeLayoutItem, e: Event) {
  const text = (e.target as HTMLTextAreaElement).value
  emit('update:layout', { items: props.layout.items.map((x) => (x.id === it.id ? { ...x, text } : x)), ink: props.layout.ink })
}
function setFontSize(size: number) {
  const it = selected.value
  if (!it || it.type !== 'text') return
  commit({ items: props.layout.items.map((x) => (x.id === it.id ? { ...x, fontSize: size } : x)), ink: props.layout.ink })
}
/** (x, y)（ページ座標）を左上にして文字の枠を作り、入力できる状態にする */
function createTextAt(x: number, y: number) {
  const it: MistakeLayoutItem = { id: newItemId('t'), type: 'text', text: '', fontSize: DEFAULT_FONT, x: round(x), y: round(y), w: TEXT_W, h: TEXT_H }
  commit({ items: [...props.layout.items, it], ink: props.layout.ink })
  selectedId.value = it.id
  tool.value = 'text'
  nextTick(() => stage.value?.querySelector<HTMLTextAreaElement>(`[data-item="${it.id}"] textarea`)?.focus())
}
/** 「＋ 文字の枠」: 今見えている範囲の真ん中に作る */
function addTextBox() {
  const cx = (stageW.value / 2 - tx.value) / z.value - TEXT_W / 2
  const cy = (stageH.value / 2 - ty.value) / z.value - TEXT_H / 2
  createTextAt(cx, cy)
}
function deleteSelected() {
  const it = selected.value
  if (!it || !canDelete.value) return
  if (it.type === 'image' && it.imageId) emit('removeImage', it.imageId)
  commit({ items: props.layout.items.filter((x) => x.id !== it.id), ink: props.layout.ink })
  selectedId.value = null
}
function clearInk() {
  if (!props.layout.ink.length) return
  commit({ items: props.layout.items, ink: [] })
  ui.notify('手書きを消しました（取り消しで戻せます）')
}

// ---- 表示 ----
function box(it: MistakeLayoutItem) {
  const b = preview.value?.id === it.id ? preview.value : it
  return { left: b.x + 'px', top: b.y + 'px', width: b.w + 'px', height: b.h + 'px' }
}
function pathOf(st: MistakeStroke): string {
  const p = st.points
  if (p.length < 2) return ''
  let d = `M${p[0]} ${p[1]}`
  for (let i = 2; i + 1 < p.length; i += 2) d += ` L${p[i]} ${p[i + 1]}`
  return d
}
const cursor = computed(() => (panning.value ? 'grabbing' : { select: 'default', pen: 'crosshair', eraser: 'cell', text: 'text' }[tool.value]))
const zoomLabel = computed(() => `${Math.round(z.value * 100)}%`)

// ---- キーボード ----
function onKey(e: KeyboardEvent) {
  const tag = (e.target as HTMLElement)?.tagName
  const typing = tag === 'TEXTAREA' || tag === 'INPUT' || tag === 'SELECT'
  if (typing) return
  if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'z') {
    e.preventDefault()
    undo()
  } else if ((e.key === 'Delete' || e.key === 'Backspace') && selected.value) {
    e.preventDefault()
    deleteSelected()
  } else if (e.key === 'Escape') {
    selectedId.value = null
  } else if (e.key === '+' || e.key === '=') {
    zoomStep(1)
  } else if (e.key === '-') {
    zoomStep(-1)
  } else if (e.key === '0' && (e.ctrlKey || e.metaKey)) {
    e.preventDefault()
    fitAll()
  }
}

onMounted(() => {
  measure()
  if (stage.value && typeof ResizeObserver !== 'undefined') {
    ro = new ResizeObserver(measure)
    ro.observe(stage.value)
  }
  window.addEventListener('resize', measure)
  window.addEventListener('keydown', onKey)
})
onBeforeUnmount(() => {
  ro?.disconnect()
  window.removeEventListener('resize', measure)
  window.removeEventListener('keydown', onKey)
})
</script>

<template>
  <div class="mc">
    <!-- 縦型の道具（左） -->
    <div class="rail">
      <div class="group">
        <button class="rb" :class="{ on: tool === 'select' }" title="選択・移動" @click="tool = 'select'">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"><path d="M5 3l14 8-6 2-3 6z" /></svg><span>選択</span>
        </button>
        <button class="rb" :class="{ on: tool === 'pen' }" title="ペン" @click="tool = 'pen'">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9" /><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z" /></svg><span>ペン</span>
        </button>
        <button class="rb" :class="{ on: tool === 'eraser' }" title="消しゴム（手書きを消す）" @click="tool = 'eraser'">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"><path d="M7 20l-4-4 10-10 6 6-8 8z" /><path d="M9 20h12" /></svg><span>消す</span>
        </button>
        <button class="rb" :class="{ on: tool === 'text' }" title="文字の挿入: 空いている所をクリックすると文字の枠ができます" @click="tool = 'text'">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M5 6h14M12 6v13M9 19h6" /></svg><span>文字</span>
        </button>
      </div>

      <!-- 道具ごとの設定 -->
      <div v-if="tool === 'pen'" class="group sub">
        <div class="colors">
          <button v-for="c in PEN_COLORS" :key="c" class="sw" :class="{ on: color === c }" :style="{ '--c': c }" :aria-label="c" @click="color = c"></button>
        </div>
        <button v-for="w in PEN_WIDTHS" :key="w.width" class="rb small" :class="{ on: width === w.width }" @click="width = w.width">
          <span class="dotw" :style="{ height: Math.min(10, w.width) + 'px' }"></span><span>{{ w.label }}</span>
        </button>
        <label v-if="isTouch" class="chk" title="OFF のときは指で動かし、Apple Pencil で書きます"><input v-model="fingerDraw" type="checkbox" /><span>指で書く</span></label>
      </div>
      <div v-else-if="tool === 'eraser'" class="group sub">
        <button class="rb small" :disabled="!layout.ink.length" @click="clearInk"><span>全部消す</span></button>
        <label v-if="isTouch" class="chk"><input v-model="fingerDraw" type="checkbox" /><span>指で消す</span></label>
      </div>
      <div v-else-if="tool === 'text' && selected?.type === 'text'" class="group sub">
        <button v-for="f in FONT_SIZES" :key="f.size" class="rb small" :class="{ on: (selected.fontSize ?? DEFAULT_FONT) === f.size }" @click="setFontSize(f.size)"><span>{{ f.label }}</span></button>
      </div>

      <div class="group">
        <button class="rb" :disabled="busy || (!hasProblem && !canAddProblem)" :title="hasProblem ? '問題の写真を差し替える' : '問題の写真を登録する'" @click="emit('requestProblem')">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 8h3l2-3h6l2 3h3v11H4z" /><circle cx="12" cy="13" r="3.2" /></svg><span>{{ hasProblem ? '写真' : '問題' }}</span>
        </button>
        <button class="rb" :disabled="busy || !hasProblem" title="説明用の画像を追加" @click="emit('requestExtra')">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2" /><path d="M3 16l5-5 4 4 3-3 6 6" /><circle cx="16" cy="9" r="1.5" /></svg><span>画像</span>
        </button>
        <button class="rb" title="見えている範囲の真ん中に文字の枠を作る" @click="addTextBox">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 6h10M9 6v12M6 18h6" /><path d="M18 10v8M14 14h8" /></svg><span>文字枠</span>
        </button>
        <button class="rb danger" :disabled="!canDelete" :title="selected?.type === 'text' ? '文字の枠を削除' : '画像を削除'" @click="deleteSelected">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6" /></svg><span>削除</span>
        </button>
        <button class="rb" :disabled="!undoStack.length" title="取り消し（Ctrl+Z）" @click="undo">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 14l-4-4 4-4" /><path d="M5 10h9a5 5 0 0 1 0 10h-3" /></svg><span>戻す</span>
        </button>
      </div>

      <div class="group zoom" title="ホイールで拡縮（約 5% ずつ）、ホイールボタンのドラッグで移動">
        <button class="rb small" aria-label="拡大" @click="zoomStep(1)"><span class="big">＋</span></button>
        <span class="zl dm">{{ zoomLabel }}</span>
        <button class="rb small" aria-label="縮小" @click="zoomStep(-1)"><span class="big">−</span></button>
        <button class="rb small" @click="fitAll"><span>全体</span></button>
        <button class="rb small" @click="fitWidth"><span>幅</span></button>
      </div>
    </div>

    <!-- ステージ（方眼は固定。中のページだけを拡縮・移動） -->
    <div
      ref="stage"
      class="stage no-gesture"
      :class="{ ['tool-' + tool]: true }"
      :style="{ cursor }"
      @wheel.prevent="onWheel"
      @pointerdown="onDown"
      @pointermove="onMove"
      @pointerup="onUp"
      @pointercancel="onUp"
      @contextmenu.prevent
    >
      <!-- 方眼の下敷き（SVG のパターン。画面に固定） -->
      <svg class="grid" aria-hidden="true">
        <defs>
          <pattern :id="gid + 's'" width="32" height="32" patternUnits="userSpaceOnUse">
            <path d="M32 0H0V32" fill="none" stroke="#dfe3e8" stroke-width="1" />
          </pattern>
          <pattern :id="gid + 'l'" width="160" height="160" patternUnits="userSpaceOnUse">
            <rect width="160" height="160" :fill="`url(#${gid}s)`" />
            <path d="M160 0H0V160" fill="none" stroke="#c3c8d0" stroke-width="1" />
          </pattern>
        </defs>
        <rect width="100%" height="100%" :fill="`url(#${gid}l)`" />
      </svg>

      <div class="mpage" :style="pageStyle">
        <div v-if="!hasProblem" class="placeholder">
          <div>まず対象の問題の写真を登録してください</div>
          <div class="ph-sub">左の「問題」ボタンか、PC では画像をコピーして Ctrl+V、またはここへドラッグ＆ドロップ</div>
        </div>

        <div
          v-for="it in layout.items"
          :key="it.id"
          class="item"
          :class="{ sel: selectedId === it.id, text: it.type === 'text', problem: it.role === 'problem' }"
          :data-item="it.id"
          :style="box(it)"
        >
          <img v-if="it.type === 'image'" :src="urls[it.imageId ?? 0]" draggable="false" alt="" />
          <textarea
            v-else
            :value="it.text ?? ''"
            :readonly="tool !== 'text'"
            :placeholder="tool === 'text' ? '間違えた部分・解説を入力' : ''"
            :style="{ fontSize: (it.fontSize ?? DEFAULT_FONT) + 'px', lineHeight: LINE_HEIGHT }"
            spellcheck="false"
            @focus="onTextFocus(it)"
            @input="onTextInput(it, $event)"
          ></textarea>
          <span v-if="it.role === 'problem'" class="tag">問題</span>
          <span v-if="selectedId === it.id" class="hdl" data-handle="se" :data-item="it.id"></span>
        </div>

        <svg class="ink" :viewBox="`0 0 ${NOTE_W} ${NOTE_H}`" preserveAspectRatio="none">
          <template v-for="(st, i) in layout.ink" :key="i">
            <path v-if="!erased.has(i)" :d="pathOf(st)" :stroke="st.color" :stroke-width="st.width" fill="none" stroke-linecap="round" stroke-linejoin="round" />
          </template>
          <path v-if="live" :d="pathOf(live)" :stroke="live.color" :stroke-width="live.width" fill="none" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
      </div>
      <div class="stage-hint">
        <template v-if="tool === 'text'">空いている所をクリックすると、その位置に文字の枠ができます　</template>
        {{ isTouch ? '2 本の指で拡縮・移動' : 'ホイールで拡縮・ホイールボタンのドラッグで移動' }}
      </div>
    </div>
  </div>
</template>

<style scoped>
.mc {
  display: flex;
  flex-direction: row;
  gap: 8px;
  flex: 1;
  min-height: 0;
  min-width: 0;
}
/* ---- 縦型の道具 ---- */
.rail {
  flex-shrink: 0;
  width: 64px;
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding: 6px 5px;
  background: #fff;
  border: 1px solid var(--line);
  border-radius: 12px;
  overflow-y: auto;
  overscroll-behavior: contain;
  scrollbar-width: none;
}
.rail::-webkit-scrollbar {
  display: none;
}
.group {
  display: flex;
  flex-direction: column;
  gap: 3px;
  padding-bottom: 8px;
  border-bottom: 1px solid #eef0f3;
}
.group:last-child {
  border-bottom: none;
  padding-bottom: 0;
}
.group.sub {
  background: #f6f7f9;
  border-radius: 9px;
  padding: 6px 3px;
  border-bottom: none;
}
.rb {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 2px;
  padding: 6px 2px 5px;
  border: none;
  border-radius: 8px;
  background: transparent;
  color: var(--mut);
  font-size: 10.5px;
  font-weight: 600;
  cursor: pointer;
  line-height: 1.1;
}
.rb.small {
  flex-direction: row;
  justify-content: center;
  gap: 4px;
  padding: 5px 2px;
  font-size: 11px;
}
.rb:hover {
  background: #f1f2f4;
}
.rb.on {
  background: #1c2024;
  color: #fff;
}
.rb:disabled {
  opacity: 0.35;
  cursor: default;
}
.rb.danger:not(:disabled) {
  color: #c0444f;
}
.rb .big {
  font-size: 16px;
  font-weight: 700;
}
.dotw {
  width: 16px;
  border-radius: 3px;
  background: currentColor;
}
.colors {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 5px;
  justify-items: center;
  padding: 2px 0 5px;
}
.sw {
  width: 20px;
  height: 20px;
  border-radius: 50%;
  border: 2px solid #fff;
  background: var(--c);
  box-shadow: 0 0 0 1px #e3e6ea;
  cursor: pointer;
  padding: 0;
}
.sw.on {
  box-shadow: 0 0 0 2px var(--c);
}
.chk {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 2px;
  font-size: 10px;
  color: var(--mut);
  font-weight: 600;
  padding-top: 4px;
}
.zl {
  text-align: center;
  font-size: 11px;
  font-weight: 700;
  color: var(--mut);
  padding: 2px 0;
}
/* ---- ステージ ---- */
.stage {
  position: relative;
  flex: 1;
  min-width: 0;
  min-height: 0;
  overflow: hidden;
  background: #fff;
  border: 1px solid #d4d8de;
  border-radius: 12px;
  touch-action: none;
  -webkit-user-select: none;
  user-select: none;
}
.grid {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  pointer-events: none;
}
.stage-hint {
  position: absolute;
  right: 10px;
  bottom: 8px;
  font-size: 11px;
  color: #7c838d;
  background: rgba(255, 255, 255, 0.8);
  border-radius: 6px;
  padding: 2px 8px;
  pointer-events: none;
}
/* ページ本体。枠は描かず、置いたものだけが見える（ページの外にも置ける。印刷時に全体を収める） */
.mpage {
  position: absolute;
  left: 0;
  top: 0;
  transform-origin: 0 0;
}
.placeholder {
  position: absolute;
  inset: 0;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 12px;
  color: var(--faint);
  font-size: 30px;
  font-weight: 600;
  text-align: center;
  padding: 40px;
  pointer-events: none;
}
.ph-sub {
  font-size: 22px;
  font-weight: 400;
}
.item {
  position: absolute;
  box-sizing: border-box;
}
.item img {
  display: block;
  width: 100%;
  height: 100%;
  object-fit: fill;
  box-shadow: 0 0 0 1px rgba(0, 0, 0, 0.12);
  pointer-events: none;
}
.item textarea {
  display: block;
  width: 100%;
  height: 100%;
  padding: 6px;
  border: 2px dashed transparent;
  background: transparent;
  resize: none;
  outline: none;
  color: var(--ink);
  font-family: inherit;
  overflow: hidden;
  pointer-events: none;
  word-break: break-all;
}
.tool-text .item textarea {
  pointer-events: auto;
  border-color: #c1c8f0;
  background: rgba(255, 255, 255, 0.5);
}
.tool-text .item textarea:focus {
  border-style: solid;
  border-color: var(--primary);
}
.tool-select .item:hover {
  outline: 2px dashed #b9c2f2;
}
.item.sel {
  outline: 3px solid var(--primary);
  outline-offset: -1px;
}
.tag {
  position: absolute;
  left: 8px;
  top: 8px;
  font-size: 18px;
  font-weight: 700;
  color: #fff;
  background: rgba(28, 32, 36, 0.7);
  border-radius: 6px;
  padding: 2px 10px;
  pointer-events: none;
}
/* つまみは拡縮しても画面上で同じ大きさに見えるようにする（--inv = 1 / 倍率） */
.hdl {
  position: absolute;
  right: calc(-11px * var(--inv, 1));
  bottom: calc(-11px * var(--inv, 1));
  width: calc(22px * var(--inv, 1));
  height: calc(22px * var(--inv, 1));
  border-radius: 50%;
  background: var(--primary);
  border: calc(3px * var(--inv, 1)) solid #fff;
  box-shadow: 0 1px 4px rgba(0, 0, 0, 0.3);
  cursor: nwse-resize;
  touch-action: none;
}
.ink {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  pointer-events: none;
  /* ページの外に書いた線も見えるようにする */
  overflow: visible;
}
.tool-pen .item,
.tool-eraser .item {
  pointer-events: none;
}
</style>
