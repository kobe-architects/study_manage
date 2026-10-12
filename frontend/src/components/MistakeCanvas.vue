<script setup lang="ts">
/**
 * 間違いノートのページ編集。問題の写真・説明用の画像を動かす／大きさを変える、文字を入力する、ペンで手書きする。
 * 座標はページ本体（NOTE_W × NOTE_H）で持ち、画面には枠の幅に合わせて拡大縮小して表示する。
 * - 選択: 画像・文字の枠をドラッグで移動、右下のつまみで大きさ変更
 * - ペン / 消しゴム: Apple Pencil・マウスで書く。指は既定ではスクロール（「指で書く」を ON にすると指でも書ける）
 * - 文字: 文字の枠に入力（PC のキーボード・iPad のソフトキーボード）
 * 変更はひとまとまりごとに update:layout で親へ渡す（親が自動保存する）。
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { DEFAULT_FONT, FONT_SIZES, LINE_HEIGHT, NOTE_H, NOTE_W, PEN_COLORS, PEN_WIDTHS, newItemId } from '@/lib/mistake'
import { isTouch } from '@/lib/native'
import { useUiStore } from '@/stores/ui'
import type { MistakeLayout, MistakeLayoutItem, MistakeStroke } from '@/types'

type Tool = 'select' | 'pen' | 'eraser' | 'text'

const props = defineProps<{ layout: MistakeLayout; urls: Record<number, string>; hasProblem: boolean }>()
const emit = defineEmits<{ 'update:layout': [layout: MistakeLayout]; removeImage: [imageId: number] }>()

const ui = useUiStore()

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

// ---- 表示倍率 ----
const wrap = ref<HTMLElement | null>(null)
const page = ref<HTMLElement | null>(null)
const s = ref(0.5)
let ro: ResizeObserver | null = null
function measure() {
  if (wrap.value) s.value = Math.max(0.1, wrap.value.clientWidth / NOTE_W)
}

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

// ---- ドラッグ（移動・大きさ・ペン・消しゴム・指のスクロール） ----
interface Drag {
  kind: 'move' | 'resize' | 'stroke' | 'erase' | 'pan'
  id: string
  x0: number
  y0: number
  ox: number
  oy: number
  ow: number
  oh: number
  aspect: number
  moved: boolean
  lastClientY: number
}
let drag: Drag | null = null
const preview = ref<{ id: string; x: number; y: number; w: number; h: number } | null>(null)
const live = ref<MistakeStroke | null>(null)
const erased = ref<Set<number>>(new Set())

function toPage(e: PointerEvent): { x: number; y: number } {
  const r = page.value!.getBoundingClientRect()
  return { x: (e.clientX - r.left) / s.value, y: (e.clientY - r.top) / s.value }
}
function scroller(): HTMLElement | null {
  return page.value?.closest<HTMLElement>('.app-scroll') ?? null
}

function onDown(e: PointerEvent) {
  if (e.pointerType === 'mouse' && e.button !== 0) return
  const target = e.target as HTMLElement
  if (tool.value === 'text' && target.closest('textarea')) return // 入力中
  const finger = e.pointerType === 'touch'
  const p = toPage(e)
  const base = { x0: p.x, y0: p.y, ox: 0, oy: 0, ow: 0, oh: 0, aspect: 1, moved: false, lastClientY: e.clientY }

  if (tool.value === 'pen' || tool.value === 'eraser') {
    if (finger && !fingerDraw.value) {
      drag = { kind: 'pan', id: '', ...base }
      return
    }
    page.value!.setPointerCapture(e.pointerId)
    if (tool.value === 'pen') {
      live.value = { color: color.value, width: width.value, points: [round(p.x), round(p.y)] }
      drag = { kind: 'stroke', id: '', ...base }
    } else {
      erased.value = new Set()
      drag = { kind: 'erase', id: '', ...base }
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
    page.value!.setPointerCapture(e.pointerId)
    drag = { kind: handle ? 'resize' : 'move', id, ...base, ox: it.x, oy: it.y, ow: it.w, oh: it.h, aspect: it.h > 0 ? it.w / it.h : 1 }
    preview.value = { id, x: it.x, y: it.y, w: it.w, h: it.h }
    return
  }
  selectedId.value = null
  if (finger) drag = { kind: 'pan', id: '', ...base }
}

function onMove(e: PointerEvent) {
  if (!drag) return
  if (drag.kind === 'pan') {
    const sc = scroller()
    if (sc) sc.scrollTop -= e.clientY - drag.lastClientY
    drag.lastClientY = e.clientY
    return
  }
  const p = toPage(e)
  const dx = p.x - drag.x0
  const dy = p.y - drag.y0
  if (!drag.moved && Math.abs(dx) * s.value < 2 && Math.abs(dy) * s.value < 2) return
  drag.moved = true
  if (drag.kind === 'stroke' && live.value) {
    const pts = live.value.points
    const lx = pts[pts.length - 2]!
    const ly = pts[pts.length - 1]!
    if (Math.hypot(p.x - lx, p.y - ly) >= 1.5) pts.push(round(p.x), round(p.y))
  } else if (drag.kind === 'erase') {
    eraseAt(p)
  } else if (drag.kind === 'move') {
    const w = drag.ow
    const h = drag.oh
    preview.value = { id: drag.id, x: clamp(drag.ox + dx, -w + 60, NOTE_W - 60), y: clamp(drag.oy + dy, -h + 60, NOTE_H - 60), w, h }
  } else if (drag.kind === 'resize') {
    const it = props.layout.items.find((x) => x.id === drag!.id)
    if (!it) return
    if (it.type === 'image') {
      const w = Math.max(60, Math.min(NOTE_W * 1.5, drag.ow + dx))
      preview.value = { id: drag.id, x: drag.ox, y: drag.oy, w, h: w / drag.aspect }
    } else {
      preview.value = { id: drag.id, x: drag.ox, y: drag.oy, w: Math.max(140, drag.ow + dx), h: Math.max(80, drag.oh + dy) }
    }
  }
}

function onUp() {
  if (!drag) return
  const d = drag
  drag = null
  if (d.kind === 'pan') return
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
function addTextBox() {
  const bottom = props.layout.items.reduce((m, it) => Math.max(m, it.y + it.h), 0)
  const y = Math.min(NOTE_H - 320, bottom + 24)
  const it: MistakeLayoutItem = { id: newItemId('t'), type: 'text', text: '', fontSize: DEFAULT_FONT, x: 0, y, w: NOTE_W, h: Math.max(300, NOTE_H - y) }
  commit({ items: [...props.layout.items, it], ink: props.layout.ink })
  selectedId.value = it.id
  tool.value = 'text'
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
  return { left: b.x * s.value + 'px', top: b.y * s.value + 'px', width: b.w * s.value + 'px', height: b.h * s.value + 'px' }
}
function pathOf(st: MistakeStroke): string {
  const p = st.points
  if (p.length < 2) return ''
  let d = `M${p[0]} ${p[1]}`
  for (let i = 2; i + 1 < p.length; i += 2) d += ` L${p[i]} ${p[i + 1]}`
  return d
}
const touchAction = computed(() => (tool.value === 'text' ? 'pan-y' : 'none'))
const cursor = computed(() => ({ select: 'default', pen: 'crosshair', eraser: 'cell', text: 'text' })[tool.value])

// ---- キーボード ----
function onKey(e: KeyboardEvent) {
  const tag = (e.target as HTMLElement)?.tagName
  const typing = tag === 'TEXTAREA' || tag === 'INPUT' || tag === 'SELECT'
  if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'z' && !typing) {
    e.preventDefault()
    undo()
  } else if ((e.key === 'Delete' || e.key === 'Backspace') && !typing && selected.value) {
    e.preventDefault()
    deleteSelected()
  } else if (e.key === 'Escape') {
    selectedId.value = null
  }
}

onMounted(() => {
  measure()
  if (wrap.value && typeof ResizeObserver !== 'undefined') {
    ro = new ResizeObserver(measure)
    ro.observe(wrap.value)
  }
  window.addEventListener('keydown', onKey)
})
onBeforeUnmount(() => {
  ro?.disconnect()
  window.removeEventListener('keydown', onKey)
})
</script>

<template>
  <div class="mc">
    <!-- 道具 -->
    <div class="tools">
      <div class="seg">
        <button class="tb" :class="{ on: tool === 'select' }" title="選択・移動" @click="tool = 'select'">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"><path d="M5 3l14 8-6 2-3 6z" /></svg>選択
        </button>
        <button class="tb" :class="{ on: tool === 'pen' }" title="ペン" @click="tool = 'pen'">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9" /><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z" /></svg>ペン
        </button>
        <button class="tb" :class="{ on: tool === 'eraser' }" title="消しゴム（手書きを消す）" @click="tool = 'eraser'">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"><path d="M7 20l-4-4 10-10 6 6-8 8z" /><path d="M9 20h12" /></svg>消す
        </button>
        <button class="tb" :class="{ on: tool === 'text' }" title="文字を入力" @click="tool = 'text'">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M5 6h14M12 6v13M9 19h6" /></svg>文字
        </button>
      </div>
      <template v-if="tool === 'pen'">
        <div class="colors">
          <button v-for="c in PEN_COLORS" :key="c" class="sw" :class="{ on: color === c }" :style="{ '--c': c }" :aria-label="c" @click="color = c"></button>
        </div>
        <div class="seg">
          <button v-for="w in PEN_WIDTHS" :key="w.width" class="tb" :class="{ on: width === w.width }" @click="width = w.width">{{ w.label }}</button>
        </div>
      </template>
      <button v-if="tool === 'eraser' && layout.ink.length" class="tb ghost" @click="clearInk">手書きをすべて消す</button>
      <label v-if="isTouch && (tool === 'pen' || tool === 'eraser')" class="chk"><input v-model="fingerDraw" type="checkbox" />指で書く</label>
      <template v-if="selected?.type === 'text'">
        <div class="seg">
          <button v-for="f in FONT_SIZES" :key="f.size" class="tb" :class="{ on: (selected.fontSize ?? DEFAULT_FONT) === f.size }" @click="setFontSize(f.size)">{{ f.label }}</button>
        </div>
      </template>
      <div style="flex: 1"></div>
      <button class="tb ghost" @click="addTextBox">＋ 文字の枠</button>
      <button v-if="canDelete" class="tb danger" @click="deleteSelected">{{ selected?.type === 'text' ? '文字の枠を削除' : '画像を削除' }}</button>
      <button class="tb ghost" :disabled="!undoStack.length" title="取り消し（Ctrl+Z）" @click="undo">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 14l-4-4 4-4" /><path d="M5 10h9a5 5 0 0 1 0 10h-3" /></svg>取り消し
      </button>
    </div>

    <!-- ページ -->
    <div ref="wrap" class="wrap">
      <div
        ref="page"
        class="mpage no-gesture"
        :class="{ ['tool-' + tool]: true }"
        :style="{ height: NOTE_H * s + 'px', touchAction, cursor }"
        @pointerdown="onDown"
        @pointermove="onMove"
        @pointerup="onUp"
        @pointercancel="onUp"
      >
        <div v-if="!hasProblem" class="placeholder">
          <div>まず対象の問題の写真を登録してください</div>
          <div class="ph-sub">上の「撮影する」「画像を選ぶ」か、PC では画像をコピーして Ctrl+V、またはここへドラッグ＆ドロップ</div>
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
            :style="{ fontSize: (it.fontSize ?? DEFAULT_FONT) * s + 'px', lineHeight: LINE_HEIGHT, padding: 6 * s + 'px' }"
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
    </div>
  </div>
</template>

<style scoped>
.mc {
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.tools {
  position: sticky;
  top: 0;
  z-index: 5;
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
  padding: 8px 10px;
  background: #fff;
  border: 1px solid var(--line);
  border-radius: 12px;
}
.seg {
  display: flex;
  background: #f1f2f4;
  border-radius: 9px;
  padding: 2px;
  gap: 2px;
}
.tb {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 6px 11px;
  border: none;
  border-radius: 7px;
  background: transparent;
  color: var(--mut);
  font-size: 12.5px;
  font-weight: 600;
  cursor: pointer;
  white-space: nowrap;
}
.tb.on {
  background: #1c2024;
  color: #fff;
}
.tb.ghost {
  border: 1px solid #e3e6ea;
  background: #fff;
}
.tb.ghost:disabled {
  opacity: 0.4;
  cursor: default;
}
.tb.danger {
  border: 1px solid #f0c3c8;
  background: #fdf3f4;
  color: #c0444f;
}
.colors {
  display: flex;
  gap: 6px;
}
.sw {
  width: 24px;
  height: 24px;
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
  align-items: center;
  gap: 5px;
  font-size: 12px;
  color: var(--mut);
  font-weight: 600;
}
.wrap {
  width: 100%;
}
.mpage {
  position: relative;
  width: 100%;
  background: #fff;
  border: 1px solid #d4d8de;
  box-shadow: 0 4px 18px rgba(0, 0, 0, 0.08);
  overflow: hidden;
  -webkit-user-select: none;
  user-select: none;
  /* 罫線（薄い方眼） */
  background-image: linear-gradient(#f3f4f6 1px, transparent 1px), linear-gradient(90deg, #f3f4f6 1px, transparent 1px);
  background-size: 48px 48px;
}
.placeholder {
  position: absolute;
  inset: 0;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 8px;
  color: var(--faint);
  font-size: 14px;
  font-weight: 600;
  text-align: center;
  padding: 20px;
  pointer-events: none;
}
.ph-sub {
  font-size: 12px;
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
  border: 1px dashed transparent;
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
  background: rgba(255, 255, 255, 0.4);
}
.tool-text .item textarea:focus {
  border-style: solid;
  border-color: var(--primary);
}
.tool-select .item:hover {
  outline: 1px dashed #b9c2f2;
}
.item.sel {
  outline: 2px solid var(--primary);
  outline-offset: -1px;
}
.tag {
  position: absolute;
  left: 6px;
  top: 6px;
  font-size: 10px;
  font-weight: 700;
  color: #fff;
  background: rgba(28, 32, 36, 0.7);
  border-radius: 4px;
  padding: 1px 6px;
  pointer-events: none;
}
.hdl {
  position: absolute;
  right: -10px;
  bottom: -10px;
  width: 22px;
  height: 22px;
  border-radius: 50%;
  background: var(--primary);
  border: 3px solid #fff;
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
}
.tool-pen .item,
.tool-eraser .item {
  pointer-events: none;
}
</style>
