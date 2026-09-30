<script setup lang="ts">
import { computed, onBeforeUnmount, reactive, ref } from 'vue'
import { rateColor } from '@/api/quiz'
import type { QuizSummary } from '@/types'

/**
 * 小テスト一覧のテーブル表示（生徒・講師共用）。
 * 1行＝1パート（教材ごと）。科目 → 教材ごとにテーブルを分け、ステータスはバッジで色分けする。
 * ステータス・教材・出題日・キーワードで絞り込める。講師のメモ・生徒の一言は左端のコメントアイコンをタップすると表示する。
 */
const props = defineProps<{ quizzes: QuizSummary[]; role: 'owner' | 'tutor' }>()
const emit = defineEmits<{
  pdf: [q: QuizSummary]
  capture: [q: QuizSummary]
  result: [q: QuizSummary]
  grade: [q: QuizSummary]
  edit: [q: QuizSummary]
  remove: [q: QuizSummary]
}>()

type StatusKey = 'all' | 'assigned' | 'overdue' | 'submitted' | 'graded'
type PeriodKey = 'all' | 'week' | 'month' | 'quarter'
type SortKey = 'created' | 'due' | 'rateAsc' | 'rateDesc'

const DEFAULT_STATUS: Exclude<StatusKey, 'all'>[] = props.role === 'tutor' ? ['submitted', 'assigned', 'overdue'] : ['assigned', 'overdue']
const filter = reactive<{ status: Exclude<StatusKey, 'all'>[]; book: string; period: PeriodKey; q: string; sort: SortKey }>({
  status: [...DEFAULT_STATUS],
  book: '',
  period: 'all',
  q: '',
  sort: 'created',
})

/** 行の表示上のステータス（未提出のうち期限切れは overdue） */
function statusOf(q: QuizSummary): Exclude<StatusKey, 'all'> {
  if (q.status === 'graded') return 'graded'
  if (q.status === 'submitted') return 'submitted'
  return q.overdue ? 'overdue' : 'assigned'
}
const STATUS_LABEL = computed<Record<Exclude<StatusKey, 'all'>, string>>(() => ({
  assigned: '未提出',
  overdue: '期限切れ',
  submitted: props.role === 'tutor' ? '採点・添削待ち' : '提出済み',
  graded: '採点・添削済み',
}))
/** 絞り込みチップの並び（生徒側は「提出済み」の絞りは出さない） */
const STATUS_ORDER = computed<Exclude<StatusKey, 'all'>[]>(() => (props.role === 'tutor' ? ['submitted', 'assigned', 'overdue', 'graded'] : ['assigned', 'overdue', 'graded']))
function toggleStatus(k: Exclude<StatusKey, 'all'>) {
  const i = filter.status.indexOf(k)
  if (i >= 0) filter.status.splice(i, 1)
  else filter.status.push(k)
}

function partLabel(q: QuizSummary): string {
  return q.bookTitle ?? '英単語テスト'
}
/** 同じ出題（箱）内でのパート番号 */
const partIndex = computed(() => {
  const groups = new Map<string, number[]>()
  for (const q of props.quizzes) {
    const k = q.groupKey ?? `solo-${q.id}`
    if (!groups.has(k)) groups.set(k, [])
    groups.get(k)!.push(q.id)
  }
  const m: Record<number, { i: number; n: number }> = {}
  for (const ids of groups.values()) {
    ids.sort((a, b) => a - b)
    ids.forEach((id, i) => (m[id] = { i: i + 1, n: ids.length }))
  }
  return m
})

const counts = computed(() => {
  const c: Record<Exclude<StatusKey, 'all'>, number> = { assigned: 0, overdue: 0, submitted: 0, graded: 0 }
  for (const q of props.quizzes) c[statusOf(q)]++
  return c
})
const books = computed(() => Array.from(new Set(props.quizzes.map(partLabel))).sort())

function periodFrom(): string | null {
  if (filter.period === 'all') return null
  const d = new Date()
  d.setHours(0, 0, 0, 0)
  if (filter.period === 'week') d.setDate(d.getDate() - 6)
  else if (filter.period === 'month') d.setMonth(d.getMonth() - 1)
  else d.setMonth(d.getMonth() - 3)
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

const rows = computed(() => {
  const term = filter.q.trim().toLowerCase()
  const from = periodFrom()
  let list = props.quizzes.filter((q) => {
    if (filter.status.length && !filter.status.includes(statusOf(q))) return false
    if (filter.book && partLabel(q) !== filter.book) return false
    if (from && q.createdOn < from) return false
    if (term && !`${q.title} ${q.note ?? ''} ${partLabel(q)} ${q.createdByName ?? ''}`.toLowerCase().includes(term)) return false
    return true
  })
  const rateOf = (q: QuizSummary) => (q.status === 'graded' && q.rate !== null ? q.rate : null)
  list = [...list].sort((a, b) => {
    if (filter.sort === 'due') {
      const ad = a.dueOn ?? '9999-99-99'
      const bd = b.dueOn ?? '9999-99-99'
      return ad.localeCompare(bd) || b.id - a.id
    }
    if (filter.sort === 'rateAsc' || filter.sort === 'rateDesc') {
      const ar = rateOf(a)
      const br = rateOf(b)
      if (ar === null && br === null) return b.id - a.id
      if (ar === null) return 1
      if (br === null) return -1
      return filter.sort === 'rateAsc' ? ar - br || b.id - a.id : br - ar || b.id - a.id
    }
    return b.createdOn.localeCompare(a.createdOn) || b.id - a.id
  })
  return list
})
/** 科目（並び順 → 名前順）→ 教材ごとにテーブルを分ける。英単語テストは「英語」に入る */
const groups = computed(() => {
  const m = new Map<string, { name: string; order: number; color: string; books: Map<string, { name: string; rows: QuizSummary[] }> }>()
  for (const q of rows.value) {
    const g = m.get(q.subjectName) ?? { name: q.subjectName, order: q.subjectOrder, color: q.subjectColor, books: new Map() }
    const bn = partLabel(q)
    const b = g.books.get(bn) ?? { name: bn, rows: [] }
    b.rows.push(q)
    g.books.set(bn, b)
    m.set(q.subjectName, g)
  }
  return [...m.values()]
    .map((g) => ({ ...g, books: [...g.books.values()] }))
    .sort((a, b) => a.order - b.order || a.name.localeCompare(b.name, 'ja'))
})
/** 行のタイトル（同じ出題に複数の教材があるときは「1/2」を添える） */
function quizLabel(q: QuizSummary): string {
  const p = partIndex.value[q.id]
  return p && p.n > 1 ? `${q.title}（${p.i}/${p.n}）` : q.title
}

// ---- コメント（講師のメモ・生徒の一言）のポップオーバー ----
const hasNote = (q: QuizSummary) => !!(q.note || q.submitNote)
const notePop = ref<{ id: number; title: string; note: string | null; submitNote: string | null; who: string; top: number; left: number } | null>(null)
function toggleNote(q: QuizSummary, e: MouseEvent) {
  if (notePop.value?.id === q.id) {
    closeNote()
    return
  }
  const r = (e.currentTarget as HTMLElement).getBoundingClientRect()
  const w = Math.min(320, window.innerWidth - 16)
  notePop.value = {
    id: q.id,
    title: quizLabel(q),
    note: q.note ?? null,
    submitNote: q.submitNote ?? null,
    who: surname(q) || '生徒',
    top: r.bottom + 6,
    left: Math.max(8, Math.min(r.left, window.innerWidth - w - 8)),
  }
  window.setTimeout(() => {
    document.addEventListener('pointerdown', onDocDown, true)
    window.addEventListener('scroll', closeNote, true)
  })
}
function closeNote() {
  notePop.value = null
  document.removeEventListener('pointerdown', onDocDown, true)
  window.removeEventListener('scroll', closeNote, true)
}
function onDocDown(e: Event) {
  if (!(e.target as HTMLElement).closest('.note-pop, .note-btn')) closeNote()
}
onBeforeUnmount(closeNote)
/** 背景色の明るさに応じて文字色を白／黒にする */
function textOn(bg: string): string {
  const m = /^#?([0-9a-f]{6})$/i.exec(bg.trim())
  if (!m) return '#fff'
  const n = parseInt(m[1]!, 16)
  const r = (n >> 16) & 255
  const g = (n >> 8) & 255
  const b = n & 255
  const lum = (0.2126 * r + 0.7152 * g + 0.0722 * b) / 255
  return lum > 0.6 ? '#1c2024' : '#fff'
}
/** 生徒の姓（「中田 智允」→「中田」）。一言の頭に（姓）を付ける */
function surname(q: QuizSummary): string {
  return (q.studentName ?? '').trim().split(/[\s　]+/)[0] ?? ''
}
/** 絞り込み欄は既定で折りたたみ */
const filtersOpen = ref(false)
const filtered = computed(() => filter.status.length > 0 || !!filter.book || filter.period !== 'all' || !!filter.q.trim())
function clearFilters() {
  filter.status = []
  filter.book = ''
  filter.period = 'all'
  filter.q = ''
}

function fmt(d: string | null): string {
  if (!d) return '–'
  const [y, m, dd] = d.split(/[- :T]/)
  return `${y}/${Number(m)}/${Number(dd)}`
}
function dueClass(q: QuizSummary): string {
  if (q.status !== 'assigned' || !q.dueOn) return ''
  if (q.overdue) return 'due-over'
  const d = new Date(q.dueOn)
  const today = new Date()
  today.setHours(0, 0, 0, 0)
  return (d.getTime() - today.getTime()) / 86400000 <= 3 ? 'due-soon' : ''
}
</script>

<template>
  <div class="qt">
    <!-- 絞り込み: 既定は折りたたみ。ステータス（複数選択）・教材・出題日・キーワード・並び順 -->
    <div class="filters">
      <div class="filter-head">
        <button class="ftoggle" :class="{ on: filtersOpen }" aria-label="絞り込み" @click="filtersOpen = !filtersOpen">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 5h18l-7 8v6l-4 2v-8z" /></svg>
          <svg class="chev" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6" /></svg>
        </button>
        <button v-if="filtered" class="clear" @click="clearFilters">解除</button>
        <span class="cnt">{{ rows.length }} / {{ quizzes.length }}件</span>
      </div>
      <div v-if="filtersOpen" class="status-tabs">
        <button class="st all" :class="{ on: !filter.status.length }" @click="filter.status = []">すべて<span class="n">{{ quizzes.length }}</span></button>
        <button v-for="k in STATUS_ORDER" :key="k" class="st" :class="[k, { on: filter.status.includes(k) }]" @click="toggleStatus(k)">
          {{ STATUS_LABEL[k] }}<span class="n">{{ counts[k] }}</span>
        </button>
      </div>
      <div v-if="filtersOpen" class="filter-row">
        <select v-model="filter.book" class="sel">
          <option value="">すべての教材</option>
          <option v-for="b in books" :key="b" :value="b">{{ b }}</option>
        </select>
        <select v-model="filter.period" class="sel">
          <option value="all">出題日: すべて</option>
          <option value="week">出題日: 直近1週間</option>
          <option value="month">出題日: 直近1ヶ月</option>
          <option value="quarter">出題日: 直近3ヶ月</option>
        </select>
        <select v-model="filter.sort" class="sel">
          <option value="created">並び: 出題日が新しい順</option>
          <option value="due">並び: 期限が近い順</option>
          <option value="rateAsc">並び: 得点率が低い順</option>
          <option value="rateDesc">並び: 得点率が高い順</option>
        </select>
        <input v-model="filter.q" class="search" type="search" placeholder="タイトル・メモ・教材で検索" />
      </div>
    </div>

    <div v-for="g in groups" :key="g.name" class="group">
      <!-- 科目の見出し（科目色のバッジ） -->
      <div class="subj">
        <span class="subj-badge" :style="{ background: g.color, color: textOn(g.color) }">{{ g.name }}</span>
      </div>
      <!-- 教材ごとのテーブル -->
      <div v-for="b in g.books" :key="b.name" class="book-block">
        <div class="book-head">{{ b.name }}</div>
        <div class="tbl-wrap">
        <table class="tbl">
          <thead>
            <tr>
              <th class="c-note"></th>
              <th class="c-actions"></th>
              <th class="c-status">ステータス</th>
              <th class="c-date">出題日</th>
              <th class="c-pages r">ページ</th>
              <th class="c-due">期限</th>
              <th class="c-score">得点</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="q in b.rows" :key="q.id" :class="['row', statusOf(q)]" :title="quizLabel(q)">
              <!-- コメント: あれば濃いアイコン＋点、なければ薄いアイコン -->
              <td class="c-note">
                <button
                  class="note-btn"
                  :class="{ has: hasNote(q), on: notePop?.id === q.id }"
                  :disabled="!hasNote(q)"
                  :aria-label="hasNote(q) ? 'コメントを表示' : 'コメントなし'"
                  @click="toggleNote(q, $event)"
                >
                  <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M4 5.5h16v10.5H9.5L5 20v-4H4z" /></svg>
                  <span v-if="hasNote(q)" class="note-dot"></span>
                </button>
              </td>
            <td class="c-actions">
                <div class="acts">
                  <template v-if="role === 'owner'">
                    <button v-if="q.status === 'graded'" class="btn primary" @click="emit('result', q)">結果を見る</button>
                    <button v-if="q.status !== 'graded'" class="btn primary" @click="emit('capture', q)">{{ q.status === 'submitted' ? '撮り直して再提出' : '撮影して提出' }}</button>
                    <button v-else-if="q.selfGraded" class="btn" title="撮り直し・自己採点の点数の修正" @click="emit('capture', q)">再提出</button>
                    <button class="btn" title="問題 PDF を別タブでプレビュー" @click="emit('pdf', q)">問題PDF</button>
                  </template>
                  <template v-else>
                    <button v-if="q.status === 'submitted'" class="btn primary" @click="emit('grade', q)">採点・添削する</button>
                    <template v-else-if="q.status === 'graded'">
                      <button class="btn primary" @click="emit('result', q)">結果を見る</button>
                      <button class="btn" title="採点・添削画面を開く（やり直し・PDF）" @click="emit('grade', q)">採点・添削</button>
                    </template>
                    <button v-else class="btn" @click="emit('edit', q)">編集</button>
                    <button class="btn" title="問題 PDF を別タブでプレビュー" @click="emit('pdf', q)">問題PDF</button>
                    <button class="btn danger" @click="emit('remove', q)">削除</button>
                  </template>
                </div>
              </td>
              <td class="c-status">
                <div class="badges">
                  <span class="badge" :class="statusOf(q)">{{ STATUS_LABEL[statusOf(q)] }}</span>
                  <span v-if="q.status === 'graded' && q.selfGraded" class="badge self">自己採点</span>
                  <span v-if="q.status === 'assigned' && q.answeredCount" class="badge sub">{{ q.answeredCount }}/{{ q.pageCount }} 撮影済み</span>
                </div>
              </td>
              <td class="c-date nowrap">{{ fmt(q.createdOn) }}</td>
              <td class="c-pages r">{{ q.pageCount }}</td>
              <td class="c-due nowrap" :class="dueClass(q)">{{ fmt(q.dueOn) }}</td>
            <td class="c-score">
                <template v-if="q.status === 'graded' && q.score !== null">
                  <span class="score-line"><b :style="{ color: rateColor(q.rate) }">{{ q.score }}</b><span class="max"> / {{ q.maxScore }}点</span></span>
                  <span class="rate-line">
                    <span class="rate-bar"><span :style="{ width: (q.rate ?? 0) + '%', background: rateColor(q.rate) }"></span></span>
                    <span class="rate" :style="{ color: rateColor(q.rate) }">{{ q.rate ?? '–' }}%</span>
                  </span>
                </template>
                <span v-else class="dash">–</span>
              </td>
            </tr>
          </tbody>
        </table>
        </div>
      </div>
    </div>

    <!-- コメントのポップオーバー（テーブルの横スクロール枠に切られないよう body 直下に出す） -->
    <Teleport to="body">
      <Transition name="np">
        <div v-if="notePop" class="note-pop" :style="{ top: notePop.top + 'px', left: notePop.left + 'px' }">
          <div class="np-title">{{ notePop.title }}</div>
          <div v-if="notePop.note" class="np-row"><span class="np-lab tutor">講師</span><span class="np-text">{{ notePop.note }}</span></div>
          <div v-if="notePop.submitNote" class="np-row"><span class="np-lab student">{{ notePop.who }}</span><span class="np-text">{{ notePop.submitNote }}</span></div>
        </div>
      </Transition>
    </Teleport>
    <div v-if="!groups.length" class="tbl-wrap empty">条件に一致する小テストはありません</div>
  </div>
</template>

<style scoped>
.qt {
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.filters {
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.filter-head {
  display: flex;
  align-items: center;
  gap: 10px;
  min-width: 0;
}
.ftoggle {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 6px 12px;
  border: 1px solid #e3e6ea;
  border-radius: 999px;
  background: #fff;
  font-size: 12px;
  font-weight: 700;
  color: var(--mut);
  cursor: pointer;
  flex-shrink: 0;
}
.ftoggle.on {
  background: #1c2024;
  border-color: #1c2024;
  color: #fff;
}
.ftoggle .chev {
  transition: transform 0.15s;
}
.ftoggle.on .chev {
  transform: rotate(180deg);
}
.filter-head .clear {
  margin-left: 2px;
}
.status-tabs {
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
}
.st {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 6px 12px;
  border: 1px solid #e3e6ea;
  border-radius: 999px;
  background: #fff;
  font-size: 12px;
  font-weight: 700;
  color: var(--mut);
  cursor: pointer;
  --c: #6b7280;
  --bg: #f1f2f4;
}
.st .n {
  font-size: 11px;
  padding: 1px 7px;
  border-radius: 999px;
  background: var(--bg);
  color: var(--c);
  font-variant-numeric: tabular-nums;
}
.st.assigned {
  --c: #6b7280;
  --bg: #f1f2f4;
}
.st.overdue {
  --c: #c0444f;
  --bg: #fdf0f1;
}
.st.submitted {
  --c: #2e4a8f;
  --bg: #e8eefb;
}
.st.graded {
  --c: #2f7a4f;
  --bg: #e6f5ec;
}
.st.on {
  background: var(--c);
  border-color: var(--c);
  color: #fff;
}
.st.all.on {
  background: #1c2024;
  border-color: #1c2024;
}
.st.on .n {
  background: rgba(255, 255, 255, 0.22);
  color: #fff;
}
.filter-row {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}
.sel,
.search {
  padding: 7px 10px;
  border: 1px solid #e3e6ea;
  border-radius: 9px;
  background: #fff;
  font-size: 12px;
  color: var(--ink);
}
.search {
  flex: 1;
  min-width: 160px;
}
.clear {
  border: none;
  background: none;
  color: #3b50cc;
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
  white-space: nowrap;
}
.cnt {
  font-size: 11.5px;
  color: var(--faint);
  white-space: nowrap;
  margin-left: auto;
}
.tbl-wrap {
  background: #fff;
  border: 1px solid var(--line);
  border-radius: 14px;
  overflow-x: auto;
}
.group {
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.subj {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 0 2px;
}
.subj-badge {
  display: inline-block;
  padding: 3px 12px;
  border-radius: 999px;
  font-size: 12px;
  font-weight: 700;
  line-height: 1.5;
}
/* 教材ごとの見出しとテーブル */
.book-block {
  margin-bottom: 8px;
}
.book-block:last-child {
  margin-bottom: 0;
}
.book-head {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 4px 6px 5px;
  font-size: 12.5px;
  font-weight: 700;
  color: var(--ink);
}
.book-head::before {
  content: '';
  width: 3px;
  height: 12px;
  border-radius: 2px;
  background: #c9cfd8;
}
.tbl {
  width: 100%;
  min-width: 600px;
  border-collapse: collapse;
  font-size: 12.5px;
}
.tbl th {
  padding: 7px 10px;
  text-align: left;
  font-size: 11px;
  font-weight: 700;
  color: var(--mut);
  background: #f8f9fb;
  border-bottom: 1px solid var(--line);
  white-space: nowrap;
}
.tbl td {
  padding: 5px 10px;
  border-bottom: 1px solid #f1f2f4;
  vertical-align: middle;
  line-height: 1.35;
}
/* 行の背景は交互に */
.tbl tbody tr:nth-child(even) {
  background: #eef2f9;
}
.tbl tr:last-child td {
  border-bottom: none;
}
.r {
  text-align: right;
}
.nowrap {
  white-space: nowrap;
}
.tbl tbody tr:hover {
  background: #e3e9f6;
}
.c-date,
.c-due {
  width: 84px;
  color: var(--mut);
}
.c-pages {
  width: 56px;
}
.c-status {
  width: 130px;
  white-space: nowrap;
}
/* ステータスは縦に並べ、幅をそろえる */
.badges {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 3px;
}
.badges .badge {
  width: 112px;
  text-align: center;
  margin-left: 0;
  box-sizing: border-box;
}
.c-actions {
  width: 1%;
  white-space: nowrap;
}
.c-score {
  width: 150px;
}
/* コメントアイコンの列（幅は最小に） */
.c-note {
  width: 1%;
  padding-left: 6px;
  padding-right: 0;
}
.note-btn {
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
  width: 30px;
  height: 30px;
  border: none;
  border-radius: 8px;
  background: transparent;
  color: #d3d8df;
  cursor: default;
}
.note-btn.has {
  color: #5b3fa0;
  cursor: pointer;
}
.note-btn.has.on {
  background: #efe9fb;
}
.note-dot {
  position: absolute;
  top: 5px;
  right: 5px;
  width: 7px;
  height: 7px;
  border-radius: 50%;
  background: #e0533d;
  border: 1.5px solid #fff;
}
.badge {
  display: inline-block;
  font-size: 10.5px;
  font-weight: 700;
  padding: 3px 9px;
  border-radius: 999px;
  white-space: nowrap;
}
.badge.assigned {
  background: #f1f2f4;
  color: var(--mut);
}
.badge.overdue {
  background: #fdf0f1;
  color: #c0444f;
}
.badge.submitted {
  background: #e8eefb;
  color: #2e4a8f;
}
.badge.graded {
  background: #e6f5ec;
  color: #2f7a4f;
}
.badge.sub {
  background: #fff4e5;
  color: #b7681a;
  margin-left: 4px;
}
.badge.self {
  background: #efe9fb;
  color: #5b3fa0;
  margin-left: 4px;
}
.sub-date {
  font-size: 10.5px;
  color: var(--faint);
  margin-left: 6px;
}
.due-soon {
  color: #d98a1a;
  font-weight: 700;
}
.due-over {
  color: #c0444f;
  font-weight: 700;
}
.c-score {
  white-space: nowrap;
}
.score-line b {
  font-size: 14px;
}
.score-line .max {
  font-size: 11.5px;
  color: var(--mut);
}
.rate-line {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  margin-left: 8px;
  vertical-align: middle;
}
.rate-bar {
  flex: 1;
  height: 6px;
  border-radius: 3px;
  background: #eef0f3;
  overflow: hidden;
  max-width: 80px;
}
.rate-bar span {
  display: block;
  height: 100%;
  border-radius: 3px;
}
.rate {
  font-size: 11.5px;
  font-weight: 700;
  white-space: nowrap;
}
.dash {
  color: #cfd4db;
}
.acts {
  display: flex;
  gap: 5px;
  flex-wrap: nowrap;
}
.btn {
  padding: 4px 9px;
  border: 1px solid #e3e6ea;
  border-radius: 8px;
  background: #fff;
  font-size: 11.5px;
  font-weight: 600;
  color: var(--mut);
  cursor: pointer;
  white-space: nowrap;
}
.btn.primary {
  background: #1c2024;
  border-color: #1c2024;
  color: #fff;
  min-width: 118px;
  text-align: center;
}
.btn.danger {
  color: #c0444f;
}
.empty {
  text-align: center;
  color: var(--faint);
  padding: 28px 12px;
  font-size: 12.5px;
}
/* タブレット縦（講師側は zoom で拡大表示するため実質の幅が狭い）: 列を詰め、ページ数の列は出さずに横スクロールなしで収める */
@media (min-width: 641px) and (max-width: 900px) {
  .tbl {
    min-width: 0;
  }
  .tbl th,
  .tbl td {
    padding-left: 7px;
    padding-right: 7px;
  }
  .c-pages {
    display: none;
  }
  .c-status {
    width: 112px;
  }
  .badges .badge {
    width: 100px;
  }
  .c-date,
  .c-due {
    width: 74px;
  }
  .c-score {
    width: 118px;
  }
  .rate-line {
    margin-left: 4px;
  }
  /* 操作ボタンが 4 つある行（採点・添削済み）は 2 段に折り返して列幅を抑える */
  .acts {
    flex-wrap: wrap;
    max-width: 230px;
  }
}
@media (max-width: 640px) {
  /* スマホは横スクロールで表示（列を詰めて折り返すより読みやすい） */
  .tbl {
    min-width: 720px;
  }
  .sel {
    flex: 1;
    min-width: 0;
  }
  .cnt {
    width: 100%;
    text-align: right;
    margin-left: 0;
  }
}
</style>

<style>
/* コメントのポップオーバー（body 直下に出すためグローバル） */
.note-pop {
  position: fixed;
  z-index: 1200;
  width: min(320px, calc(100vw - 16px));
  padding: 10px 12px;
  border-radius: 12px;
  background: #fff;
  border: 1px solid #e3e6ea;
  box-shadow: 0 12px 32px rgba(15, 20, 30, 0.18);
  font-size: 12.5px;
  line-height: 1.6;
}
.note-pop .np-title {
  font-size: 11px;
  font-weight: 700;
  color: var(--faint);
  margin-bottom: 6px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.note-pop .np-row {
  display: flex;
  align-items: flex-start;
  gap: 8px;
  padding: 3px 0;
}
.note-pop .np-lab {
  flex-shrink: 0;
  padding: 1px 7px;
  border-radius: 999px;
  font-size: 10.5px;
  font-weight: 700;
  line-height: 1.6;
}
.note-pop .np-lab.tutor {
  background: #e8eefb;
  color: #2e4a8f;
}
.note-pop .np-lab.student {
  background: #e6f5ec;
  color: #2f7a4f;
}
.note-pop .np-text {
  white-space: pre-wrap;
  word-break: break-word;
  color: var(--ink);
}
.np-enter-active,
.np-leave-active {
  transition: opacity 0.14s ease, transform 0.14s ease;
}
.np-enter-from,
.np-leave-to {
  opacity: 0;
  transform: translateY(-4px);
}
</style>
