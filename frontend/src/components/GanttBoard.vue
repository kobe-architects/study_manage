<script setup lang="ts">
/**
 * ガントチャート本体。左に項目名、右に年／月（倍率により週・日）の目盛りとバーを表示する。
 * - バーをドラッグ: 期間ごと移動。両端のつまみをドラッグ: 開始日／終了日を変更（1 日単位にスナップ）
 * - バー・項目名をクリック（タップ）: 編集
 * - 項目名の左の取っ手を上下にドラッグ: 並び替え
 * - 空いている場所をダブルクリック: その日から始まる項目を追加
 * スクロールは 1 つの枠で縦横とも行い、目盛りは上に、項目名は左に固定表示する。
 */
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { daysBetween, hexA, parseDate } from '@/lib/design'
import { GANTT_ZOOMS, addDays, isoAt, todayIso, type GanttZoom } from '@/lib/gantt'
import { haptic, viewportWidth } from '@/lib/native'
import type { GanttTask } from '@/types'

const props = defineProps<{ tasks: GanttTask[]; startOn: string; endOn: string; zoom: GanttZoom }>()
const emit = defineEmits<{
  move: [task: GanttTask, startOn: string, endOn: string]
  reorder: [ids: number[]]
  edit: [task: GanttTask]
  add: [startOn: string]
}>()

const ROW_H = 44

const scroller = ref<HTMLElement | null>(null)
const bodyEl = ref<HTMLElement | null>(null)

const dayW = computed(() => GANTT_ZOOMS.find((z) => z.key === props.zoom)?.dayW ?? 3.2)
const rangeStart = computed(() => parseDate(props.startOn))
const rangeEnd = computed(() => parseDate(props.endOn))
const totalDays = computed(() => Math.max(1, daysBetween(rangeStart.value, rangeEnd.value) + 1))
const totalW = computed(() => Math.round(totalDays.value * dayW.value))
const nameW = computed(() => (viewportWidth.value < 600 ? 150 : 240))

const idx = (isoDate: string) => daysBetween(rangeStart.value, parseDate(isoDate))

// ---- 目盛り ----
interface Seg {
  key: string
  left: number
  width: number
  label: string
  strong?: boolean
  weekend?: boolean
}

const years = computed<Seg[]>(() => {
  const out: Seg[] = []
  let d = new Date(rangeStart.value)
  while (d <= rangeEnd.value) {
    const y = d.getFullYear()
    const yEnd = new Date(y, 11, 31)
    const segEnd = yEnd < rangeEnd.value ? yEnd : rangeEnd.value
    const width = (daysBetween(d, segEnd) + 1) * dayW.value
    out.push({ key: String(y), left: daysBetween(rangeStart.value, d) * dayW.value, width, label: width >= 44 ? `${y}年` : '', strong: true })
    d = new Date(y + 1, 0, 1)
  }
  return out
})

const months = computed<Seg[]>(() => {
  const out: Seg[] = []
  let d = new Date(rangeStart.value)
  while (d <= rangeEnd.value) {
    const y = d.getFullYear()
    const m = d.getMonth()
    const mEnd = new Date(y, m + 1, 0)
    const segEnd = mEnd < rangeEnd.value ? mEnd : rangeEnd.value
    const width = (daysBetween(d, segEnd) + 1) * dayW.value
    const label = width >= 34 ? `${m + 1}月` : width >= 16 ? String(m + 1) : ''
    out.push({ key: `${y}-${m}`, left: daysBetween(rangeStart.value, d) * dayW.value, width, label, strong: m === 0 })
    d = new Date(y, m + 1, 1)
  }
  return out
})

/** 日の目盛り（週表示）／週の目盛り（月表示） */
const showDays = computed(() => dayW.value >= 16)
const showWeeks = computed(() => !showDays.value && dayW.value >= 6)

const days = computed<Seg[]>(() => {
  if (!showDays.value) return []
  const out: Seg[] = []
  for (let i = 0; i < totalDays.value; i++) {
    const d = addDays(rangeStart.value, i)
    const dow = d.getDay()
    out.push({ key: String(i), left: i * dayW.value, width: dayW.value, label: String(d.getDate()), weekend: dow === 0 || dow === 6, strong: d.getDate() === 1 })
  }
  return out
})

const weeks = computed<Seg[]>(() => {
  if (!showWeeks.value) return []
  const out: Seg[] = []
  let i = 0
  while (i < totalDays.value) {
    const d = addDays(rangeStart.value, i)
    // 次の月曜の前日まで
    const toMon = ((8 - d.getDay()) % 7) || 7
    const len = Math.min(toMon, totalDays.value - i)
    const width = len * dayW.value
    out.push({ key: String(i), left: i * dayW.value, width, label: width >= 30 ? `${d.getMonth() + 1}/${d.getDate()}` : '', strong: false })
    i += len
  }
  return out
})

/** 土日の帯（週表示のみ） */
const weekendShades = computed(() => days.value.filter((d) => d.weekend))

const todayIdx = computed(() => idx(todayIso()))
const todayVisible = computed(() => todayIdx.value >= 0 && todayIdx.value < totalDays.value)
const todayLeft = computed(() => todayIdx.value * dayW.value)

// ---- バー ----
interface Bar {
  task: GanttTask
  /** ドラッグ中のプレビューを含む開始・終了インデックス */
  s: number
  e: number
  left: number
  width: number
  outside: boolean
  clipL: boolean
  clipR: boolean
}

const drag = reactive({ id: 0, mode: 'move' as 'move' | 'l' | 'r', x0: 0, lastX: 0, ds: 0, de: 0, s0: 0, e0: 0, moved: false })

const bars = computed<Bar[]>(() =>
  props.tasks.map((t) => {
    let s = idx(t.startOn)
    let e = idx(t.endOn)
    if (drag.id === t.id) {
      s += drag.ds
      e += drag.de
    }
    const cs = Math.max(0, s)
    const ce = Math.min(totalDays.value - 1, e)
    return {
      task: t,
      s,
      e,
      left: cs * dayW.value,
      width: Math.max(dayW.value, (ce - cs + 1) * dayW.value),
      outside: ce < cs,
      clipL: s < 0,
      clipR: e > totalDays.value - 1,
    }
  }),
)

function fmt(i: number): string {
  const d = addDays(rangeStart.value, i)
  return `${d.getFullYear()}/${d.getMonth() + 1}/${d.getDate()}`
}
function rangeLabel(b: Bar): string {
  if (b.task.kind === 'milestone') return fmt(b.s)
  return `${fmt(b.s)} 〜 ${fmt(b.e)}・${b.e - b.s + 1}日`
}

/** スクロール枠内の X 座標（スクロール量込み） */
function contentX(clientX: number): number {
  const el = scroller.value
  if (!el) return clientX
  return clientX - el.getBoundingClientRect().left + el.scrollLeft
}

function onBarDown(e: PointerEvent, t: GanttTask, mode: 'move' | 'l' | 'r') {
  if (e.pointerType === 'mouse' && e.button !== 0) return
  if (rowDrag.id) return
  const bar = (e.currentTarget as HTMLElement).closest<HTMLElement>('.gbar, .gms')
  if (!bar) return
  bar.setPointerCapture(e.pointerId)
  Object.assign(drag, { id: t.id, mode, x0: contentX(e.clientX), lastX: e.clientX, ds: 0, de: 0, s0: idx(t.startOn), e0: idx(t.endOn), moved: false })
}

function applyDrag(clientX: number) {
  const dx = contentX(clientX) - drag.x0
  if (!drag.moved && Math.abs(dx) < 4) return
  drag.moved = true
  let d = Math.round(dx / dayW.value)
  const len = drag.e0 - drag.s0
  const last = totalDays.value - 1
  if (drag.mode === 'move') {
    d = Math.max(-drag.s0, Math.min(last - drag.e0, d))
    drag.ds = d
    drag.de = d
  } else if (drag.mode === 'l') {
    d = Math.max(-drag.s0, Math.min(len, d))
    drag.ds = d
  } else {
    d = Math.max(-len, Math.min(last - drag.e0, d))
    drag.de = d
  }
}

function onBarMove(e: PointerEvent) {
  if (!drag.id) return
  drag.lastX = e.clientX
  applyDrag(e.clientX)
  autoScroll(e.clientX)
}

function onBarUp() {
  if (!drag.id) return
  const { moved, ds, de } = drag
  const task = props.tasks.find((x) => x.id === drag.id)
  const s = drag.s0 + ds
  const e = drag.e0 + de
  drag.id = 0
  stopAutoScroll()
  if (!task) return
  if (moved) {
    if (ds || de) {
      haptic()
      emit('move', task, isoAt(rangeStart.value, s), isoAt(rangeStart.value, e))
    }
  } else {
    emit('edit', task)
  }
}

// 端に近づいたら横に自動スクロール（ドラッグ中）
let asRaf = 0
let asDir = 0
function autoScroll(clientX: number) {
  const el = scroller.value
  if (!el) return
  const r = el.getBoundingClientRect()
  asDir = clientX < r.left + nameW.value + 28 ? -1 : clientX > r.right - 28 ? 1 : 0
  if (asDir && !asRaf) asRaf = requestAnimationFrame(asStep)
}
function asStep() {
  const el = scroller.value
  if (!asDir || !el || !drag.id) {
    asRaf = 0
    return
  }
  el.scrollLeft += asDir * 10
  applyDrag(drag.lastX)
  asRaf = requestAnimationFrame(asStep)
}
function stopAutoScroll() {
  asDir = 0
  if (asRaf) cancelAnimationFrame(asRaf)
  asRaf = 0
}

// ---- 並び替え（左の取っ手を上下にドラッグ） ----
const rowDrag = reactive({ id: 0, from: -1, to: -1, y0: 0, active: false, sx: 0 })

function onHandleDown(e: PointerEvent, i: number) {
  if (e.pointerType === 'mouse' && e.button !== 0) return
  const t = props.tasks[i]
  if (!t) return
  ;(e.currentTarget as HTMLElement).setPointerCapture(e.pointerId)
  Object.assign(rowDrag, { id: t.id, from: i, to: i, y0: e.clientY, active: false })
}
function onHandleMove(e: PointerEvent) {
  if (!rowDrag.id || !bodyEl.value) return
  if (!rowDrag.active && Math.abs(e.clientY - rowDrag.y0) < 4) return
  rowDrag.active = true
  rowDrag.sx = scroller.value?.scrollLeft ?? 0
  const y = e.clientY - bodyEl.value.getBoundingClientRect().top
  rowDrag.to = Math.max(0, Math.min(props.tasks.length, Math.round(y / ROW_H)))
}
function onHandleUp() {
  if (!rowDrag.id) return
  const { from, to, active } = rowDrag
  rowDrag.id = 0
  rowDrag.active = false
  if (!active || to === from || to === from + 1) return
  const ids = props.tasks.map((t) => t.id)
  const [moved] = ids.splice(from, 1)
  if (moved === undefined) return
  ids.splice(to > from ? to - 1 : to, 0, moved)
  haptic()
  emit('reorder', ids)
}

// ---- 空いている場所をダブルクリックで追加 ----
function onCellsDblClick(e: MouseEvent) {
  if (e.target !== e.currentTarget) return
  const r = (e.currentTarget as HTMLElement).getBoundingClientRect()
  const i = Math.max(0, Math.min(totalDays.value - 1, Math.floor((e.clientX - r.left) / dayW.value)))
  emit('add', isoAt(rangeStart.value, i))
}

// ---- スクロール位置 ----
function scrollToDate(isoDate: string, ratio = 0.25) {
  const el = scroller.value
  if (!el) return
  const view = el.clientWidth - nameW.value
  el.scrollLeft = Math.max(0, idx(isoDate) * dayW.value - view * ratio)
}
function scrollToToday() {
  scrollToDate(todayIso())
}
defineExpose({ scrollToToday, scrollToDate })

// 倍率を変えても画面中央の日付が動かないようにする
watch(
  () => props.zoom,
  (_z, oldZ) => {
    const el = scroller.value
    if (!el) return
    const oldW = GANTT_ZOOMS.find((z) => z.key === oldZ)?.dayW ?? dayW.value
    const view = el.clientWidth - nameW.value
    const centerIdx = (el.scrollLeft + view / 2) / oldW
    nextTick(() => {
      el.scrollLeft = Math.max(0, centerIdx * dayW.value - view / 2)
    })
  },
)

// 枠の高さ: 画面の下端まで（中身が少ないときは中身の高さ）
const maxH = ref(600)
function measure() {
  const el = scroller.value
  if (!el) return
  const top = el.getBoundingClientRect().top
  maxH.value = Math.max(320, window.innerHeight - top - 28)
}
onMounted(() => {
  measure()
  window.addEventListener('resize', measure)
  nextTick(scrollToToday)
})
onBeforeUnmount(() => {
  window.removeEventListener('resize', measure)
  stopAutoScroll()
})
</script>

<template>
  <div
    ref="scroller"
    class="gantt no-gesture"
    :class="{ 'is-drag': drag.id !== 0, 'is-rowdrag': rowDrag.active }"
    :style="{ maxHeight: maxH + 'px', '--name-w': nameW + 'px', '--row-h': ROW_H + 'px' }"
  >
    <!-- 目盛り（上に固定）。左上の角を左に固定し続けるため、幅は本体と同じにする -->
    <div class="ghead" :style="{ width: nameW + totalW + 'px' }">
      <div class="gcorner">
        <span>項目</span>
        <span class="cnt">{{ tasks.length }}</span>
      </div>
      <div class="gscale" :style="{ width: totalW + 'px' }">
        <div class="srow years">
          <div v-for="y in years" :key="y.key" class="seg strong" :style="{ left: y.left + 'px', width: y.width + 'px' }">{{ y.label }}</div>
        </div>
        <div class="srow months" :class="{ last: !showDays && !showWeeks }">
          <div v-for="m in months" :key="m.key" class="seg" :class="{ strong: m.strong }" :style="{ left: m.left + 'px', width: m.width + 'px' }">{{ m.label }}</div>
        </div>
        <div v-if="showDays || showWeeks" class="srow sub last">
          <div
            v-for="d in showDays ? days : weeks"
            :key="d.key"
            class="seg"
            :class="{ strong: d.strong, weekend: d.weekend }"
            :style="{ left: d.left + 'px', width: d.width + 'px' }"
          >
            {{ d.label }}
          </div>
        </div>
        <div v-if="todayVisible" class="today-tag" :style="{ left: todayLeft + dayW / 2 + 'px' }">今日</div>
      </div>
    </div>

    <!-- 本体 -->
    <div ref="bodyEl" class="gbody" :style="{ width: nameW + totalW + 'px', minHeight: Math.max(1, tasks.length) * ROW_H + 'px' }">
      <!-- 罫線・土日・今日 -->
      <div class="ggrid" :style="{ left: nameW + 'px', width: totalW + 'px' }">
        <div v-for="w in weekendShades" :key="'w' + w.key" class="wk" :style="{ left: w.left + 'px', width: w.width + 'px' }"></div>
        <div v-for="m in months" :key="m.key" class="vline" :class="{ strong: m.strong }" :style="{ left: m.left + 'px' }"></div>
        <template v-if="showDays">
          <div v-for="d in days" :key="'d' + d.key" class="vline day" :style="{ left: d.left + 'px' }"></div>
        </template>
        <div v-if="todayVisible" class="today" :style="{ left: todayLeft + dayW / 2 + 'px' }"></div>
      </div>

      <div
        v-for="(b, i) in bars"
        :key="b.task.id"
        class="grow"
        :class="{ dragging: drag.id === b.task.id, lifted: rowDrag.active && rowDrag.id === b.task.id }"
      >
        <div class="gname">
          <button
            class="handle"
            title="ドラッグで並び替え"
            aria-label="並び替え"
            @pointerdown="onHandleDown($event, i)"
            @pointermove="onHandleMove"
            @pointerup="onHandleUp"
            @pointercancel="onHandleUp"
          >
            <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><circle cx="9" cy="6" r="1.7" /><circle cx="15" cy="6" r="1.7" /><circle cx="9" cy="12" r="1.7" /><circle cx="15" cy="12" r="1.7" /><circle cx="9" cy="18" r="1.7" /><circle cx="15" cy="18" r="1.7" /></svg>
          </button>
          <span class="dot" :class="{ ms: b.task.kind === 'milestone' }" :style="{ background: b.task.color }"></span>
          <button class="ntext" @click="emit('edit', b.task)">
            <span class="ntitle">{{ b.task.title }}</span>
            <span class="nsub">{{ rangeLabel(b) }}</span>
          </button>
          <span v-if="b.task.kind !== 'milestone'" class="npct dm" :style="{ color: b.task.progress >= 100 ? '#2e9d62' : undefined }">{{ b.task.progress }}%</span>
        </div>

        <div class="gcells" :style="{ width: totalW + 'px' }" @dblclick="onCellsDblClick">
          <template v-if="!b.outside">
            <!-- マイルストーン（ひし形） -->
            <div
              v-if="b.task.kind === 'milestone'"
              class="gms"
              :style="{ left: b.left + dayW / 2 + 'px', '--c': b.task.color }"
              :title="`${b.task.title}（${fmt(b.s)}）`"
              @pointerdown="onBarDown($event, b.task, 'move')"
              @pointermove="onBarMove"
              @pointerup="onBarUp"
              @pointercancel="onBarUp"
            >
              <span class="gms-shape"></span>
              <span class="gms-label">{{ b.task.title }}</span>
            </div>
            <!-- 期間のバー -->
            <template v-else>
              <div
                class="gbar"
                :class="{ clipl: b.clipL, clipr: b.clipR, done: b.task.progress >= 100 }"
                :style="{ left: b.left + 'px', width: b.width + 'px', '--c': b.task.color, '--c-soft': hexA(b.task.color, 0.22) }"
                :title="`${b.task.title}\n${rangeLabel(b)}`"
                @pointerdown="onBarDown($event, b.task, 'move')"
                @pointermove="onBarMove"
                @pointerup="onBarUp"
                @pointercancel="onBarUp"
              >
                <div class="gfill" :style="{ width: b.task.progress + '%' }"></div>
                <span v-if="b.width >= 56" class="glabel">{{ b.task.title }}</span>
                <span class="gh l" @pointerdown.stop="onBarDown($event, b.task, 'l')"></span>
                <span class="gh r" @pointerdown.stop="onBarDown($event, b.task, 'r')"></span>
              </div>
              <span v-if="b.width < 56" class="gout" :style="{ left: b.left + b.width + 6 + 'px' }">{{ b.task.title }}</span>
            </template>
          </template>
          <!-- 表示期間の外にある項目 -->
          <span v-else class="gout muted" :style="{ left: (b.e < 0 ? 8 : Math.max(8, totalW - 160)) + 'px' }">
            {{ b.e < 0 ? '← 表示期間より前' : '表示期間より後 →' }}
          </span>
        </div>
      </div>

      <!-- 並び替え中の挿入位置（横スクロール中でも見える位置に出す） -->
      <div v-if="rowDrag.active" class="ins" :style="{ top: rowDrag.to * ROW_H + 'px', left: rowDrag.sx + 'px', width: nameW + 'px' }"></div>

      <div v-if="!tasks.length" class="grow empty-row">
        <div class="gempty" :style="{ left: nameW + 'px' }">項目がまだありません。「項目を追加」か、この辺りをダブルクリックして追加してください。</div>
        <div class="gcells" :style="{ width: totalW + 'px' }" @dblclick="onCellsDblClick"></div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.gantt {
  position: relative;
  overflow: auto;
  background: #fff;
  border: 1px solid var(--line);
  border-radius: 14px;
  -webkit-user-select: none;
  user-select: none;
  overscroll-behavior: contain;
}
.gantt.is-drag {
  cursor: grabbing;
}
.gantt.is-drag .gbar,
.gantt.is-drag .gh,
.gantt.is-drag .gms {
  cursor: grabbing;
}
.gantt.is-rowdrag {
  cursor: ns-resize;
}

/* ---- 目盛り ---- */
.ghead {
  position: sticky;
  top: 0;
  z-index: 5;
  display: flex;
  background: #fff;
  border-bottom: 1px solid #e3e6ea;
}
.gcorner {
  position: sticky;
  left: 0;
  z-index: 6;
  flex-shrink: 0;
  width: var(--name-w);
  display: flex;
  align-items: flex-end;
  gap: 6px;
  padding: 0 12px 7px 14px;
  background: #fff;
  border-right: 1px solid #e3e6ea;
  font-size: 12px;
  font-weight: 700;
  color: var(--mut);
}
.gcorner .cnt {
  font-size: 11px;
  font-weight: 700;
  color: #fff;
  background: #c3c8d0;
  border-radius: 99px;
  padding: 0 7px;
  line-height: 17px;
}
.gscale {
  position: relative;
  flex-shrink: 0;
}
.srow {
  position: relative;
  height: 24px;
}
.srow.years {
  height: 22px;
}
.srow.sub {
  height: 20px;
}
.seg {
  position: absolute;
  top: 0;
  bottom: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  overflow: hidden;
  white-space: nowrap;
  font-size: 11px;
  color: var(--mut);
  border-left: 1px solid #eceef1;
}
.years .seg {
  font-weight: 700;
  font-size: 12px;
  color: var(--ink);
  justify-content: flex-start;
  padding-left: 8px;
  border-left-color: #cfd4db;
}
.seg.strong {
  border-left-color: #cfd4db;
}
.sub .seg {
  font-size: 10px;
  color: var(--faint);
}
.sub .seg.weekend {
  background: #f6f7f9;
  color: #b0b6bf;
}
.today-tag {
  position: absolute;
  bottom: 2px;
  transform: translateX(-50%);
  font-size: 10px;
  font-weight: 700;
  color: #fff;
  background: #e0533d;
  border-radius: 99px;
  padding: 0 6px;
  line-height: 15px;
  pointer-events: none;
}

/* ---- 本体 ---- */
.gbody {
  position: relative;
}
.ggrid {
  position: absolute;
  top: 0;
  bottom: 0;
  pointer-events: none;
  z-index: 0;
}
.vline {
  position: absolute;
  top: 0;
  bottom: 0;
  width: 1px;
  background: #eef0f3;
}
.vline.strong {
  background: #d4d8de;
}
.vline.day {
  background: #f4f5f7;
}
.wk {
  position: absolute;
  top: 0;
  bottom: 0;
  background: #f8f9fb;
}
.today {
  position: absolute;
  top: 0;
  bottom: 0;
  width: 2px;
  margin-left: -1px;
  background: #e0533d;
  opacity: 0.75;
}

.grow {
  position: relative;
  z-index: 1;
  display: flex;
  height: var(--row-h);
  border-bottom: 1px solid #f0f1f3;
}
.grow:hover .gname {
  background: #fafbfc;
}
.grow.lifted {
  opacity: 0.45;
}
.gname {
  position: sticky;
  left: 0;
  z-index: 2;
  flex-shrink: 0;
  width: var(--name-w);
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 0 10px 0 4px;
  background: #fff;
  border-right: 1px solid #e3e6ea;
}
.handle {
  flex-shrink: 0;
  width: 22px;
  height: 32px;
  border: none;
  background: transparent;
  color: #c3c8d0;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: grab;
  touch-action: none;
  border-radius: 6px;
  padding: 0;
}
.handle:hover {
  color: #8a919c;
  background: #f1f2f4;
}
.dot {
  flex-shrink: 0;
  width: 9px;
  height: 9px;
  border-radius: 50%;
}
.dot.ms {
  border-radius: 2px;
  transform: rotate(45deg);
  width: 8px;
  height: 8px;
}
.ntext {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  border: none;
  background: transparent;
  padding: 0;
  text-align: left;
  cursor: pointer;
  line-height: 1.3;
}
.ntitle {
  max-width: 100%;
  font-size: 12.5px;
  font-weight: 600;
  color: var(--ink);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.nsub {
  max-width: 100%;
  font-size: 10px;
  color: var(--faint);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  font-variant-numeric: tabular-nums;
}
.npct {
  flex-shrink: 0;
  font-size: 11px;
  font-weight: 700;
  color: var(--mut);
}

.gcells {
  position: relative;
  flex-shrink: 0;
  height: 100%;
}

/* バー */
.gbar {
  position: absolute;
  top: 9px;
  height: 26px;
  border-radius: 7px;
  background: var(--c-soft);
  border: 1.5px solid var(--c);
  cursor: grab;
  touch-action: none;
  overflow: hidden;
  display: flex;
  align-items: center;
  transition: box-shadow 0.15s ease;
}
.gbar:hover {
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.14);
}
.grow.dragging .gbar,
.grow.dragging .gms {
  box-shadow: 0 6px 18px rgba(0, 0, 0, 0.2);
  z-index: 3;
}
.gbar.clipl {
  border-left-style: dashed;
  border-top-left-radius: 0;
  border-bottom-left-radius: 0;
}
.gbar.clipr {
  border-right-style: dashed;
  border-top-right-radius: 0;
  border-bottom-right-radius: 0;
}
.gfill {
  position: absolute;
  left: 0;
  top: 0;
  bottom: 0;
  background: var(--c);
  opacity: 0.85;
  pointer-events: none;
}
.gbar.done .gfill {
  opacity: 1;
}
.glabel {
  position: relative;
  z-index: 1;
  padding: 0 10px;
  font-size: 11.5px;
  font-weight: 600;
  color: var(--ink);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  pointer-events: none;
  /* 進捗の塗りの上でも読めるように */
  text-shadow: 0 0 3px rgba(255, 255, 255, 0.9), 0 0 1px rgba(255, 255, 255, 0.9);
}
.gh {
  position: absolute;
  top: 0;
  bottom: 0;
  width: 10px;
  cursor: ew-resize;
  touch-action: none;
  z-index: 2;
}
.gh.l {
  left: 0;
}
.gh.r {
  right: 0;
}
.gh::after {
  content: '';
  position: absolute;
  top: 7px;
  bottom: 7px;
  left: 3px;
  width: 2px;
  border-radius: 1px;
  background: var(--c);
  opacity: 0;
  transition: opacity 0.12s;
}
.gh.r::after {
  left: auto;
  right: 3px;
}
.gbar:hover .gh::after {
  opacity: 0.7;
}
.gout {
  position: absolute;
  top: 0;
  height: 100%;
  display: flex;
  align-items: center;
  font-size: 11.5px;
  font-weight: 600;
  color: var(--ink);
  white-space: nowrap;
  pointer-events: none;
}
.gout.muted {
  color: var(--faint);
  font-weight: 500;
}

/* マイルストーン */
.gms {
  position: absolute;
  top: 0;
  height: 100%;
  display: flex;
  align-items: center;
  gap: 8px;
  transform: translateX(-11px);
  cursor: grab;
  touch-action: none;
  padding-right: 6px;
}
.gms-shape {
  width: 16px;
  height: 16px;
  margin: 0 3px;
  background: var(--c);
  border-radius: 3px;
  transform: rotate(45deg);
  box-shadow: 0 1px 4px rgba(0, 0, 0, 0.18);
  flex-shrink: 0;
}
.gms-label {
  font-size: 11.5px;
  font-weight: 600;
  color: var(--ink);
  white-space: nowrap;
}

.ins {
  position: absolute;
  left: 0;
  z-index: 4;
  height: 3px;
  margin-top: -1px;
  background: var(--primary);
  border-radius: 2px;
  pointer-events: none;
}
.ins::before {
  content: '';
  position: absolute;
  left: 6px;
  top: -4px;
  width: 11px;
  height: 11px;
  border-radius: 50%;
  background: var(--primary);
}
.empty-row {
  border-bottom: none;
}
/* 横にスクロールしても項目名の列のすぐ右に見え続けるようにする */
.gempty {
  position: sticky;
  z-index: 2;
  flex-shrink: 0;
  height: var(--row-h);
  display: flex;
  align-items: center;
  padding: 0 16px;
  font-size: 12.5px;
  color: var(--faint);
  pointer-events: none;
  white-space: nowrap;
}
.empty-row .gcells {
  flex: 1;
}
</style>
