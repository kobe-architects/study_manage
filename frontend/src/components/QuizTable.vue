<script setup lang="ts">
import { computed, reactive } from 'vue'
import { rateColor } from '@/api/quiz'
import type { QuizSummary } from '@/types'

/**
 * 小テスト一覧のテーブル表示（生徒・講師共用）。
 * 1行＝1パート（教材ごと）。ステータスはバッジで色分けし、ステータス・教材・出題日・キーワードで絞り込める。
 * 同じ出題（箱）に複数の教材がある場合はタイトルの横に「1/2」のように表示する。
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

const filter = reactive<{ status: StatusKey; book: string; period: PeriodKey; q: string; sort: SortKey }>({
  status: 'all',
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
const STATUS_ORDER: Exclude<StatusKey, 'all'>[] = ['submitted', 'assigned', 'overdue', 'graded']

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
    if (filter.status !== 'all' && statusOf(q) !== filter.status) return false
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
const filtered = computed(() => filter.status !== 'all' || !!filter.book || filter.period !== 'all' || !!filter.q.trim())
function clearFilters() {
  filter.status = 'all'
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
    <!-- 絞り込み: ステータス（件数つきバッジ）・教材・出題日・キーワード・並び順 -->
    <div class="filters">
      <div class="status-tabs">
        <button class="st all" :class="{ on: filter.status === 'all' }" @click="filter.status = 'all'">すべて<span class="n">{{ quizzes.length }}</span></button>
        <button v-for="k in STATUS_ORDER" :key="k" class="st" :class="[k, { on: filter.status === k }]" @click="filter.status = filter.status === k ? 'all' : k">
          {{ STATUS_LABEL[k] }}<span class="n">{{ counts[k] }}</span>
        </button>
      </div>
      <div class="filter-row">
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
        <button v-if="filtered" class="clear" @click="clearFilters">絞り込みを解除</button>
        <span class="cnt">{{ rows.length }} / {{ quizzes.length }}件</span>
      </div>
    </div>

    <div class="tbl-wrap">
      <table class="tbl">
        <thead>
          <tr>
            <th class="c-date">出題日</th>
            <th class="c-title">タイトル</th>
            <th class="c-book">教材</th>
            <th class="c-pages r">ページ</th>
            <th class="c-due">期限</th>
            <th class="c-status">ステータス</th>
            <th class="c-score">得点</th>
            <th class="c-actions"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="q in rows" :key="q.id" :class="['row', statusOf(q)]">
            <td class="c-date nowrap">{{ fmt(q.createdOn) }}</td>
            <td class="c-title">
              <div class="ttl">
                {{ q.title }}
                <span v-if="partIndex[q.id] && partIndex[q.id]!.n > 1" class="part-no">{{ partIndex[q.id]!.i }}/{{ partIndex[q.id]!.n }}</span>
              </div>
              <div v-if="q.note" class="note">{{ q.note }}</div>
              <div v-if="q.submitNote" class="note snote">一言: {{ q.submitNote }}</div>
              <div v-if="role === 'owner' && q.createdByName" class="note">{{ q.createdByName }}</div>
            </td>
            <td class="c-book">{{ partLabel(q) }}</td>
            <td class="c-pages r">{{ q.pageCount }}</td>
            <td class="c-due nowrap" :class="dueClass(q)">{{ fmt(q.dueOn) }}</td>
            <td class="c-status">
              <span class="badge" :class="statusOf(q)">{{ STATUS_LABEL[statusOf(q)] }}</span>
              <span v-if="q.status === 'graded' && q.selfGraded" class="badge self">自己採点</span>
              <span v-if="q.status === 'assigned' && q.answeredCount" class="badge sub">{{ q.answeredCount }}/{{ q.pageCount }} 撮影済み</span>
              <div v-if="q.status === 'submitted' && q.submittedAt" class="sub-date">提出 {{ fmt(q.submittedAt) }}</div>
              <div v-else-if="q.status === 'graded' && q.gradedAt" class="sub-date">採点 {{ fmt(q.gradedAt) }}</div>
            </td>
            <td class="c-score">
              <template v-if="q.status === 'graded' && q.score !== null">
                <div class="score-line"><b :style="{ color: rateColor(q.rate) }">{{ q.score }}</b><span class="max"> / {{ q.maxScore }}点</span></div>
                <div class="rate-line">
                  <span class="rate-bar"><span :style="{ width: (q.rate ?? 0) + '%', background: rateColor(q.rate) }"></span></span>
                  <span class="rate" :style="{ color: rateColor(q.rate) }">{{ q.rate ?? '–' }}%</span>
                </div>
              </template>
              <span v-else class="dash">–</span>
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
                  <button v-else-if="q.status === 'graded'" class="btn primary" @click="emit('grade', q)">結果</button>
                  <button v-else class="btn" @click="emit('edit', q)">編集</button>
                  <button class="btn" title="問題 PDF を別タブでプレビュー" @click="emit('pdf', q)">問題PDF</button>
                  <button class="btn danger" @click="emit('remove', q)">削除</button>
                </template>
              </div>
            </td>
          </tr>
          <tr v-if="!rows.length">
            <td colspan="8" class="empty">条件に一致する小テストはありません</td>
          </tr>
        </tbody>
      </table>
    </div>
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
.tbl {
  width: 100%;
  min-width: 760px;
  border-collapse: collapse;
  font-size: 12.5px;
}
.tbl th {
  padding: 10px 12px;
  text-align: left;
  font-size: 11px;
  font-weight: 700;
  color: var(--mut);
  background: #f8f9fb;
  border-bottom: 1px solid var(--line);
  white-space: nowrap;
}
.tbl td {
  padding: 10px 12px;
  border-bottom: 1px solid #f1f2f4;
  vertical-align: middle;
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
.row.submitted {
  background: #f6f8fe;
}
.row.overdue {
  background: #fff7f7;
}
.row:hover {
  background: #f8f9fb;
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
  width: 150px;
}
.c-score {
  width: 150px;
}
.c-book {
  color: var(--mut);
  white-space: nowrap;
}
.c-title {
  min-width: 200px;
}
.ttl {
  font-weight: 700;
  color: var(--ink);
}
.part-no {
  display: inline-block;
  margin-left: 6px;
  font-size: 10.5px;
  font-weight: 700;
  color: #5b6b8c;
  background: #eef1f6;
  padding: 1px 7px;
  border-radius: 999px;
  vertical-align: middle;
}
.note {
  font-size: 11px;
  color: var(--faint);
  margin-top: 2px;
  white-space: pre-wrap;
}
.note.snote {
  color: #5b3fa0;
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
  margin-top: 3px;
}
.due-soon {
  color: #d98a1a;
  font-weight: 700;
}
.due-over {
  color: #c0444f;
  font-weight: 700;
}
.score-line b {
  font-size: 15px;
}
.score-line .max {
  font-size: 11.5px;
  color: var(--mut);
}
.rate-line {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-top: 3px;
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
  gap: 6px;
  justify-content: flex-end;
  flex-wrap: wrap;
}
.btn {
  padding: 6px 10px;
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
}
.btn.danger {
  color: #c0444f;
}
.empty {
  text-align: center;
  color: var(--faint);
  padding: 28px 12px !important;
}
@media (max-width: 640px) {
  /* スマホは横スクロールで表示（列を詰めて折り返すより読みやすい） */
  .tbl {
    min-width: 900px;
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
