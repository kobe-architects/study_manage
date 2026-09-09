<script setup lang="ts">
import { computed } from 'vue'
import { QUIZ_STATUS_LABEL } from '@/api/quiz'
import type { QuizSummary } from '@/types'

/** 小テストカード（生徒・講師共用。role によって操作ボタンが変わる） */
const props = defineProps<{ quiz: QuizSummary; role: 'owner' | 'tutor'; compact?: boolean }>()
const emit = defineEmits<{ download: []; capture: []; result: []; grade: []; edit: []; remove: [] }>()

const status = computed(() => props.quiz.status)
const chip = computed(() => {
  if (status.value === 'graded') return { label: '添削済み', cls: 'graded' }
  if (status.value === 'submitted') return { label: props.role === 'tutor' ? '添削待ち' : '提出済み', cls: 'submitted' }
  if (props.quiz.overdue) return { label: '期限切れ', cls: 'overdue' }
  return { label: QUIZ_STATUS_LABEL[status.value] ?? status.value, cls: 'assigned' }
})

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
        <div class="q-title">{{ quiz.title }}</div>
        <div class="q-meta">
          <span v-if="quiz.bookTitle">{{ quiz.bookTitle }}</span>
          <span>{{ quiz.pageCount }}ページ</span>
          <span v-if="quiz.dueOn" :style="{ color: quiz.overdue ? '#c0444f' : undefined }">期限 {{ fmt(quiz.dueOn) }}</span>
          <span v-if="quiz.createdByName && role === 'owner'">{{ quiz.createdByName }}</span>
          <span v-if="status === 'submitted' && quiz.submittedAt">提出 {{ fmt(quiz.submittedAt) }}</span>
          <span v-if="status === 'graded' && quiz.gradedAt">添削 {{ fmt(quiz.gradedAt) }}</span>
        </div>
      </div>
      <span class="chip" :class="chip.cls">{{ chip.label }}</span>
    </div>

    <div v-if="quiz.note && !compact" class="q-note">{{ quiz.note }}</div>

    <div v-if="status === 'graded' && quiz.score !== null" class="score-row">
      <div class="bar"><span :style="{ width: (quiz.rate ?? 0) + '%' }"></span></div>
      <span class="score"><b>{{ quiz.score }}</b> / {{ quiz.maxScore }}点<span class="rate">（{{ quiz.rate }}%）</span></span>
    </div>
    <div v-else-if="status === 'assigned' && quiz.answeredCount" class="q-note" style="color: #2f7a4f">{{ quiz.answeredCount }} / {{ quiz.pageCount }} ページ撮影済み（未提出）</div>

    <div class="actions">
      <template v-if="role === 'owner'">
        <button v-if="status === 'graded'" class="btn primary" @click="emit('result')">結果を見る</button>
        <button v-if="status !== 'graded'" class="btn primary" @click="emit('capture')">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h3l2-2h6l2 2h3v12H4z" /><circle cx="12" cy="13" r="3.5" /></svg>
          {{ status === 'submitted' ? '撮り直して再提出' : '撮影して提出' }}
        </button>
        <button class="btn" @click="emit('download')">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 4v11" /><path d="M7 10l5 5 5-5" /><path d="M4 19h16" /></svg>
          問題PDF
        </button>
      </template>
      <template v-else>
        <button v-if="status === 'submitted'" class="btn primary" @click="emit('grade')">添削する</button>
        <button v-else-if="status === 'graded'" class="btn primary" @click="emit('grade')">添削結果を見る</button>
        <button v-else class="btn" @click="emit('edit')">編集</button>
        <button class="btn" @click="emit('download')">問題PDF</button>
        <button class="btn danger" @click="emit('remove')">削除</button>
      </template>
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
.chip.assigned {
  background: #f1f2f4;
  color: var(--mut);
}
.chip.overdue {
  background: #fdf0f1;
  color: #c0444f;
}
.chip.submitted {
  background: #e8eefb;
  color: #2e4a8f;
}
.chip.graded {
  background: #e6f5ec;
  color: #2f7a4f;
}
.q-note {
  font-size: 12px;
  color: var(--mut);
  white-space: pre-wrap;
}
.score-row {
  display: flex;
  align-items: center;
  gap: 10px;
}
.bar {
  flex: 1;
  height: 8px;
  border-radius: 99px;
  background: #e8ebf5;
  overflow: hidden;
}
.bar span {
  display: block;
  height: 100%;
  background: #3b50cc;
  border-radius: 99px;
}
.score {
  font-size: 12px;
  color: var(--mut);
  white-space: nowrap;
}
.score b {
  font-size: 15px;
  color: var(--ink);
}
.rate {
  color: var(--faint);
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
