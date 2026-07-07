<script setup lang="ts">
import { computed, nextTick, reactive, ref, watch } from 'vue'
import Heatmap from '@/components/Heatmap.vue'
import MonthCalendar from '@/components/MonthCalendar.vue'
import EventModal from '@/components/EventModal.vue'
import { computeReviewOn, daysBetween, hexA, iso, parseDate, pct, REVIEW_OPTIONS, TYPE_BADGE } from '@/lib/design'
import { openListPrint, type PrintCell, type PrintColumn } from '@/lib/printList'
import { useStudyStore } from '@/stores/study'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'
import { useVocabularyStore } from '@/stores/vocabulary'
import { useRouter } from 'vue-router'
import { STUDY_TYPES, type Goal, type GoalItemDetail, type RecordColor, type ReviewItem, type StudyItemRow, type StudyType } from '@/types'

const study = useStudyStore()
const auth = useAuthStore()
const ui = useUiStore()
const vocab = useVocabularyStore()
const router = useRouter()

const today = new Date()
today.setHours(0, 0, 0, 0)

const tab = ref<'progress' | 'goals' | 'review'>('progress')
const expanded = reactive<Record<number, boolean>>({})
const vw = ref(window.innerWidth)
window.addEventListener('resize', () => (vw.value = window.innerWidth))
const isMobile = computed(() => vw.value < 860)

const hideEmpty = computed(() => auth.settings?.hideEmpty ?? false)

// ---- 進捗集計（included のみ・教材の行ベース達成率） ----
const incItems = computed(() => study.items.filter((i) => i.included))

interface TypeAgg {
  done: number // 1件以上記録のある行数
  total: number // 紐づく教材行の総数
}
function emptyTypes(): Record<StudyType, TypeAgg> {
  return { 講義: { done: 0, total: 0 }, 問題集: { done: 0, total: 0 }, 教科書: { done: 0, total: 0 } }
}
function addTypes(into: Record<StudyType, TypeAgg>, row: StudyItemRow) {
  for (const t of STUDY_TYPES) {
    into[t].done += row.byType[t]?.done ?? 0
    into[t].total += row.byType[t]?.total ?? 0
  }
}
function sumDone(t: Record<StudyType, TypeAgg>) {
  return STUDY_TYPES.reduce((a, k) => a + t[k].done, 0)
}
function sumTotal(t: Record<StudyType, TypeAgg>) {
  return STUDY_TYPES.reduce((a, k) => a + t[k].total, 0)
}

interface SubjAgg {
  id: number
  name: string
  group: string
  colorSoft: string
  colorVivid: string
  itemCount: number
  types: Record<StudyType, TypeAgg>
  majors: { name: string; types: Record<StudyType, TypeAgg> }[]
}

const subjAgg = computed<SubjAgg[]>(() => {
  const map = new Map<number, SubjAgg>()
  for (const it of incItems.value) {
    if (!map.has(it.subjectId)) {
      map.set(it.subjectId, {
        id: it.subjectId,
        name: it.subjectName,
        group: it.group,
        colorSoft: it.colorSoft,
        colorVivid: it.colorVivid,
        itemCount: 0,
        types: emptyTypes(),
        majors: [],
      })
    }
    const s = map.get(it.subjectId)!
    s.itemCount++
    addTypes(s.types, it)
    let mj = s.majors.find((m) => m.name === it.major)
    if (!mj) {
      mj = { name: it.major, types: emptyTypes() }
      s.majors.push(mj)
    }
    addTypes(mj.types, it)
  }
  return [...map.values()]
})

const overall = computed(() => {
  const t = emptyTypes()
  for (const s of subjAgg.value) {
    for (const k of STUDY_TYPES) {
      t[k].done += s.types[k].done
      t[k].total += s.types[k].total
    }
  }
  const done = sumDone(t)
  const total = sumTotal(t)
  return {
    lecPct: pct(t['講義'].done, t['講義'].total),
    lecDone: t['講義'].done,
    lecTotal: t['講義'].total,
    quizPct: pct(t['問題集'].done, t['問題集'].total),
    quizDone: t['問題集'].done,
    quizTotal: t['問題集'].total,
    textPct: pct(t['教科書'].done, t['教科書'].total),
    textDone: t['教科書'].done,
    textTotal: t['教科書'].total,
    allPct: pct(done, total),
    studied: done,
    totalBoth: total,
  }
})

const subjectCards = computed(() =>
  subjAgg.value
    .filter((s) => (!hideEmpty.value || sumDone(s.types) > 0) && sumTotal(s.types) > 0)
    .map((s) => {
      const color = ui.colorOf(s.colorSoft, s.colorVivid)
      const overallPct = pct(sumDone(s.types), sumTotal(s.types))
      const circ = 2 * Math.PI * 30
      return {
        id: s.id,
        name: s.name,
        group: s.group,
        itemCount: s.itemCount,
        color,
        light: hexA(color, 0.12),
        overallPct,
        ringDash: `${((overallPct / 100) * circ).toFixed(1)} ${circ.toFixed(1)}`,
        lecPct: pct(s.types['講義'].done, s.types['講義'].total),
        lecDone: s.types['講義'].done,
        lecTotal: s.types['講義'].total,
        quizPct: pct(s.types['問題集'].done, s.types['問題集'].total),
        quizDone: s.types['問題集'].done,
        quizTotal: s.types['問題集'].total,
        textPct: pct(s.types['教科書'].done, s.types['教科書'].total),
        textDone: s.types['教科書'].done,
        textTotal: s.types['教科書'].total,
        majors: s.majors
          .filter((m) => sumTotal(m.types) > 0)
          .map((m) => ({
            name: m.name,
            pct: pct(sumDone(m.types), sumTotal(m.types)),
            done: sumDone(m.types),
            totalBoth: sumTotal(m.types),
          })),
      }
    }),
)

// ---- 英単語カード（習得率 = 習得済み / 総単語数、セクション別に展開可） ----
const VOCAB_COLOR = { soft: '#c9569b', vivid: '#e0338f' }
const vocabCards = computed(() =>
  vocab.progress
    .filter((p) => p.totalWords > 0)
    .map((p) => {
      const color = ui.colorOf(VOCAB_COLOR.soft, VOCAB_COLOR.vivid)
      const overallPct = pct(p.masteredCount, p.totalWords)
      const circ = 2 * Math.PI * 30
      return {
        id: p.id,
        resourceName: p.name,
        color,
        light: hexA(color, 0.12),
        overallPct,
        ringDash: `${((overallPct / 100) * circ).toFixed(1)} ${circ.toFixed(1)}`,
        masteredCount: p.masteredCount,
        totalWords: p.totalWords,
        sectionCount: p.sections.length,
        sections: p.sections
          .filter((s) => s.totalWords > 0)
          .map((s) => ({
            name: s.name,
            pct: pct(s.masteredCount, s.totalWords),
            done: s.masteredCount,
            total: s.totalWords,
          })),
      }
    }),
)
const vocabExpanded = reactive<Record<number, boolean>>({})
function toggleVocab(id: number) {
  vocabExpanded[id] = !vocabExpanded[id]
}

// ---- 目標設定状況（ガントチャート） ----
const PX_PER_DAY = 34
const GANTT_LABEL_W = 300
const WD = ['日', '月', '火', '水', '木', '金', '土']
const EXTEND_DAYS = 60 // スクロール端で読み込む日数
const EDGE_PX = 400 // 端とみなすしきい値(px)
function addDays(base: Date, n: number) {
  const d = new Date(base)
  d.setDate(d.getDate() + n)
  return d
}
// 表示レンジ（スクロールで過去/未来を随時読み込む）。初期は今日を含む約1年強。
const rangeStart = ref(addDays(today, -30))
const rangeEnd = ref(addDays(today, 365))

interface GanttGoalRow {
  kind: 'goal'
  id: number
  title: string
  isSub: boolean
  createdOn: string
  deadline: string
  done: number
  target: number
  pct: number
  remaining: number
  expectedDone: number // 今日までに進んでおくべき項目数（按分）
  behind: boolean // 予定より遅れているか
  color: string
  achieved: boolean | null
  overdue: boolean
  linkedCount: number
}
interface GanttItemRow {
  kind: 'item'
  id: number
  parentGoalId: number
  title: string
  type: StudyType | null
  studied: boolean
  studiedOn: string | null
  deadline: string
  color: string
}
type GanttRow = GanttGoalRow | GanttItemRow

// 紐づけ項目の展開状態と明細キャッシュ（目標IDで管理）
const ganttExpanded = reactive<Record<number, boolean>>({})
const ganttItems = reactive<Record<number, GoalItemDetail[]>>({})
const ganttItemsLoading = reactive<Record<number, boolean>>({})
async function toggleGanttExpand(goalId: number) {
  ganttExpanded[goalId] = !ganttExpanded[goalId]
  if (ganttExpanded[goalId] && !ganttItems[goalId]) {
    ganttItemsLoading[goalId] = true
    try {
      ganttItems[goalId] = await study.fetchGoalItems(goalId)
    } catch {
      ganttItems[goalId] = []
    } finally {
      ganttItemsLoading[goalId] = false
    }
  }
}
async function toggleGanttItem(goalId: number, itemId: number, studied: boolean) {
  try {
    await study.setGoalItemStudied(goalId, itemId, !studied)
    ganttItems[goalId] = await study.fetchGoalItems(goalId)
  } catch {
    ui.notify('学習済みの更新に失敗しました')
  }
}

const gantt = computed(() => {
  if (!study.goals.length) return null
  const goalsFlat: { g: Goal; isSub: boolean }[] = []
  for (const g of study.goals) {
    goalsFlat.push({ g, isSub: false })
    for (const s of g.subGoals ?? []) goalsFlat.push({ g: s, isSub: true })
  }

  const start = new Date(rangeStart.value)
  const end = new Date(rangeEnd.value)
  const total = Math.max(1, daysBetween(start, end))
  const width = total * PX_PER_DAY

  // 1日ごとの見出し（日付＋曜日、土日の色分け）
  const days: { px: number; num: number; wd: number; sat: boolean; sun: boolean }[] = []
  const dc = new Date(start)
  for (let i = 0; i <= total; i++) {
    const wd = dc.getDay()
    days.push({ px: i * PX_PER_DAY, num: dc.getDate(), wd, sat: wd === 6, sun: wd === 0 })
    dc.setDate(dc.getDate() + 1)
  }
  // 月境界（1日）にラベル用の目印
  const months = days.filter((x) => x.num === 1).map((x) => x.px)

  const posPx = (isoDate: string) => daysBetween(start, parseDate(isoDate)) * PX_PER_DAY
  const todayPx = daysBetween(start, today) * PX_PER_DAY

  const rows: GanttRow[] = []
  for (const { g, isSub } of goalsFlat) {
    const createdD = parseDate(g.createdOn)
    const dlD = parseDate(g.deadline)
    // 当日も1日として換算（作成日〜期限を両端含む日数で按分）
    const elapsed = daysBetween(createdD, today) + 1
    const span = daysBetween(createdD, dlD) + 1
    const ratio = span > 0 ? Math.min(1, Math.max(0, elapsed / span)) : 1
    const expectedDone = Math.min(g.target, Math.round(g.target * ratio))
    rows.push({
      kind: 'goal',
      id: g.id,
      title: g.title,
      isSub,
      createdOn: g.createdOn,
      deadline: g.deadline,
      done: g.done,
      target: g.target,
      pct: pct(g.done, g.target),
      remaining: Math.max(0, g.target - g.done),
      expectedDone,
      behind: g.done < expectedDone,
      color: ui.colorOf(g.colorSoft, g.colorVivid),
      achieved: g.achieved,
      overdue: parseDate(g.deadline).getTime() < today.getTime() && g.done < g.target,
      linkedCount: g.linkedCount,
    })
    if (ganttExpanded[g.id]) {
      for (const it of ganttItems[g.id] ?? []) {
        rows.push({
          kind: 'item',
          id: it.id,
          parentGoalId: g.id,
          title: it.title ?? it.sub ?? '（無題）',
          type: it.type,
          studied: it.studied,
          studiedOn: it.studiedOn,
          deadline: g.deadline,
          color: ui.colorOf(g.colorSoft, g.colorVivid),
        })
      }
    }
  }
  return { rows, days, months, posPx, todayPx, width, labelW: GANTT_LABEL_W }
})
// 目標バー（作成日→期限）。ペース目安の位置(pacePx)＝今日をバー内にクランプ。
function goalBar(row: GanttGoalRow) {
  const g = gantt.value!
  const l = g.posPx(row.createdOn)
  const r = g.posPx(row.deadline)
  const left = Math.min(l, r)
  const width = Math.max(6, Math.abs(r - l))
  return { left, width }
}
// 目安ライン（今日までに進めておくべき進捗位置）の px
function goalPacePx(row: GanttGoalRow) {
  const g = gantt.value!
  const l = g.posPx(row.createdOn)
  const r = g.posPx(row.deadline)
  const frac = row.target > 0 ? row.expectedDone / row.target : 0
  return Math.min(r, l + (r - l) * frac)
}
// 項目バー（今日→期限）
function barPx(deadline: string) {
  const g = gantt.value!
  const dl = g.posPx(deadline)
  return { left: Math.min(g.todayPx, dl), width: Math.max(6, Math.abs(dl - g.todayPx)) }
}
// マウスの縦ホイールで横スクロールさせる
const ganttScroll = ref<HTMLElement | null>(null)
function onGanttWheel(e: WheelEvent) {
  const el = ganttScroll.value
  if (!el) return
  // 見出し列（固定）の上では横スクロールに変換せず、ページの縦スクロールに委ねる
  if (e.clientX - el.getBoundingClientRect().left < GANTT_LABEL_W) return
  if (el.scrollWidth <= el.clientWidth) return
  const dy = e.deltaY
  if (dy === 0) return
  const atStart = el.scrollLeft <= 0
  const atEnd = el.scrollLeft + el.clientWidth >= el.scrollWidth - 1
  // 端に達したらページの縦スクロールに委ねる
  if ((dy < 0 && atStart) || (dy > 0 && atEnd)) return
  // 行(deltaMode=1)は px 換算。1回の移動を控えめにして細かくスクロール。
  const step = (e.deltaMode === 1 ? dy * 16 : dy) * 0.45
  el.scrollLeft += step
  e.preventDefault()
}
// スクロールで端に近づいたら過去/未来を読み込む（レンジ拡張）
let extending = false
async function onGanttScroll() {
  const el = ganttScroll.value
  if (!el || extending) return
  if (el.scrollLeft < EDGE_PX) {
    extending = true
    rangeStart.value = addDays(rangeStart.value, -EXTEND_DAYS)
    await nextTick()
    el.scrollLeft += EXTEND_DAYS * PX_PER_DAY // 左に伸びた分だけ位置を保つ
    extending = false
  } else if (el.scrollLeft + el.clientWidth > el.scrollWidth - EDGE_PX) {
    extending = true
    rangeEnd.value = addDays(rangeEnd.value, EXTEND_DAYS)
    await nextTick()
    extending = false
  }
}
// 目標・予定が現在レンジ外なら包含するよう拡張
function ensureCovers() {
  const dates: number[] = []
  const collect = (g: Goal) => {
    dates.push(parseDate(g.deadline).getTime())
    ;(g.subGoals ?? []).forEach(collect)
  }
  study.goals.forEach(collect)
  study.events.forEach((e) => dates.push(parseDate(e.date).getTime()))
  if (!dates.length) return
  const min = Math.min(...dates)
  const max = Math.max(...dates)
  if (min < rangeStart.value.getTime()) rangeStart.value = addDays(new Date(min), -14)
  if (max > rangeEnd.value.getTime()) rangeEnd.value = addDays(new Date(max), 14)
}
watch(() => [study.goals, study.events], ensureCovers, { immediate: true })
// レンジ内の予定
const eventsInRange = computed(() => {
  if (!gantt.value) return []
  return study.events.filter((e) => {
    const t = parseDate(e.date).getTime()
    return t >= rangeStart.value.getTime() && t <= rangeEnd.value.getTime()
  })
})
// 目標タブを開いて今日を左端付近に表示
function selectGoals() {
  tab.value = 'goals'
  nextTick(() => {
    const el = ganttScroll.value
    const g = gantt.value
    if (el && g) el.scrollLeft = Math.max(0, g.todayPx - 40)
  })
}

// ---- 復習項目 ----
function selectReview() {
  tab.value = 'review'
  study.fetchReviews().catch(() => {})
}
const reviewDueCount = computed(
  () => study.reviews.filter((r) => !r.reviewed && (r.overdue || parseDate(r.reviewOn).getTime() === today.getTime())).length,
)

// 復習フィルター: 未復習 / 復習済み / すべて
const reviewFilter = ref<'pending' | 'done' | 'all'>('pending')
const pendingReviews = computed(() => study.reviews.filter((r) => !r.reviewed))
const doneReviews = computed(() => study.reviews.filter((r) => r.reviewed))
const filteredReviews = computed(() => {
  if (reviewFilter.value === 'pending') return pendingReviews.value
  if (reviewFilter.value === 'done') return doneReviews.value
  return study.reviews
})

// 復習記録モーダル（学習記録と同じ設定項目）
const reviewModal = reactive<{
  open: boolean
  item: ReviewItem | null
  date: string
  color: RecordColor | null
  reviewIdx: number
  customDays: number | null
  saving: boolean
}>({
  open: false,
  item: null,
  date: iso(new Date()),
  color: 'red',
  reviewIdx: 1,
  customDays: 7,
  saving: false,
})
function openReviewRecord(r: ReviewItem) {
  reviewModal.open = true
  reviewModal.item = r
  reviewModal.date = iso(new Date())
  reviewModal.color = 'red'
  reviewModal.reviewIdx = 1
  reviewModal.customDays = 7
  reviewModal.saving = false
}
const reviewModalPreview = computed(() => {
  const opt = REVIEW_OPTIONS[reviewModal.reviewIdx]
  const on = computeReviewOn(reviewModal.date, opt, reviewModal.customDays)
  return on ? `→ 次回の復習期限: ${fmtMd(on)}` : '次回の復習は予約されません'
})
async function submitReviewRecord() {
  if (!reviewModal.item || reviewModal.saving) return
  reviewModal.saving = true
  const opt = REVIEW_OPTIONS[reviewModal.reviewIdx]
  const nextReviewOn = computeReviewOn(reviewModal.date, opt, reviewModal.customDays)
  try {
    await study.completeReview(reviewModal.item.id, reviewModal.date, reviewModal.color, nextReviewOn)
    ui.notify(nextReviewOn ? `復習を記録しました（次回 ${fmtMd(nextReviewOn)}）` : '復習を記録しました')
    reviewModal.open = false
  } catch {
    ui.notify('復習の記録に失敗しました')
    reviewModal.saving = false
  }
}
function fmtMd(isoDate: string) {
  const d = parseDate(isoDate)
  return `${d.getMonth() + 1}/${d.getDate()}`
}
function reviewDayLabel(isoDate: string) {
  const diff = daysBetween(today, parseDate(isoDate))
  if (diff < 0) return `${-diff}日超過`
  if (diff === 0) return '本日'
  return `あと${diff}日`
}
function reviewColor(r: ReviewItem) {
  return ui.colorOf(r.colorSoft, r.colorVivid)
}
// 記録時に選んだ色（赤/青/緑）→ タイトル文字色。色なしは既定色。
function recordColorHex(c: RecordColor | null): string {
  return c === 'red' ? '#d92d20' : c === 'blue' ? '#2563eb' : c === 'green' ? '#2e9d62' : '#1c2024'
}
const DATE_COLORS: Record<RecordColor, string> = { red: '#d92d20', blue: '#2563eb', green: '#2e9d62' }
const COLOR_LABEL: Record<RecordColor, string> = { red: '赤', blue: '青', green: '緑' }
const RECORD_COLOR_KEYS: RecordColor[] = ['red', 'blue', 'green']
function escHtml(s: string): string {
  return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
}
// 復習項目一覧をチェックシートとして画面出力（PDF印刷可）
function printReviews() {
  const list = filteredReviews.value
  if (!list.length) {
    ui.notify('出力対象の復習項目がありません')
    return
  }
  const emptyBox = '<span style="display:inline-block;width:15px;height:15px;border:1.5px solid #333;border-radius:3px"></span>'
  const doneBox = '<span style="display:inline-block;width:15px;height:15px;border:1.5px solid #2e9d62;border-radius:3px;background:#2e9d62;color:#fff;text-align:center;line-height:15px;font-size:11px">✓</span>'
  const cols: PrintColumn[] = [
    { label: '✓', align: 'center', width: '34px' },
    { label: '復習期限', align: 'center', width: '78px', nowrap: true },
    { label: '種別', align: 'center', width: '66px', nowrap: true },
    { label: '科目', width: '84px', nowrap: true },
    { label: 'タイトル' },
    { label: '出典 ｜ 中分類', width: '176px' },
    { label: '学習日', align: 'center', width: '70px', nowrap: true },
    { label: '復習日', align: 'center', width: '70px', nowrap: true },
  ]
  const rows: PrintCell[][] = list.map((r) => [
    { html: r.reviewed ? doneBox : emptyBox },
    fmtMd(r.reviewOn),
    r.type ?? '',
    r.subjectName ?? '',
    { html: `<span style="color:${recordColorHex(r.color)};font-weight:700">${escHtml(r.title ?? r.sub ?? '')}</span>` },
    `${r.bookTitle ?? ''}${r.major ? ` ｜ ${r.major}›${r.mid}` : ''}`,
    fmtMd(r.studiedOn),
    r.reviewedOn ? fmtMd(r.reviewedOn) : '',
  ])
  const scope = reviewFilter.value === 'pending' ? '未復習' : reviewFilter.value === 'done' ? '復習済み' : 'すべて'
  const n = new Date()
  const ok = openListPrint({
    title: '復習チェックシート',
    subtitle: `${scope}・全${list.length}件・出力日 ${n.getFullYear()}/${n.getMonth() + 1}/${n.getDate()}`,
    columns: cols,
    rows,
  })
  if (!ok) ui.notify('ポップアップがブロックされました。ブラウザ設定で許可してください。')
}

// ---- カレンダー / 予定 ----
const examDate = computed(() => auth.settings?.examDate ?? null)
const daysToExam = computed(() => (examDate.value ? Math.max(0, daysBetween(today, parseDate(examDate.value))) : 0))

const upcoming = computed(() =>
  study.events
    .map((e) => ({ ...e, d: parseDate(e.date) }))
    .filter((e) => e.d >= today)
    .sort((a, b) => a.d.getTime() - b.d.getTime()),
)
const nextEvent = computed(() => upcoming.value[0] ?? null)
const upcomingList = computed(() =>
  upcoming.value.slice(0, 3).map((e) => ({
    title: e.title,
    dateLabel: `${e.d.getMonth() + 1}/${e.d.getDate()}`,
    days: Math.max(0, daysBetween(today, e.d)),
  })),
)

const eventModal = ref<{ date: string; title: string } | null>(null)
function openEvent(date: string, title: string) {
  eventModal.value = { date, title }
}
async function saveEvent(title: string) {
  if (!eventModal.value) return
  const date = eventModal.value.date
  if (title.trim()) {
    await study.saveEvent(date, title.trim())
    ui.notify('予定を保存しました')
  } else {
    const ev = study.events.find((e) => e.date === date)
    if (ev) {
      await study.deleteEvent(ev.id)
      ui.notify('予定を削除しました')
    }
  }
  eventModal.value = null
}
async function deleteEvent() {
  if (!eventModal.value) return
  const ev = study.events.find((e) => e.date === eventModal.value!.date)
  if (ev) {
    await study.deleteEvent(ev.id)
    ui.notify('予定を削除しました')
  }
  eventModal.value = null
}

const homeCols = computed(() => (isMobile.value ? '1fr' : 'minmax(300px,340px) 1fr'))
function toggle(id: number) {
  expanded[id] = !expanded[id]
}
</script>

<template>
  <div>
    <!-- tabs -->
    <div style="display: flex; margin-bottom: 18px">
      <div class="seg">
        <button class="seg-btn" :class="{ on: tab === 'progress' }" @click="tab = 'progress'">進捗率</button>
        <button class="seg-btn" :class="{ on: tab === 'goals' }" @click="selectGoals">目標</button>
        <button class="seg-btn" :class="{ on: tab === 'review' }" @click="selectReview">復習項目<span v-if="reviewDueCount" class="tab-badge">{{ reviewDueCount }}</span></button>
      </div>
    </div>

    <!-- Progress tab -->
    <div v-if="tab === 'progress'" :style="{ display: 'grid', gridTemplateColumns: homeCols, gap: '18px', alignItems: 'start' }">
      <!-- LEFT -->
      <div :style="{ display: 'flex', flexDirection: 'column', gap: '14px', order: isMobile ? 2 : 0 }">
        <div class="card" style="padding: 16px 18px">
          <div class="row-between" style="margin-bottom: 12px">
            <span style="font-size: 13px; font-weight: 700">学習カレンダー</span>
            <span style="font-size: 10.5px; color: var(--faint)">直近16週間</span>
          </div>
          <Heatmap :counts="study.recordStats?.heatmap ?? {}" :cell="11" />
        </div>

        <MonthCalendar :events="study.events" :exam-date="examDate" @day-click="openEvent" />

        <div style="background: #1c2024; border-radius: 16px; padding: 15px 18px; color: #fff">
          <div class="row-between" style="align-items: baseline">
            <span style="font-size: 11.5px; color: #b7bcc6">受験本番まで</span>
            <span><span class="dm" style="font-size: 24px; font-weight: 700">{{ daysToExam }}</span><span style="font-size: 11px; color: #b7bcc6; margin-left: 2px">日</span></span>
          </div>
          <div v-if="nextEvent" class="row-between" style="align-items: baseline; margin-top: 10px; padding-top: 10px; border-top: 1px solid rgba(255, 255, 255, 0.12)">
            <span style="font-size: 11.5px; color: #b7bcc6">{{ nextEvent.title }}</span>
            <span><span class="dm" style="font-size: 18px; font-weight: 700; color: #9fb4ff">{{ Math.max(0, daysBetween(today, nextEvent.d)) }}</span><span style="font-size: 11px; color: #b7bcc6; margin-left: 2px">日</span></span>
          </div>
        </div>

        <div v-if="upcomingList.length" class="card" style="padding: 14px 16px">
          <div style="font-size: 12px; font-weight: 700; margin-bottom: 9px">今後の予定</div>
          <div style="display: flex; flex-direction: column; gap: 8px">
            <div v-for="(e, i) in upcomingList" :key="i" style="display: flex; align-items: center; gap: 9px">
              <span style="font-size: 11px; color: var(--faint); width: 36px">{{ e.dateLabel }}</span>
              <span style="flex: 1; font-size: 12px; font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis">{{ e.title }}</span>
              <span style="font-size: 11px; font-weight: 600; color: #cf4486">{{ e.days }}日</span>
            </div>
          </div>
        </div>
      </div>

      <!-- RIGHT -->
      <div :style="{ minWidth: 0, order: isMobile ? 1 : 0 }">
        <!-- overall summary -->
        <div class="card" style="padding: 22px 24px; margin-bottom: 18px; display: flex; gap: 30px; align-items: center; flex-wrap: wrap">
          <div style="flex: 1; min-width: 200px">
            <div style="font-size: 12.5px; color: var(--faint); font-weight: 500; margin-bottom: 4px">全体の学習進捗</div>
            <div style="display: flex; align-items: baseline; gap: 10px">
              <span class="dm" style="font-size: 40px; font-weight: 700">{{ overall.allPct }}<span style="font-size: 19px">%</span></span>
              <span style="font-size: 13px; color: var(--mut)">{{ overall.studied }} / {{ overall.totalBoth }} 行</span>
            </div>
            <div class="track" style="height: 9px; margin-top: 12px">
              <div :style="{ height: '100%', width: overall.allPct + '%', background: 'linear-gradient(90deg,#3b50cc,#6678e6)', borderRadius: '99px' }"></div>
            </div>
          </div>
          <div style="display: flex; gap: 20px">
            <div style="text-align: center; min-width: 80px">
              <div style="font-size: 11.5px; color: var(--faint); margin-bottom: 3px">講義</div>
              <div class="dm" style="font-size: 23px; font-weight: 700">{{ overall.lecPct }}<span style="font-size: 13px">%</span></div>
              <div style="font-size: 11px; color: var(--faint)">{{ overall.lecDone }} / {{ overall.lecTotal }}</div>
            </div>
            <div style="width: 1px; background: var(--line)"></div>
            <div style="text-align: center; min-width: 80px">
              <div style="font-size: 11.5px; color: var(--faint); margin-bottom: 3px">問題集</div>
              <div class="dm" style="font-size: 23px; font-weight: 700">{{ overall.quizPct }}<span style="font-size: 13px">%</span></div>
              <div style="font-size: 11px; color: var(--faint)">{{ overall.quizDone }} / {{ overall.quizTotal }}</div>
            </div>
            <div style="width: 1px; background: var(--line)"></div>
            <div style="text-align: center; min-width: 80px">
              <div style="font-size: 11.5px; color: var(--faint); margin-bottom: 3px">教科書</div>
              <div class="dm" style="font-size: 23px; font-weight: 700">{{ overall.textPct }}<span style="font-size: 13px">%</span></div>
              <div style="font-size: 11px; color: var(--faint)">{{ overall.textDone }} / {{ overall.textTotal }}</div>
            </div>
          </div>
        </div>

        <!-- subject cards -->
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(330px, 1fr)); gap: 16px">
          <div v-for="s in subjectCards" :key="s.id" class="card" style="padding: 18px 20px">
            <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 14px">
              <div v-if="ui.progressStyle === 'リング'" style="position: relative; width: 56px; height: 56px; flex-shrink: 0">
                <svg width="56" height="56" viewBox="0 0 70 70">
                  <circle cx="35" cy="35" r="30" fill="none" stroke="#eef0f3" stroke-width="8" />
                  <circle cx="35" cy="35" r="30" fill="none" :stroke="s.color" stroke-width="8" stroke-linecap="round" :stroke-dasharray="s.ringDash" transform="rotate(-90 35 35)" />
                </svg>
                <div class="dm" style="position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 700">{{ s.overallPct }}</div>
              </div>
              <div v-else :style="{ width: '11px', height: '38px', borderRadius: '6px', background: s.color, flexShrink: 0 }"></div>
              <div style="flex: 1; min-width: 0">
                <div class="row-between">
                  <div style="display: flex; align-items: center; gap: 8px">
                    <span style="font-size: 16px; font-weight: 700">{{ s.name }}</span>
                    <span :style="{ fontSize: '10.5px', fontWeight: 600, color: s.color, background: s.light, padding: '2px 7px', borderRadius: '99px' }">{{ s.group }}</span>
                  </div>
                  <span style="font-size: 11.5px; color: var(--faint)">{{ s.itemCount }}項目</span>
                </div>
                <template v-if="ui.progressStyle !== 'リング'">
                  <div v-if="ui.progressStyle === 'ドット'" style="display: flex; gap: 3px; margin-top: 9px">
                    <div v-for="i in 10" :key="i" :style="{ flex: 1, height: '7px', borderRadius: '2px', background: i <= Math.round(s.overallPct / 10) ? s.color : '#eef0f3' }"></div>
                  </div>
                  <div v-else class="track" style="height: 7px; margin-top: 9px">
                    <div :style="{ height: '100%', width: s.overallPct + '%', background: s.color, borderRadius: '99px' }"></div>
                  </div>
                </template>
              </div>
            </div>

            <div style="display: flex; gap: 8px; margin-bottom: 4px">
              <div class="stat-box">
                <div class="row-between"><span style="font-size: 11.5px; color: var(--mut); font-weight: 500">講義</span><span class="dm" style="font-size: 13px; font-weight: 700">{{ s.lecPct }}%</span></div>
                <div style="font-size: 10.5px; color: var(--faint)">{{ s.lecDone }} / {{ s.lecTotal }}</div>
              </div>
              <div class="stat-box">
                <div class="row-between"><span style="font-size: 11.5px; color: var(--mut); font-weight: 500">問題集</span><span class="dm" style="font-size: 13px; font-weight: 700">{{ s.quizPct }}%</span></div>
                <div style="font-size: 10.5px; color: var(--faint)">{{ s.quizDone }} / {{ s.quizTotal }}</div>
              </div>
              <div class="stat-box">
                <div class="row-between"><span style="font-size: 11.5px; color: var(--mut); font-weight: 500">教科書</span><span class="dm" style="font-size: 13px; font-weight: 700">{{ s.textPct }}%</span></div>
                <div style="font-size: 10.5px; color: var(--faint)">{{ s.textDone }} / {{ s.textTotal }}</div>
              </div>
            </div>

            <button class="toggle-btn" @click="toggle(s.id)">
              {{ expanded[s.id] ? '大分類を閉じる' : '大分類別の進捗を見る' }}
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" :style="{ transform: expanded[s.id] ? 'rotate(180deg)' : 'none' }"><path d="M6 9l6 6 6-6" /></svg>
            </button>

            <div v-if="expanded[s.id]" style="border-top: 1px solid #f0f1f3; margin-top: 4px; padding-top: 12px; display: flex; flex-direction: column; gap: 11px">
              <div v-for="(m, mi) in s.majors" :key="mi">
                <div class="row-between" style="align-items: baseline; margin-bottom: 5px">
                  <span style="font-size: 12.5px; font-weight: 500">{{ m.name }}</span>
                  <span style="font-size: 11px; color: var(--faint)">{{ m.pct }}% · {{ m.done }}/{{ m.totalBoth }}</span>
                </div>
                <div class="track" style="height: 6px">
                  <div :style="{ height: '100%', width: m.pct + '%', background: s.color, opacity: 0.85, borderRadius: '99px' }"></div>
                </div>
              </div>
            </div>
          </div>

          <!-- 英単語カード（習得率 = 習得済み/総単語数、セクション別に展開可） -->
          <div v-for="v in vocabCards" :key="'vocab-' + v.id" class="card" style="padding: 18px 20px">
            <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 14px">
              <div v-if="ui.progressStyle === 'リング'" style="position: relative; width: 56px; height: 56px; flex-shrink: 0">
                <svg width="56" height="56" viewBox="0 0 70 70">
                  <circle cx="35" cy="35" r="30" fill="none" stroke="#eef0f3" stroke-width="8" />
                  <circle cx="35" cy="35" r="30" fill="none" :stroke="v.color" stroke-width="8" stroke-linecap="round" :stroke-dasharray="v.ringDash" transform="rotate(-90 35 35)" />
                </svg>
                <div class="dm" style="position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 700">{{ v.overallPct }}</div>
              </div>
              <div v-else :style="{ width: '11px', height: '38px', borderRadius: '6px', background: v.color, flexShrink: 0 }"></div>
              <div style="flex: 1; min-width: 0">
                <div class="row-between">
                  <div style="display: flex; align-items: center; gap: 8px; min-width: 0">
                    <span style="font-size: 16px; font-weight: 700">英単語</span>
                    <span :style="{ fontSize: '10.5px', fontWeight: 600, color: v.color, background: v.light, padding: '2px 7px', borderRadius: '99px' }">習得率</span>
                  </div>
                  <router-link :to="{ name: 'quiz' }" style="font-size: 11.5px; color: var(--faint); text-decoration: none; white-space: nowrap">クイズへ ›</router-link>
                </div>
                <div style="font-size: 11px; color: var(--faint); margin-top: 2px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap">{{ v.resourceName }}</div>
                <template v-if="ui.progressStyle !== 'リング'">
                  <div v-if="ui.progressStyle === 'ドット'" style="display: flex; gap: 3px; margin-top: 9px">
                    <div v-for="i in 10" :key="i" :style="{ flex: 1, height: '7px', borderRadius: '2px', background: i <= Math.round(v.overallPct / 10) ? v.color : '#eef0f3' }"></div>
                  </div>
                  <div v-else class="track" style="height: 7px; margin-top: 9px">
                    <div :style="{ height: '100%', width: v.overallPct + '%', background: v.color, borderRadius: '99px' }"></div>
                  </div>
                </template>
              </div>
            </div>

            <div style="display: flex; gap: 8px; margin-bottom: 4px">
              <div class="stat-box">
                <div class="row-between"><span style="font-size: 11.5px; color: var(--mut); font-weight: 500">習得</span><span class="dm" style="font-size: 13px; font-weight: 700">{{ v.overallPct }}%</span></div>
                <div style="font-size: 10.5px; color: var(--faint)">{{ v.masteredCount }} / {{ v.totalWords }} 語</div>
              </div>
              <div class="stat-box">
                <div class="row-between"><span style="font-size: 11.5px; color: var(--mut); font-weight: 500">セクション</span><span class="dm" style="font-size: 13px; font-weight: 700">{{ v.sectionCount }}</span></div>
                <div style="font-size: 10.5px; color: var(--faint)">全 {{ v.totalWords }} 語</div>
              </div>
            </div>

            <button class="toggle-btn" @click="toggleVocab(v.id)">
              {{ vocabExpanded[v.id] ? 'セクション別を閉じる' : 'セクション別の進捗を見る' }}
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" :style="{ transform: vocabExpanded[v.id] ? 'rotate(180deg)' : 'none' }"><path d="M6 9l6 6 6-6" /></svg>
            </button>

            <div v-if="vocabExpanded[v.id]" style="border-top: 1px solid #f0f1f3; margin-top: 4px; padding-top: 12px; display: flex; flex-direction: column; gap: 11px; max-height: 340px; overflow-y: auto">
              <div v-for="(sec, si) in v.sections" :key="si">
                <div class="row-between" style="align-items: baseline; margin-bottom: 5px">
                  <span style="font-size: 12.5px; font-weight: 500; overflow: hidden; text-overflow: ellipsis; white-space: nowrap">{{ sec.name }}</span>
                  <span style="font-size: 11px; color: var(--faint); white-space: nowrap; flex-shrink: 0; margin-left: 8px">{{ sec.pct }}% · {{ sec.done }}/{{ sec.total }}</span>
                </div>
                <div class="track" style="height: 6px">
                  <div :style="{ height: '100%', width: sec.pct + '%', background: v.color, opacity: 0.85, borderRadius: '99px' }"></div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Goals-status tab: ガントチャート -->
    <div v-else-if="tab === 'goals'">
      <div v-if="!gantt" class="card" style="padding: 40px; text-align: center; color: var(--faint); font-size: 13px; line-height: 1.7">
        目標がありません。<br />「目標設定」から個別学習データを紐づけて目標を作成すると、ここに期限までのガントチャートが表示されます。
      </div>
      <div v-else ref="ganttScroll" class="card gantt-card" @wheel="onGanttWheel" @scroll="onGanttScroll">
        <div class="gantt" :style="{ width: gantt.labelW + gantt.width + 'px' }">
          <!-- 日付軸（日付＋曜日、土=青 / 日=赤） -->
          <div class="g-axis-row">
            <div class="g-label-col g-corner">目標 / 中間目標</div>
            <div class="g-axis" :style="{ width: gantt.width + 'px' }">
              <div v-for="d in gantt.days" :key="d.px" class="g-day" :class="{ sat: d.sat, sun: d.sun }" :style="{ left: d.px + 'px', width: PX_PER_DAY + 'px' }">
                <span class="g-day-num">{{ d.num }}</span>
                <span class="g-day-wd">{{ WD[d.wd] }}</span>
              </div>
            </div>
          </div>
          <!-- 行 -->
          <div class="g-rows">
            <!-- 背景: 土日の帯 -->
            <div class="g-weekend-layer" :style="{ left: gantt.labelW + 'px', width: gantt.width + 'px' }">
              <template v-for="d in gantt.days" :key="'wk' + d.px">
                <div v-if="d.sat || d.sun" class="g-weekend-band" :class="{ sat: d.sat, sun: d.sun }" :style="{ left: d.px + 'px', width: PX_PER_DAY + 'px' }"></div>
              </template>
            </div>
            <!-- 予定（カレンダー登録） -->
            <div v-if="eventsInRange.length" class="g-row g-evt-row">
              <div class="g-label-col"><div class="g-title"><span class="g-evt-cal">📅</span>予定</div></div>
              <div class="g-track">
                <div v-for="e in eventsInRange" :key="e.id" class="g-evt" :style="{ left: gantt.posPx(e.date) + 'px' }" :title="e.title">
                  <span class="g-evt-pin"></span><span class="g-evt-txt">{{ e.title }}</span>
                </div>
              </div>
            </div>
            <template v-for="row in gantt.rows" :key="row.kind + row.id">
              <!-- 目標 / 中間目標 -->
              <div v-if="row.kind === 'goal'" class="g-row" :class="{ sub: row.isSub }">
                <div class="g-label-col">
                  <div class="g-title">
                    <button v-if="row.linkedCount" class="g-chev" title="紐づけ項目を展開" @click.stop="toggleGanttExpand(row.id)">
                      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" :style="{ transform: ganttExpanded[row.id] ? 'rotate(90deg)' : 'none', transition: 'transform .12s' }"><path d="M9 6l6 6-6 6" /></svg>
                    </button>
                    <span v-else class="g-chev-sp"></span>
                    <span v-if="row.isSub" class="g-subtag">中間</span>
                    <span class="g-title-txt" @click="router.push({ name: 'goals' })">{{ row.title }}</span>
                  </div>
                  <div class="g-meta">
                    {{ row.done }}/{{ row.target }}・残り{{ row.remaining }}
                    <span :class="['g-pace-tag', row.behind ? 'ng' : 'ok']">目安{{ row.expectedDone }}</span>
                    <span v-if="row.achieved === true" class="g-ach ok">達成</span>
                    <span v-else-if="row.achieved === false" class="g-ach ng">未達</span>
                  </div>
                </div>
                <div class="g-track">
                  <div class="g-bar" :class="{ overdue: row.overdue }" :style="{ left: goalBar(row).left + 'px', width: goalBar(row).width + 'px', background: hexA(row.color, 0.16), borderColor: row.color }">
                    <div class="g-fill" :style="{ width: row.pct + '%', background: row.color }"></div>
                    <span class="g-bar-pct">{{ row.pct }}%</span>
                  </div>
                  <!-- 目安ライン（緑の縦破線） -->
                  <div class="g-pace-line" :style="{ left: goalPacePx(row) + 'px' }" title="今日の目安ライン"></div>
                  <span class="g-deadline" :class="{ overdue: row.overdue }" :style="{ left: gantt.posPx(row.deadline) + 'px' }">{{ fmtMd(row.deadline) }}</span>
                </div>
              </div>
              <!-- 紐づけ項目（1行: チェック → バッジ → 項目名） -->
              <div v-else class="g-row gi">
                <div class="g-label-col gi-label">
                  <button
                    class="gi-check"
                    :class="{ on: row.studied }"
                    :title="row.studied ? '学習済み（クリックで解除）' : 'クリックで学習済みにする'"
                    @click.stop="toggleGanttItem(row.parentGoalId, row.id, row.studied)"
                  >{{ row.studied ? '✓' : '' }}</button>
                  <span v-if="row.type" class="rv-badge" :style="{ background: TYPE_BADGE[row.type].bg, color: TYPE_BADGE[row.type].fg }">{{ row.type }}</span>
                  <span class="gi-ttl" :style="{ textDecoration: row.studied ? 'line-through' : 'none', color: row.studied ? '#9aa1ab' : '#4b5563' }">{{ row.title }}</span>
                </div>
                <div class="g-track">
                  <div class="g-bar gi-bar" :style="{ left: barPx(row.deadline).left + 'px', width: barPx(row.deadline).width + 'px' }">
                    <div v-if="row.studied" class="g-fill" :style="{ width: '100%', background: row.color }"></div>
                  </div>
                  <span v-if="row.studiedOn" class="gi-date" :style="{ left: gantt.posPx(row.studiedOn) + 'px' }">◆</span>
                </div>
              </div>
            </template>
            <div v-if="Object.values(ganttItemsLoading).some(Boolean)" class="g-loading">項目を読み込み中…</div>
            <!-- 罫線 + 予定線 + 今日ライン（行領域に重ねる。見出しには重ならない） -->
            <div class="g-overlay" :style="{ left: gantt.labelW + 'px', width: gantt.width + 'px' }">
              <div v-for="mx in gantt.months" :key="'m' + mx" class="g-grid" :style="{ left: mx + 'px' }"></div>
              <div v-for="e in eventsInRange" :key="'evl' + e.id" class="g-evt-line" :style="{ left: gantt.posPx(e.date) + 'px' }"></div>
              <div class="g-today" :style="{ left: gantt.todayPx + 'px' }"></div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Review tab -->
    <div v-else-if="tab === 'review'">
      <div v-if="!study.reviews.length" class="card" style="padding: 40px; text-align: center; color: var(--faint); font-size: 13px; line-height: 1.7">
        復習項目はありません。<br />個別学習一覧データで学習記録を追加する際に「復習期限」を設定すると、期限が近い順にここへ表示されます。
      </div>
      <template v-else>
        <div class="row-between" style="margin-bottom: 13px; flex-wrap: wrap; gap: 10px">
          <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap">
            <div class="seg rv-seg">
              <button class="seg-btn" :class="{ on: reviewFilter === 'pending' }" @click="reviewFilter = 'pending'">未復習<span v-if="pendingReviews.length" class="flt-num">{{ pendingReviews.length }}</span></button>
              <button class="seg-btn" :class="{ on: reviewFilter === 'done' }" @click="reviewFilter = 'done'">復習済み<span v-if="doneReviews.length" class="flt-num">{{ doneReviews.length }}</span></button>
              <button class="seg-btn" :class="{ on: reviewFilter === 'all' }" @click="reviewFilter = 'all'">すべて</button>
            </div>
            <span v-if="reviewDueCount" style="color: #e0533d; font-weight: 700; font-size: 12px">要復習 {{ reviewDueCount }} 件</span>
          </div>
          <button class="btn-print" @click="printReviews">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9V2h12v7M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2M6 14h12v8H6z" /></svg>
            チェックシート出力
          </button>
        </div>
        <div v-if="!filteredReviews.length" class="card" style="padding: 32px; text-align: center; color: var(--faint); font-size: 13px">
          {{ reviewFilter === 'done' ? '復習済みの項目はまだありません。' : '該当する復習項目はありません。' }}
        </div>
        <div v-else class="card" style="overflow: hidden; padding: 0">
          <div style="overflow-x: auto">
            <table class="rv-tbl">
              <thead>
                <tr>
                  <th style="width: 100px; text-align: center">復習記録</th>
                  <th style="width: 78px; text-align: center">状態</th>
                  <th style="width: 74px">種別</th>
                  <th style="width: 96px">科目</th>
                  <th>タイトル</th>
                  <th>出典 ｜ 中分類</th>
                  <th style="width: 62px; text-align: center">学習日</th>
                  <th style="width: 74px; text-align: center">復習期限</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="r in filteredReviews" :key="r.id" :class="{ overdue: r.overdue, donerow: r.reviewed }">
                  <td style="text-align: center">
                    <button v-if="!r.reviewed" class="rv-rec-btn" title="復習を記録して完了にする" @click="openReviewRecord(r)">復習記録</button>
                    <span v-else class="rv-done-badge">✓ {{ r.reviewedOn ? fmtMd(r.reviewedOn) : '完了' }}</span>
                  </td>
                  <td style="text-align: center">
                    <span v-if="r.reviewed" style="font-size: 11.5px; color: #9aa1ab">復習済み</span>
                    <span v-else :style="{ fontSize: '11.5px', fontWeight: 700, color: r.overdue ? '#e0533d' : '#2e9d62' }">{{ reviewDayLabel(r.reviewOn) }}</span>
                  </td>
                  <td>
                    <span v-if="r.type" class="rv-badge" :style="{ background: TYPE_BADGE[r.type].bg, color: TYPE_BADGE[r.type].fg }">{{ r.type }}</span>
                  </td>
                  <td>
                    <span style="display: inline-flex; align-items: center; gap: 6px; min-width: 0">
                      <span :style="{ width: '8px', height: '8px', borderRadius: '50%', background: reviewColor(r), flexShrink: 0 }"></span>
                      <span style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis">{{ r.subjectName }}</span>
                    </span>
                  </td>
                  <td :style="{ fontWeight: 700, color: recordColorHex(r.color), textDecoration: r.reviewed ? 'line-through' : 'none', opacity: r.reviewed ? 0.6 : 1 }">{{ r.title ?? r.sub ?? '（無題）' }}</td>
                  <td style="color: var(--faint); font-size: 11.5px">{{ r.bookTitle }}<span v-if="r.major"> ｜ {{ r.major }}›{{ r.mid }}</span></td>
                  <td style="text-align: center; color: var(--faint)">{{ fmtMd(r.studiedOn) }}</td>
                  <td style="text-align: center; font-weight: 700" :style="{ color: r.overdue ? '#e0533d' : '#1c2024' }">{{ fmtMd(r.reviewOn) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </template>
    </div>

    <!-- 復習記録モーダル -->
    <Teleport to="body">
    <div v-if="reviewModal.open" class="modal-bg" @click.self="reviewModal.open = false">
      <div class="modal">
        <div class="modal-title">復習を記録</div>
        <div v-if="reviewModal.item" style="font-size: 12.5px; color: var(--mut); margin-top: -4px">
          {{ reviewModal.item.title ?? reviewModal.item.sub ?? '（無題）' }}
          <span style="color: var(--faint)"> ｜ {{ reviewModal.item.bookTitle }}</span>
        </div>
        <div class="rv-form-row">
          <label class="fld" style="flex: 1"><span>復習した日</span><input v-model="reviewModal.date" type="date" /></label>
          <div class="fld" style="flex: 0 0 auto">
            <span>色</span>
            <div style="display: flex; align-items: center; gap: 8px; height: 37px">
              <button class="rec-dot none" :class="{ sel: reviewModal.color === null }" title="色なし" @click="reviewModal.color = null"></button>
              <button v-for="c in RECORD_COLOR_KEYS" :key="c" class="rec-dot" :class="{ sel: reviewModal.color === c }" :style="{ background: DATE_COLORS[c] }" :title="COLOR_LABEL[c]" @click="reviewModal.color = c"></button>
            </div>
          </div>
        </div>
        <div class="fld">
          <span>次回の復習期限</span>
          <div class="review-opts">
            <button v-for="(opt, i) in REVIEW_OPTIONS" :key="opt.label" class="review-chip" :class="{ on: reviewModal.reviewIdx === i }" @click="reviewModal.reviewIdx = i">{{ opt.label }}</button>
            <input v-if="REVIEW_OPTIONS[reviewModal.reviewIdx].kind === 'custom'" v-model.number="reviewModal.customDays" type="number" min="1" class="review-custom" placeholder="日数" />
          </div>
          <span style="font-size: 11px; color: var(--faint); font-weight: 400; margin-top: 4px">{{ reviewModalPreview }}</span>
        </div>
        <div class="modal-actions">
          <button class="btn-out" @click="reviewModal.open = false">キャンセル</button>
          <button class="btn-dark" :disabled="reviewModal.saving" @click="submitReviewRecord">復習を記録して完了</button>
        </div>
      </div>
    </div>
    </Teleport>

    <EventModal
      v-if="eventModal"
      :date="eventModal.date"
      :title="eventModal.title"
      @save="saveEvent"
      @delete="deleteEvent"
      @close="eventModal = null"
    />
  </div>
</template>

<style scoped>
.seg {
  display: flex;
  background: #fff;
  border: 1px solid #e6e8eb;
  border-radius: 11px;
  padding: 3px;
}
.seg-btn {
  padding: 8px 18px;
  border: none;
  border-radius: 8px;
  cursor: pointer;
  font-size: 13px;
  font-weight: 600;
  background: transparent;
  color: var(--mut);
}
.seg-btn.on {
  background: #1c2024;
  color: #fff;
}
.tab-badge {
  margin-left: 6px;
  font-size: 10px;
  font-weight: 700;
  background: #e0533d;
  color: #fff;
  border-radius: 99px;
  padding: 1px 6px;
}
/* ===== ガントチャート ===== */
.gantt-card {
  padding: 14px 0 16px;
  overflow-x: auto;
  /* 共通コンテンツ幅(約1280px)を打ち消しビューポート幅へ拡張。
     transform を使うと内部の position:sticky(見出し列固定)が壊れるため margin で実現する。 */
  width: calc(100vw - 16px);
  margin-left: calc(50% - 50vw + 8px);
}
@media (max-width: 880px) {
  /* スマホでは横幅ブレイクアウトを解除（見切れ防止） */
  .gantt-card {
    width: auto;
    margin-left: 0;
  }
}
.gantt {
  position: relative;
}
.g-label-col {
  width: 300px;
  flex-shrink: 0;
  padding-left: 16px;
  padding-right: 12px;
  box-sizing: border-box;
  position: sticky;
  left: 0;
  z-index: 5;
  background: #fff;
  display: flex;
  flex-direction: column;
  justify-content: center;
}
.g-axis-row {
  display: flex;
  align-items: stretch;
  height: 46px;
  border-bottom: 1px solid #eceef1;
  margin-bottom: 2px;
}
.g-corner {
  font-size: 11px;
  font-weight: 700;
  color: var(--faint);
  position: sticky;
  left: 0;
  z-index: 10;
  background: #fff;
}
.g-axis {
  position: relative;
  flex-shrink: 0;
  height: 100%;
}
/* カレンダー見出し（日付＋曜日） */
.g-day {
  position: absolute;
  top: 0;
  bottom: 0;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 1px;
  border-left: 1px solid #f0f1f3;
  box-sizing: border-box;
}
.g-day.sat {
  background: rgba(37, 99, 235, 0.1);
}
.g-day.sun {
  background: rgba(224, 83, 61, 0.1);
}
.g-day-num {
  font-size: 11px;
  font-weight: 600;
  color: #4b5563;
  line-height: 1.1;
}
.g-day-wd {
  font-size: 9.5px;
  color: #9aa1ab;
  line-height: 1.1;
}
.g-day.sat .g-day-num,
.g-day.sat .g-day-wd {
  color: #2563eb;
}
.g-day.sun .g-day-num,
.g-day.sun .g-day-wd {
  color: #d64550;
}
.g-rows {
  position: relative;
  z-index: 1;
}
/* 土日の背景帯（本文） */
.g-weekend-layer {
  position: absolute;
  top: 0;
  bottom: 0;
  z-index: 0;
  pointer-events: none;
}
.g-weekend-band {
  position: absolute;
  top: 0;
  bottom: 0;
}
.g-weekend-band.sat {
  background: rgba(37, 99, 235, 0.05);
}
.g-weekend-band.sun {
  background: rgba(224, 83, 61, 0.05);
}
.g-row {
  display: flex;
  align-items: stretch;
  min-height: 40px;
  border-top: 1px solid #f4f5f7;
  cursor: pointer;
  position: relative;
  z-index: 1;
}
.g-row:first-child {
  border-top: none;
}
.g-row:hover {
  background: #f8f9fb;
}
.g-row:hover .g-label-col {
  background: #f8f9fb;
}
.g-row.sub .g-label-col {
  padding-left: 14px;
}
.g-chev {
  width: 16px;
  height: 16px;
  border: none;
  background: none;
  cursor: pointer;
  color: #9aa1ab;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  padding: 0;
}
.g-chev-sp {
  width: 16px;
  flex-shrink: 0;
  display: inline-block;
}
.g-title-txt {
  white-space: normal;
  word-break: break-word;
  cursor: pointer;
}
/* 紐づけ項目行 */
.g-row.gi {
  min-height: 30px;
}
.gi-label {
  display: flex;
  flex-direction: row;
  align-items: center;
  justify-content: flex-start;
  gap: 7px;
  padding-left: 22px;
}
.gi-check {
  width: 18px;
  height: 18px;
  flex-shrink: 0;
  border: 1.6px solid #cbd1d8;
  border-radius: 5px;
  background: #fff;
  cursor: pointer;
  font-size: 12px;
  font-weight: 700;
  line-height: 1;
  color: #fff;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 0;
}
.gi-check.on {
  background: #2e9d62;
  border-color: #2e9d62;
}
.gi-ttl {
  font-size: 12px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.gi-bar {
  border: 1.2px solid #dfe3e8 !important;
  background: #fff !important;
  height: 14px !important;
  border-radius: 5px !important;
}
.gi-date {
  position: absolute;
  top: 50%;
  transform: translate(-50%, -50%);
  font-size: 10px;
  color: #2e9d62;
}
.g-loading {
  padding: 10px 12px;
  font-size: 12px;
  color: var(--faint);
}
/* 予定（カレンダー） */
.g-evt-row {
  background: #fbfaff;
}
.g-evt-cal {
  margin-right: 4px;
}
.g-evt {
  position: absolute;
  top: 9px;
  display: inline-flex;
  align-items: center;
  gap: 3px;
  transform: translateX(-4px);
  white-space: nowrap;
}
.g-evt-pin {
  width: 8px;
  height: 8px;
  border-radius: 2px;
  background: #7c5cff;
  transform: rotate(45deg);
  flex-shrink: 0;
}
.g-evt-txt {
  font-size: 10.5px;
  font-weight: 600;
  color: #5849c0;
  background: #fff;
  padding: 0 4px;
  border-radius: 4px;
}
.g-evt-line {
  position: absolute;
  top: 0;
  bottom: 0;
  width: 1px;
  background: rgba(124, 92, 255, 0.35);
}
.g-title {
  font-size: 12.5px;
  font-weight: 600;
  color: #1c2024;
  display: flex;
  align-items: flex-start;
  gap: 5px;
  line-height: 1.35;
}
.g-pace-tag {
  flex-shrink: 0;
  font-size: 9.5px;
  font-weight: 700;
  padding: 0 5px;
  border-radius: 99px;
}
.g-pace-tag.ok {
  background: #eaf7ef;
  color: #1f7a45;
}
.g-pace-tag.ng {
  background: #fdeef0;
  color: #c0444f;
}
.g-subtag {
  flex-shrink: 0;
  font-size: 9.5px;
  font-weight: 700;
  color: #5849c0;
  background: #eeecfa;
  padding: 0 5px;
  border-radius: 99px;
}
.g-meta {
  font-size: 10.5px;
  color: var(--faint);
  margin-top: 2px;
  display: flex;
  align-items: center;
  gap: 6px;
}
.g-ach {
  font-size: 9.5px;
  font-weight: 700;
  padding: 0 5px;
  border-radius: 99px;
}
.g-ach.ok {
  background: #eaf7ef;
  color: #1f7a45;
}
.g-ach.ng {
  background: #fdeef0;
  color: #c0444f;
}
.g-track {
  position: relative;
  flex: 1;
  min-width: 0;
  min-height: 40px;
}
.g-bar {
  position: absolute;
  top: 50%;
  transform: translateY(-50%);
  height: 18px;
  border: 1.5px solid;
  border-radius: 6px;
  overflow: hidden;
  min-width: 6px;
}
.g-bar.overdue {
  border-style: dashed;
}
.g-fill {
  height: 100%;
}
.g-bar-pct {
  position: absolute;
  right: 6px;
  top: 50%;
  transform: translateY(-50%);
  font-size: 10px;
  font-weight: 700;
  color: #1c2024;
  line-height: 1;
  pointer-events: none;
}
.g-deadline {
  position: absolute;
  top: 50%;
  transform: translate(6px, -50%);
  font-size: 10px;
  font-weight: 600;
  color: #6b7280;
  white-space: nowrap;
}
.g-deadline.overdue {
  color: #e0533d;
}
/* 目安ライン（緑の縦破線・バー上に表示） */
.g-pace-line {
  position: absolute;
  top: 50%;
  transform: translateY(-50%);
  height: 22px;
  width: 0;
  border-left: 2px dashed #2e9d62;
  pointer-events: none;
}
/* 今日の目安（按分）フラグ */
.g-pace {
  position: absolute;
  top: 4px;
  bottom: 4px;
  width: 0;
  border-left: 2px dotted #2e9d62;
}
.g-pace.behind {
  border-left-color: #e0533d;
}
.g-pace-flag {
  position: absolute;
  top: -1px;
  left: 3px;
  font-size: 9px;
  font-weight: 700;
  color: #2e9d62;
  background: #fff;
  padding: 0 3px;
  border-radius: 3px;
  white-space: nowrap;
}
.g-pace.behind .g-pace-flag {
  color: #e0533d;
}
.g-overlay {
  position: absolute;
  top: 0;
  bottom: 0;
  z-index: 0; /* 行の背面。見出し列(不透明)で覆えるようにする */
  pointer-events: none;
}
.g-grid {
  position: absolute;
  top: 0;
  bottom: 0;
  width: 1px;
  background: rgba(28, 32, 36, 0.05);
}
.g-today {
  position: absolute;
  top: 0;
  bottom: 0;
  width: 0;
  border-left: 1.5px dashed #e0533d;
}
.g-today span {
  position: absolute;
  top: 2px;
  left: 3px;
  font-size: 9px;
  font-weight: 700;
  color: #e0533d;
  background: #fff;
  padding: 0 3px;
}
.rv-badge {
  display: inline-block;
  flex-shrink: 0;
  font-size: 10px;
  font-weight: 700;
  padding: 1px 7px;
  border-radius: 99px;
  white-space: nowrap;
}
/* 復習項目 一覧テーブル */
.rv-tbl {
  width: 100%;
  border-collapse: collapse;
  font-size: 13px;
  min-width: 760px;
}
.rv-tbl thead tr {
  background: #f8f9fb;
  color: var(--faint);
  font-size: 11.5px;
  text-align: left;
}
.rv-tbl th {
  padding: 10px;
  font-weight: 600;
}
.rv-tbl th:first-child {
  padding-left: 14px;
}
.rv-tbl td {
  padding: 10px;
  border-top: 1px solid #f0f1f3;
  vertical-align: middle;
}
.rv-tbl td:first-child {
  padding-left: 14px;
}
.rv-tbl tbody tr.overdue {
  background: #fff8f7;
}
.rv-tbl tbody tr:hover {
  background: #f8f9fb;
}
.rv-tbl tbody tr.overdue:hover {
  background: #fff2f0;
}
/* チェックシート出力ボタン */
.btn-print {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 8px 14px;
  border: 1px solid #e3e6ea;
  border-radius: 10px;
  background: #fff;
  color: #1c2024;
  font-size: 12.5px;
  font-weight: 600;
  cursor: pointer;
}
.btn-print:hover {
  background: #1c2024;
  color: #fff;
  border-color: #1c2024;
}
/* 復習フィルター */
.rv-seg {
  padding: 3px;
}
.rv-seg .seg-btn {
  padding: 6px 13px;
  font-size: 12.5px;
  display: inline-flex;
  align-items: center;
  gap: 6px;
}
.flt-num {
  font-size: 10.5px;
  font-weight: 700;
  background: #eceef1;
  color: #6b7280;
  border-radius: 99px;
  padding: 0 6px;
  min-width: 16px;
  text-align: center;
}
.rv-seg .seg-btn.on .flt-num {
  background: rgba(255, 255, 255, 0.25);
  color: #fff;
}
/* 復習記録ボタン / 完了バッジ */
.rv-rec-btn {
  border: 1px solid #d7dbe0;
  background: #fff;
  color: #1c2024;
  border-radius: 8px;
  padding: 5px 12px;
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
  white-space: nowrap;
}
.rv-rec-btn:hover {
  background: #1c2024;
  color: #fff;
  border-color: #1c2024;
}
.rv-done-badge {
  display: inline-block;
  font-size: 11px;
  font-weight: 700;
  color: #2e9d62;
  background: #eaf6ee;
  border: 1px solid #c4e6d0;
  border-radius: 99px;
  padding: 2px 9px;
  white-space: nowrap;
}
.rv-tbl tbody tr.donerow {
  background: #fafbfc;
}
.rv-tbl tbody tr.donerow:hover {
  background: #f4f6f8;
}
/* 復習記録モーダル */
.modal-bg {
  position: fixed;
  inset: 0;
  background: rgba(20, 22, 26, 0.42);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 50;
  padding: 16px;
}
.modal {
  background: #fff;
  border-radius: 15px;
  padding: 22px;
  width: 100%;
  max-width: 480px;
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.modal-title {
  font-size: 16px;
  font-weight: 700;
}
.modal .fld span {
  font-size: 12px;
  color: var(--mut);
  font-weight: 500;
  display: block;
  margin-bottom: 5px;
}
.modal .fld input {
  width: 100%;
  padding: 9px 11px;
  border: 1px solid #e3e6ea;
  border-radius: 9px;
  font-size: 13px;
  outline: none;
  background: #fff;
}
.rv-form-row {
  display: flex;
  gap: 12px;
  align-items: flex-end;
  flex-wrap: wrap;
}
.modal-actions {
  display: flex;
  justify-content: flex-end;
  gap: 9px;
  margin-top: 4px;
}
.btn-out {
  padding: 9px 15px;
  border: 1px solid #e3e6ea;
  border-radius: 10px;
  background: #fff;
  color: #1c2024;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
}
.btn-dark {
  padding: 9px 15px;
  border: none;
  border-radius: 10px;
  background: #1c2024;
  color: #fff;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
}
.btn-dark:disabled {
  opacity: 0.5;
  cursor: default;
}
.rec-dot {
  width: 20px;
  height: 20px;
  border-radius: 50%;
  border: 1.5px solid #fff;
  box-shadow: 0 0 0 1px rgba(0, 0, 0, 0.14);
  cursor: pointer;
  padding: 0;
}
.rec-dot:hover {
  transform: scale(1.12);
}
.rec-dot.sel {
  box-shadow: 0 0 0 2px #1c2024;
}
.rec-dot.none {
  background: #fff;
  box-shadow: 0 0 0 1px #cbd1d8;
}
.rec-dot.none.sel {
  box-shadow: 0 0 0 2px #1c2024;
}
.review-opts {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  align-items: center;
}
.review-chip {
  border: 1px solid #e3e6ea;
  background: #fff;
  border-radius: 8px;
  padding: 6px 11px;
  font-size: 12px;
  font-weight: 600;
  color: #4b5563;
  cursor: pointer;
}
.review-chip.on {
  background: #1c2024;
  border-color: #1c2024;
  color: #fff;
}
.modal .fld input.review-custom {
  width: 84px;
  padding: 7px 9px;
}
.subgoal-mini {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 3px 2px;
  font-size: 12px;
}
.sm-title {
  flex: 1;
  min-width: 0;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  font-weight: 600;
  color: #4b5563;
}
.sm-meta {
  flex-shrink: 0;
  font-size: 11px;
  color: var(--faint);
}
.goal-expand {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  border: none;
  background: none;
  padding: 2px 0;
  cursor: pointer;
  font-size: 12px;
  font-weight: 600;
  color: #3b50cc;
}
.goal-item {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 5px 4px;
  font-size: 12px;
  min-width: 0;
  border: none;
  background: none;
  cursor: pointer;
  text-align: left;
  border-radius: 6px;
}
.goal-item:hover {
  background: #f6f8fb;
}
.gi-badge {
  flex-shrink: 0;
  font-size: 10px;
  font-weight: 700;
  padding: 1px 6px;
  border-radius: 99px;
  width: 44px;
  text-align: center;
}
.gi-mark {
  flex-shrink: 0;
  font-weight: 700;
  width: 14px;
  text-align: center;
}
.gi-title {
  flex: 1;
  min-width: 0;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.gi-src {
  flex-shrink: 0;
  max-width: 40%;
  font-size: 10.5px;
  color: #aeb4bd;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.row-between {
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.track {
  background: #eef0f3;
  border-radius: 99px;
  overflow: hidden;
}
.stat-box {
  flex: 1;
  background: #f8f9fb;
  border-radius: 10px;
  padding: 9px 12px;
}
.toggle-btn {
  width: 100%;
  margin-top: 8px;
  padding: 7px;
  border: none;
  background: transparent;
  color: var(--mut);
  font-size: 12px;
  font-weight: 500;
  cursor: pointer;
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 5px;
}
</style>
