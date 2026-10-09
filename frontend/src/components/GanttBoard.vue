<script setup lang="ts">
/**
 * ガントチャート本体。左に行（科目・学習分野）、右に年／月（倍率により週・日）の目盛りと区間のバーを表示する。
 * - バーをドラッグ: 期間ごと移動。両端のつまみをドラッグ: 開始日／終了日を変更（1 日単位にスナップ）
 * - バーをクリック（タップ）: 区間の編集。行の名前をクリック: 行の編集
 * - 行の左の取っ手を上下にドラッグ: 並び替え
 * - 行の空いている場所をダブルクリック: その日から始まる区間を追加
 * 「全体」倍率では枠の幅に合わせて横スクロールなしで全期間を表示する。
 */
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { daysBetween, parseDate } from '@/lib/design'
import { GANTT_ZOOMS, addDays, isDark, isoAt, monthSpans, todayIso, yearSpans, type GanttZoom } from '@/lib/gantt'
import { haptic, viewportWidth } from '@/lib/native'
import type { GanttRow, GanttTask } from '@/types'

const props = defineProps<{ rows: GanttRow[]; startOn: string; endOn: string; zoom: GanttZoom }>()
const emit = defineEmits<{
  move: [task: GanttTask, startOn: string, endOn: string]
  reorder: [ids: number[]]
  editTask: [task: GanttTask]
  editRow: [row: GanttRow]
  addTask: [row: GanttRow, startOn?: string]
}>()

const ROW_H = 44

const scroller = ref<HTMLElement | null>(null)
const bodyEl = ref<HTMLElement | null>(null)

const rangeStart = computed(() => parseDate(props.startOn))
const rangeEnd = computed(() => parseDate(props.endOn))
const totalDays = computed(() => Math.max(1, daysBetween(rangeStart.value, rangeEnd.value) + 1))
const nameW = computed(() => (viewportWidth.value < 600 ? 150 : 240))

/** 「全体」倍率のときに使う、チャート部分の幅（枠の幅 − 項目名の列） */
const fitWidth = ref(800)
const dayW = computed(() => {
  if (props.zoom === 'fit') return Math.max(0.4, fitWidth.value / totalDays.value)
  return GANTT_ZOOMS.find((z) => z.key === props.zoom)?.dayW ?? 3.2
})
const totalW = computed(() => (props.zoom === 'fit' ? fitWidth.value : Math.round(totalDays.value * dayW.value)))

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

const years = computed<Seg[]>(() =>
  yearSpans(rangeStart.value, rangeEnd.value).map((y) => {
    const width = y.days * dayW.value
    return { key: y.key, left: y.startIdx * dayW.value, width, label: width >= 44 ? `${y.year}年` : '', strong: true }
  }),
)

const months = computed<Seg[]>(() =>
  monthSpans(rangeStart.value, rangeEnd.value).map((m) => {
    const width = m.days * dayW.value
    const label = width >= 34 ? `${m.month}月` : width >= 14 ? String(m.month) : ''
    return { key: m.key, left: m.startIdx * dayW.value, width, label, strong: m.month === 1 }
  }),
)

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
    const toMon = (8 - d.getDay()) % 7 || 7
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

// ---- 行とバー ----
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
  dark: boolean
}
interface RowView {
  row: GanttRow
  /** 前の行とグループが違う（太い区切り線を引く） */
  groupStart: boolean
  /** グループ名を表示する行か（グループの先頭） */
  groupLabel: string
  bars: Bar[]
  outsideCount: number
}

const drag = reactive({ id: 0, mode: 'move' as 'move' | 'l' | 'r', x0: 0, lastX: 0, ds: 0, de: 0, s0: 0, e0: 0, moved: false })

const rowViews = computed<RowView[]>(() => {
  let prevGroup: string | null | undefined
  return props.rows.map((row, i) => {
    const groupStart = i > 0 && (row.group ?? null) !== (prevGroup ?? null)
    const groupLabel = row.group && (i === 0 || groupStart) ? row.group : ''
    prevGroup = row.group
    const bars: Bar[] = row.tasks.map((t) => {
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
        width: Math.max(2, (ce - cs + 1) * dayW.value),
        outside: ce < cs,
        clipL: s < 0,
        clipR: e > totalDays.value - 1,
        dark: isDark(t.color),
      }
    })
    return { row, groupStart, groupLabel, bars, outsideCount: bars.filter((b) => b.outside).length }
  })
})

function fmt(i: number): string {
  const d = addDays(rangeStart.value, i)
  return `${d.getFullYear()}/${d.getMonth() + 1}/${d.getDate()}`
}
function barTitle(b: Bar): string {
  if (b.task.kind === 'milestone') return `${b.task.title}（${fmt(b.s)}）`
  return `${b.task.title}\n${fmt(b.s)} 〜 ${fmt(b.e)}・${b.e - b.s + 1}日${b.task.progress ? `・${b.task.progress}%` : ''}`
}
/** 行の下の小さな説明（区間の数・期間） */
function rowSub(v: RowView): string {
  const bars = v.bars.filter((b) => !b.outside)
  if (!bars.length) return v.row.tasks.length ? '表示期間の外' : '区間なし'
  const s = Math.min(...bars.map((b) => b.s))
  const e = Math.max(...bars.map((b) => b.e))
  return `${fmt(Math.max(0, s))} 〜 ${fmt(Math.min(totalDays.value - 1, e))}`
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
  let task: GanttTask | undefined
  for (const r of props.rows) task ??= r.tasks.find((x) => x.id === drag.id)
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
    emit('editTask', task)
  }
}

// 端に近づいたら横に自動スクロール（ドラッグ中・横スクロールがあるときだけ）
let asRaf = 0
let asDir = 0
function autoScroll(clientX: number) {
  const el = scroller.value
  if (!el || props.zoom === 'fit') return
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
  const r = props.rows[i]
  if (!r) return
  ;(e.currentTarget as HTMLElement).setPointerCapture(e.pointerId)
  Object.assign(rowDrag, { id: r.id, from: i, to: i, y0: e.clientY, active: false })
}
function onHandleMove(e: PointerEvent) {
  if (!rowDrag.id || !bodyEl.value) return
  if (!rowDrag.active && Math.abs(e.clientY - rowDrag.y0) < 4) return
  rowDrag.active = true
  rowDrag.sx = scroller.value?.scrollLeft ?? 0
  const y = e.clientY - bodyEl.value.getBoundingClientRect().top
  rowDrag.to = Math.max(0, Math.min(props.rows.length, Math.round(y / ROW_H)))
}
function onHandleUp() {
  if (!rowDrag.id) return
  const { from, to, active } = rowDrag
  rowDrag.id = 0
  rowDrag.active = false
  if (!active || to === from || to === from + 1) return
  const ids = props.rows.map((r) => r.id)
  const [moved] = ids.splice(from, 1)
  if (moved === undefined) return
  ids.splice(to > from ? to - 1 : to, 0, moved)
  haptic()
  emit('reorder', ids)
}

// ---- 空いている場所をダブルクリックで区間を追加 ----
function onCellsDblClick(e: MouseEvent, row: GanttRow) {
  if (e.target !== e.currentTarget) return
  const r = (e.currentTarget as HTMLElement).getBoundingClientRect()
  const i = Math.max(0, Math.min(totalDays.value - 1, Math.floor((e.clientX - r.left) / dayW.value)))
  emit('addTask', row, isoAt(rangeStart.value, i))
}

// ---- スクロール位置 ----
function scrollToDate(isoDate: string, ratio = 0.25) {
  const el = scroller.value
  if (!el || props.zoom === 'fit') return
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
  (z, oldZ) => {
    const el = scroller.value
    if (!el) return
    if (z === 'fit') return
    const view = el.clientWidth - nameW.value
    const oldW = oldZ === 'fit' ? fitWidth.value / totalDays.value : (GANTT_ZOOMS.find((x) => x.key === oldZ)?.dayW ?? dayW.value)
    const centerIdx = (el.scrollLeft + view / 2) / oldW
    nextTick(() => {
      el.scrollLeft = Math.max(0, centerIdx * dayW.value - view / 2)
    })
  },
)

// 枠の高さ: 画面の下端まで（中身が少ないときは中身の高さ）。幅: 「全体」倍率の計算に使う
const maxH = ref(600)
let ro: ResizeObserver | null = null
function measure() {
  const el = scroller.value
  if (!el) return
  const top = el.getBoundingClientRect().top
  maxH.value = Math.max(320, window.innerHeight - top - 28)
  fitWidth.value = Math.max(200, el.clientWidth - nameW.value)
}
onMounted(() => {
  measure()
  window.addEventListener('resize', measure)
  if (typeof ResizeObserver !== 'undefined' && scroller.value) {
    ro = new ResizeObserver(() => measure())
    ro.observe(scroller.value)
  }
  nextTick(scrollToToday)
})
onBeforeUnmount(() => {
  window.removeEventListener('resize', measure)
  ro?.disconnect()
  stopAutoScroll()
})
</script>

<template>
  <div
    ref="scroller"
    class="gantt no-gesture"
    :class="{ 'is-drag': drag.id !== 0, 'is-rowdrag': rowDrag.active, fit: zoom === 'fit' }"
    :style="{ maxHeight: maxH + 'px', '--name-w': nameW + 'px', '--row-h': ROW_H + 'px' }"
  >
    <!-- 目盛り（上に固定）。左上の角を左に固定し続けるため、幅は本体と同じにする -->
    <div class="ghead" :style="{ width: nameW + totalW + 'px' }">
      <div class="gcorner">
        <span>科目・学習分野</span>
        <span class="cnt">{{ rows.length }}</span>
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
    <div ref="bodyEl" class="gbody" :style="{ width: nameW + totalW + 'px', minHeight: Math.max(1, rows.length) * ROW_H + 'px' }">
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
        v-for="(v, i) in rowViews"
        :key="v.row.id"
        class="grow"
        :class="{ gstart: v.groupStart, lifted: rowDrag.active && rowDrag.id === v.row.id }"
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
          <button class="ntext" :title="`${v.row.title}（クリックで行を編集）`" @click="emit('editRow', v.row)">
            <span class="ntitle">{{ v.row.title }}</span>
            <span class="nsub">
              <span v-if="v.groupLabel" class="gchip">{{ v.groupLabel }}</span>
              {{ rowSub(v) }}
            </span>
          </button>
          <button class="nadd" title="この行に区間を追加" aria-label="区間を追加" @click="emit('addTask', v.row)">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M12 5v14M5 12h14" /></svg>
          </button>
        </div>

        <div class="gcells" :style="{ width: totalW + 'px' }" @dblclick="onCellsDblClick($event, v.row)">
          <template v-for="b in v.bars" :key="b.task.id">
            <template v-if="!b.outside">
              <!-- 節目（ひし形） -->
              <div
                v-if="b.task.kind === 'milestone'"
                class="gms"
                :class="{ dragging: drag.id === b.task.id }"
                :style="{ left: b.left + dayW / 2 + 'px', '--c': b.task.color }"
                :title="barTitle(b)"
                @pointerdown="onBarDown($event, b.task, 'move')"
                @pointermove="onBarMove"
                @pointerup="onBarUp"
                @pointercancel="onBarUp"
              >
                <span class="gms-shape"></span>
                <span class="gms-label">{{ b.task.title }}</span>
              </div>
              <!-- 期間のバー -->
              <div
                v-else
                class="gbar"
                :class="{ clipl: b.clipL, clipr: b.clipR, dark: b.dark, narrow: b.width < 72, tiny: b.width < 26, dragging: drag.id === b.task.id }"
                :style="{ left: b.left + 'px', width: b.width + 'px', '--c': b.task.color }"
                :title="barTitle(b)"
                @pointerdown="onBarDown($event, b.task, 'move')"
                @pointermove="onBarMove"
                @pointerup="onBarUp"
                @pointercancel="onBarUp"
              >
                <span class="glabel">{{ b.task.title }}</span>
                <span v-if="b.task.progress > 0" class="gprog" :style="{ width: `calc((100% - 6px) * ${Math.min(100, b.task.progress) / 100})` }"></span>
                <span class="gh l" @pointerdown.stop="onBarDown($event, b.task, 'l')"></span>
                <span class="gh r" @pointerdown.stop="onBarDown($event, b.task, 'r')"></span>
              </div>
            </template>
          </template>
          <span v-if="v.outsideCount && !v.bars.some((b) => !b.outside)" class="gout muted" :style="{ left: '8px' }">表示期間の外に{{ v.outsideCount }}件</span>
        </div>
      </div>

      <!-- 並び替え中の挿入位置（横スクロール中でも見える位置に出す） -->
      <div v-if="rowDrag.active" class="ins" :style="{ top: rowDrag.to * ROW_H + 'px', left: rowDrag.sx + 'px', width: nameW + 'px' }"></div>

      <div v-if="!rows.length" class="grow empty-row">
        <div class="gempty" :style="{ left: nameW + 'px' }">行がまだありません。「行を追加」から科目・学習分野を追加してください。</div>
        <div class="gcells" :style="{ width: totalW + 'px' }"></div>
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
.gantt.fit {
  overflow-x: hidden;
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
  border-bottom: 1px solid #d4d8de;
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
  background: #f6f7f9;
  border-right: 1px solid #d4d8de;
  font-size: 12px;
  font-weight: 700;
  color: var(--ink);
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
  background: #f6f7f9;
}
.srow {
  position: relative;
  height: 24px;
}
.srow.years {
  height: 24px;
  border-bottom: 1px solid #d4d8de;
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
  font-size: 11.5px;
  color: #4b5260;
  border-left: 1px solid #e3e6ea;
}
.years .seg {
  font-weight: 700;
  font-size: 12.5px;
  color: var(--ink);
  border-left-color: #9aa1ab;
}
.seg.strong {
  border-left-color: #9aa1ab;
}
.sub .seg {
  font-size: 10px;
  color: var(--faint);
}
.sub .seg.weekend {
  background: #eef0f3;
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
  background: #eceef1;
}
.vline.strong {
  background: #b8bec7;
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
  border-bottom: 1px solid #eceef1;
}
/* グループの変わり目は太い線 */
.grow.gstart {
  box-shadow: 0 -2px 0 0 #4b5260;
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
  gap: 4px;
  padding: 0 4px 0 2px;
  background: #fff;
  border-right: 1px solid #d4d8de;
}
.handle {
  flex-shrink: 0;
  width: 20px;
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
.ntext {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  border: none;
  background: transparent;
  padding: 0 2px;
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
  display: flex;
  align-items: center;
  gap: 5px;
  font-size: 10px;
  color: var(--faint);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  font-variant-numeric: tabular-nums;
}
.gchip {
  flex-shrink: 0;
  font-size: 9.5px;
  font-weight: 700;
  color: #4b5260;
  background: #eef0f3;
  border-radius: 4px;
  padding: 0 5px;
  line-height: 14px;
}
.nadd {
  flex-shrink: 0;
  width: 24px;
  height: 24px;
  border: none;
  border-radius: 7px;
  background: transparent;
  color: #b0b6bf;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  padding: 0;
}
.nadd:hover {
  background: #eef1f6;
  color: var(--primary);
}

.gcells {
  position: relative;
  flex-shrink: 0;
  height: 100%;
}

/* バー */
.gbar {
  position: absolute;
  top: 8px;
  height: 28px;
  border-radius: 4px;
  background: var(--c);
  box-shadow: inset 0 0 0 1px rgba(0, 0, 0, 0.16);
  cursor: grab;
  touch-action: none;
  overflow: hidden;
  display: flex;
  align-items: center;
  justify-content: center;
  color: var(--ink);
  transition: box-shadow 0.15s ease;
}
.gbar.dark {
  color: #fff;
}
.gbar:hover {
  box-shadow: inset 0 0 0 1px rgba(0, 0, 0, 0.16), 0 2px 8px rgba(0, 0, 0, 0.18);
}
.gbar.dragging,
.gms.dragging {
  box-shadow: 0 6px 18px rgba(0, 0, 0, 0.25);
  z-index: 3;
}
.gbar.clipl {
  border-top-left-radius: 0;
  border-bottom-left-radius: 0;
}
.gbar.clipr {
  border-top-right-radius: 0;
  border-bottom-right-radius: 0;
}
.glabel {
  position: relative;
  z-index: 1;
  padding: 0 8px;
  font-size: 11.5px;
  font-weight: 600;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  pointer-events: none;
  text-align: center;
}
/* 細いバーは 2 行に折り返す */
.gbar.narrow .glabel {
  padding: 0 3px;
  font-size: 9.5px;
  line-height: 1.15;
  white-space: normal;
  word-break: break-all;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
}
.gbar.tiny .glabel {
  display: none;
}
/* 進捗の帯（バーの下端） */
.gprog {
  position: absolute;
  left: 3px;
  bottom: 3px;
  height: 3px;
  border-radius: 2px;
  background: rgba(28, 32, 36, 0.55);
  pointer-events: none;
}
.gbar.dark .gprog {
  background: rgba(255, 255, 255, 0.85);
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
  background: currentColor;
  opacity: 0;
  transition: opacity 0.12s;
}
.gh.r::after {
  left: auto;
  right: 3px;
}
.gbar:hover .gh::after {
  opacity: 0.55;
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

/* 節目 */
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
