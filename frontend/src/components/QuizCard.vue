<script setup lang="ts">
import { computed } from 'vue'
import { MARK_COLOR, MARK_LABEL, QUIZ_STATUS_LABEL, groupStatus } from '@/api/quiz'
import type { QuizSummary } from '@/types'

/**
 * 小テストカード（生徒・講師共用。role によって操作ボタンが変わる）。
 * 1枚のカード＝1回の出題（箱）。箱には教材ごとのパートが複数入ることがあり、
 * 問題 PDF・提出・採点はパート（教材）ごとに行う。
 */
const props = defineProps<{ parts: QuizSummary[]; role: 'owner' | 'tutor'; compact?: boolean }>()
const emit = defineEmits<{
  pdf: [q: QuizSummary]
  capture: [q: QuizSummary]
  result: [q: QuizSummary]
  grade: [q: QuizSummary]
  edit: [q: QuizSummary]
  remove: [q: QuizSummary]
}>()

const head = computed(() => props.parts[0]!)
const multi = computed(() => props.parts.length > 1)

const status = computed(() => groupStatus(props.parts, props.role))
const overdue = computed(() => props.parts.some((q) => q.overdue))
const chip = computed(() => {
  if (status.value === 'graded') return { label: '採点・添削済み', cls: 'graded' }
  if (status.value === 'submitted') return { label: props.role === 'tutor' ? '採点・添削待ち' : '提出済み', cls: 'submitted' }
  if (overdue.value) return { label: '期限切れ', cls: 'overdue' }
  return { label: QUIZ_STATUS_LABEL[status.value] ?? status.value, cls: 'assigned' }
})

function partChip(q: QuizSummary): { label: string; cls: string } {
  if (q.status === 'graded') return { label: '採点・添削済み', cls: 'graded' }
  if (q.status === 'submitted') return { label: props.role === 'tutor' ? '採点・添削待ち' : '提出済み', cls: 'submitted' }
  if (q.overdue) return { label: '期限切れ', cls: 'overdue' }
  return { label: '未提出', cls: 'assigned' }
}
function partLabel(q: QuizSummary): string {
  return q.bookTitle ?? '英単語テスト'
}

const totalMarks = computed(() => {
  const t = { o: 0, tri: 0, x: 0 }
  for (const q of props.parts) {
    t.o += q.marks?.o ?? 0
    t.tri += q.marks?.tri ?? 0
    t.x += q.marks?.x ?? 0
  }
  return t
})
const allGraded = computed(() => props.parts.every((q) => q.status === 'graded'))

function fmt(d: string | null): string {
  if (!d) return ''
  const [y, m, dd] = d.split(/[- :]/)
  return `${y}/${Number(m)}/${Number(dd)}`
}
</script>

<template>
  <div class="qcard" :class="{ compact }">
    <div class="q-head">
      <div style="min-width: 0; flex: 1">
        <div class="q-title">{{ head.title }}</div>
        <div class="q-meta">
          <span v-if="multi">{{ parts.length }}教材</span>
          <span>{{ parts.reduce((s, q) => s + q.pageCount, 0) }}ページ</span>
          <span v-if="head.dueOn" :style="{ color: overdue ? '#c0444f' : undefined }">期限 {{ fmt(head.dueOn) }}</span>
          <span v-if="head.createdByName && role === 'owner'">{{ head.createdByName }}</span>
        </div>
      </div>
      <span class="chip" :class="chip.cls">{{ chip.label }}</span>
    </div>

    <div v-if="head.note && !compact" class="q-note">{{ head.note }}</div>

    <!-- パート（教材）ごとの行: 問題PDF・提出・採点はここから -->
    <div v-for="q in parts" :key="q.id" class="part">
      <div class="part-info">
        <div class="part-name">
          {{ partLabel(q) }}
          <span v-if="multi" class="pchip" :class="partChip(q).cls">{{ partChip(q).label }}</span>
        </div>
        <div class="part-meta">
          <span>{{ q.pageCount }}ページ</span>
          <span v-if="q.status === 'submitted' && q.submittedAt">提出 {{ fmt(q.submittedAt) }}</span>
          <span v-if="q.status === 'graded' && q.gradedAt">採点・添削 {{ fmt(q.gradedAt) }}</span>
          <span v-if="q.status === 'assigned' && q.answeredCount" style="color: #2f7a4f">{{ q.answeredCount }}/{{ q.pageCount }} 撮影済み（未提出）</span>
        </div>
        <div v-if="q.status === 'graded'" class="marks-row">
          <span v-for="m in (['o', 'tri', 'x'] as const)" :key="m" class="mk" :style="{ color: MARK_COLOR[m] }">
            <b>{{ MARK_LABEL[m] }}</b>{{ q.marks?.[m] ?? 0 }}
          </span>
        </div>
      </div>
      <div class="actions">
        <template v-if="role === 'owner'">
          <button v-if="q.status === 'graded'" class="btn primary" @click="emit('result', q)">結果を見る</button>
          <button v-if="q.status !== 'graded'" class="btn primary" @click="emit('capture', q)">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h3l2-2h6l2 2h3v12H4z" /><circle cx="12" cy="13" r="3.5" /></svg>
            {{ q.status === 'submitted' ? '撮り直して再提出' : '撮影して提出' }}
          </button>
          <button class="btn" title="問題 PDF を別タブでプレビュー" @click="emit('pdf', q)">問題PDF</button>
        </template>
        <template v-else>
          <button v-if="q.status === 'submitted'" class="btn primary" @click="emit('grade', q)">採点・添削する</button>
          <button v-else-if="q.status === 'graded'" class="btn primary" @click="emit('grade', q)">採点・添削結果</button>
          <button v-else class="btn" @click="emit('edit', q)">編集</button>
          <button class="btn" title="問題 PDF を別タブでプレビュー" @click="emit('pdf', q)">問題PDF</button>
          <button class="btn danger" @click="emit('remove', q)">削除</button>
        </template>
      </div>
    </div>

    <div v-if="multi && allGraded" class="total-row">
      合計
      <span v-for="m in (['o', 'tri', 'x'] as const)" :key="m" class="mk" :style="{ color: MARK_COLOR[m] }"><b>{{ MARK_LABEL[m] }}</b>{{ totalMarks[m] }}</span>
    </div>
  </div>
</template>

<style scoped>
.qcard {
  background: #fff;
  border: 1px solid var(--line);
  border-radius: 14px;
  padding: 14px 16px;
  display: flex;
  flex-direction: column;
  gap: 9px;
}
.qcard.compact {
  padding: 12px 14px;
  gap: 7px;
}
.q-head {
  display: flex;
  align-items: flex-start;
  gap: 10px;
}
.q-title {
  font-size: 13.5px;
  font-weight: 700;
  line-height: 1.4;
}
.q-meta {
  display: flex;
  flex-wrap: wrap;
  gap: 4px 10px;
  font-size: 11px;
  color: var(--faint);
  margin-top: 3px;
}
.chip {
  flex-shrink: 0;
  font-size: 10.5px;
  font-weight: 700;
  padding: 3px 9px;
  border-radius: 999px;
}
.chip.assigned,
.pchip.assigned {
  background: #f1f2f4;
  color: var(--mut);
}
.chip.overdue,
.pchip.overdue {
  background: #fdf0f1;
  color: #c0444f;
}
.chip.submitted,
.pchip.submitted {
  background: #e8eefb;
  color: #2e4a8f;
}
.chip.graded,
.pchip.graded {
  background: #e6f5ec;
  color: #2f7a4f;
}
.pchip {
  font-size: 10px;
  font-weight: 700;
  padding: 2px 7px;
  border-radius: 999px;
  margin-left: 6px;
}
.q-note {
  font-size: 12px;
  color: var(--mut);
  white-space: pre-wrap;
}
.part {
  border-top: 1px solid #f1f2f4;
  padding-top: 9px;
  display: flex;
  flex-direction: column;
  gap: 7px;
}
.part-name {
  font-size: 12.5px;
  font-weight: 600;
  display: flex;
  align-items: center;
  flex-wrap: wrap;
}
.part-meta {
  display: flex;
  flex-wrap: wrap;
  gap: 3px 10px;
  font-size: 11px;
  color: var(--faint);
  margin-top: 2px;
}
.marks-row {
  display: flex;
  align-items: center;
  gap: 14px;
  margin-top: 5px;
}
.mk {
  font-size: 13px;
}
.mk b {
  font-size: 16px;
  margin-right: 3px;
}
.total-row {
  border-top: 1px solid #f1f2f4;
  padding-top: 8px;
  font-size: 12px;
  color: var(--mut);
  text-align: right;
  display: flex;
  justify-content: flex-end;
  align-items: baseline;
  gap: 12px;
}
.actions {
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
}
.btn {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 7px 12px;
  border: 1px solid #e3e6ea;
  border-radius: 9px;
  background: #fff;
  font-size: 12px;
  font-weight: 600;
  color: var(--mut);
  cursor: pointer;
}
.btn.primary {
  background: #1c2024;
  border-color: #1c2024;
  color: #fff;
}
.btn.danger {
  color: #c0444f;
  border-color: #f0b8be;
  margin-left: auto;
}
</style>
