<script setup lang="ts">
/**
 * 画像のトリミング（間違いノート）。元の画像は変えず、見せる範囲（元の画像に対する割合 0〜1）を決める。
 * 枠の中をドラッグで移動、角・辺のつまみで大きさ変更。「全体に戻す」でトリミングなしに。
 * done には枠（全体なら null）と元の画像の大きさを返す（呼び出し側が枠の縦横比を合わせる）。
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import type { ImageCrop } from '@/types'

const props = defineProps<{ src: string; crop: ImageCrop | null }>()
const emit = defineEmits<{ done: [crop: ImageCrop | null, natural: { w: number; h: number }]; cancel: [] }>()

const area = ref<HTMLElement | null>(null)
const areaW = ref(0)
const areaH = ref(0)
const nat = ref<{ w: number; h: number } | null>(null)
/** 表示している画像の位置・大きさ（area 内の px。縦横比を保って収める） */
const view = computed(() => {
  if (!nat.value || !areaW.value || !areaH.value) return null
  const s = Math.min(areaW.value / nat.value.w, areaH.value / nat.value.h)
  const w = nat.value.w * s
  const h = nat.value.h * s
  return { x: (areaW.value - w) / 2, y: (areaH.value - h) / 2, w, h }
})
const imgStyle = computed(() => {
  const v = view.value
  return v ? { left: v.x + 'px', top: v.y + 'px', width: v.w + 'px', height: v.h + 'px' } : { opacity: 0 }
})
/** 枠（割合） */
const c = ref<ImageCrop>(props.crop ? { ...props.crop } : { x: 0, y: 0, w: 1, h: 1 })
const MIN = 0.05
const boxStyle = computed(() => {
  const v = view.value
  if (!v) return {}
  return { left: v.x + c.value.x * v.w + 'px', top: v.y + c.value.y * v.h + 'px', width: c.value.w * v.w + 'px', height: c.value.h * v.h + 'px' }
})
const isFull = computed(() => c.value.x <= 0.001 && c.value.y <= 0.001 && c.value.w >= 0.998 && c.value.h >= 0.998)

function onLoad(e: Event) {
  const img = e.target as HTMLImageElement
  nat.value = { w: img.naturalWidth, h: img.naturalHeight }
}
let ro: ResizeObserver | null = null
function measure() {
  if (!area.value) return
  areaW.value = area.value.clientWidth
  areaH.value = area.value.clientHeight
}
function onKey(e: KeyboardEvent) {
  if (e.key === 'Escape') emit('cancel')
  else if (e.key === 'Enter') finish()
}
onMounted(() => {
  measure()
  if (area.value && typeof ResizeObserver !== 'undefined') {
    ro = new ResizeObserver(measure)
    ro.observe(area.value)
  }
  window.addEventListener('keydown', onKey)
})
onBeforeUnmount(() => {
  ro?.disconnect()
  window.removeEventListener('keydown', onKey)
})

// ---- ドラッグ（枠の移動・つまみ） ----
type Mode = 'move' | 'n' | 's' | 'e' | 'w' | 'ne' | 'nw' | 'se' | 'sw'
const HANDLES: Mode[] = ['nw', 'n', 'ne', 'e', 'se', 's', 'sw', 'w']
let drag: { mode: Mode; pointerId: number; x0: number; y0: number; c0: ImageCrop } | null = null
function down(e: PointerEvent, mode: Mode) {
  if (!view.value) return
  e.preventDefault()
  ;(e.currentTarget as HTMLElement).setPointerCapture(e.pointerId)
  drag = { mode, pointerId: e.pointerId, x0: e.clientX, y0: e.clientY, c0: { ...c.value } }
}
function move(e: PointerEvent) {
  const v = view.value
  if (!drag || drag.pointerId !== e.pointerId || !v) return
  const dx = (e.clientX - drag.x0) / v.w
  const dy = (e.clientY - drag.y0) / v.h
  const o = drag.c0
  let { x, y, w, h } = o
  const m = drag.mode
  if (m === 'move') {
    x = clamp(o.x + dx, 0, 1 - o.w)
    y = clamp(o.y + dy, 0, 1 - o.h)
  } else {
    if (m.includes('w')) {
      x = clamp(o.x + dx, 0, o.x + o.w - MIN)
      w = o.x + o.w - x
    }
    if (m.includes('e')) w = clamp(o.w + dx, MIN, 1 - o.x)
    if (m.includes('n')) {
      y = clamp(o.y + dy, 0, o.y + o.h - MIN)
      h = o.y + o.h - y
    }
    if (m.includes('s')) h = clamp(o.h + dy, MIN, 1 - o.y)
  }
  c.value = { x, y, w, h }
}
function up(e: PointerEvent) {
  if (drag?.pointerId === e.pointerId) drag = null
}
const clamp = (v: number, lo: number, hi: number) => Math.max(lo, Math.min(hi, v))
const r4 = (n: number) => Math.round(n * 10000) / 10000

function reset() {
  c.value = { x: 0, y: 0, w: 1, h: 1 }
}
function finish() {
  if (!nat.value) return
  const r = c.value
  emit('done', isFull.value ? null : { x: r4(r.x), y: r4(r.y), w: r4(r.w), h: r4(r.h) }, nat.value)
}
</script>

<template>
  <Teleport to="body">
    <div class="cr ui-overlay">
      <div class="cr-panel ui-panel ui-flush">
        <div class="cr-head">
          <div class="cr-title">画像をトリミング</div>
          <div class="cr-sub">枠の中をドラッグで移動、角や辺のつまみで大きさを変えます（元の画像は残ります）</div>
        </div>
        <div ref="area" class="cr-area" @pointermove="move" @pointerup="up" @pointercancel="up">
          <img :src="src" alt="" draggable="false" :style="imgStyle" @load="onLoad" />
          <div v-if="view" class="cr-box" :style="boxStyle" @pointerdown="down($event, 'move')">
            <div class="cr-grid"></div>
            <span v-for="hd in HANDLES" :key="hd" class="cr-h" :class="hd" @pointerdown.stop="down($event, hd)"></span>
          </div>
          <div v-if="!view" class="cr-loading">読み込み中…</div>
        </div>
        <div class="cr-foot">
          <button class="cr-btn" :disabled="isFull" @click="reset">全体に戻す</button>
          <span class="cr-sp"></span>
          <button class="cr-btn" @click="emit('cancel')">キャンセル</button>
          <button class="cr-btn primary" :disabled="!nat" @click="finish">決定</button>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.cr {
  position: fixed;
  inset: 0;
  z-index: 400;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: max(10px, env(safe-area-inset-top)) max(10px, env(safe-area-inset-right)) max(10px, env(safe-area-inset-bottom)) max(10px, env(safe-area-inset-left));
  background: rgba(0, 0, 0, 0.62);
  -webkit-user-select: none;
  user-select: none;
  -webkit-touch-callout: none;
  touch-action: none;
}
.cr-panel {
  width: min(100%, 960px);
  height: min(100%, 800px);
  display: flex;
  flex-direction: column;
  background: #1c2024;
  color: #fff;
  border-radius: 14px;
  overflow: hidden;
  box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
}
.cr-head {
  padding: 12px 16px 10px;
  flex-shrink: 0;
}
.cr-title {
  font-size: 14px;
  font-weight: 700;
}
.cr-sub {
  margin-top: 2px;
  font-size: 11.5px;
  color: #b7bcc5;
}
.cr-area {
  position: relative;
  flex: 1;
  min-height: 0;
  background: #0f1113;
  overflow: hidden;
  touch-action: none;
}
.cr-area img {
  position: absolute;
  display: block;
  object-fit: fill;
  pointer-events: none;
}
.cr-loading {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #9aa1ab;
  font-size: 13px;
}
/* 枠。外側は影で暗くする */
.cr-box {
  position: absolute;
  box-sizing: border-box;
  border: 2px solid #fff;
  box-shadow: 0 0 0 9999px rgba(0, 0, 0, 0.55);
  cursor: move;
  touch-action: none;
}
.cr-grid {
  position: absolute;
  inset: 0;
  background-image: linear-gradient(rgba(255, 255, 255, 0.35) 1px, transparent 1px), linear-gradient(90deg, rgba(255, 255, 255, 0.35) 1px, transparent 1px);
  background-size: 33.34% 33.34%;
  pointer-events: none;
}
/* つまみ（指で掴める 32px。見た目は中の白い丸） */
.cr-h {
  position: absolute;
  width: 32px;
  height: 32px;
  margin: -16px 0 0 -16px;
  touch-action: none;
}
.cr-h::after {
  content: '';
  position: absolute;
  inset: 9px;
  border-radius: 50%;
  background: #fff;
  box-shadow: 0 0 0 1.5px rgba(0, 0, 0, 0.35);
}
.cr-h.nw {
  left: 0;
  top: 0;
  cursor: nwse-resize;
}
.cr-h.n {
  left: 50%;
  top: 0;
  cursor: ns-resize;
}
.cr-h.ne {
  left: 100%;
  top: 0;
  cursor: nesw-resize;
}
.cr-h.e {
  left: 100%;
  top: 50%;
  cursor: ew-resize;
}
.cr-h.se {
  left: 100%;
  top: 100%;
  cursor: nwse-resize;
}
.cr-h.s {
  left: 50%;
  top: 100%;
  cursor: ns-resize;
}
.cr-h.sw {
  left: 0;
  top: 100%;
  cursor: nesw-resize;
}
.cr-h.w {
  left: 0;
  top: 50%;
  cursor: ew-resize;
}
.cr-foot {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px 12px;
  flex-shrink: 0;
  border-top: 1px solid rgba(255, 255, 255, 0.1);
}
.cr-sp {
  flex: 1;
}
.cr-btn {
  padding: 9px 16px;
  border: 1px solid rgba(255, 255, 255, 0.25);
  border-radius: 10px;
  background: transparent;
  color: #fff;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
}
.cr-btn:disabled {
  opacity: 0.4;
  cursor: default;
}
.cr-btn.primary {
  background: #fff;
  color: #1c2024;
  border-color: #fff;
}
</style>
