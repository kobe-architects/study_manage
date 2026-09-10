<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import CameraCapture from '@/components/CameraCapture.vue'
import QuizCard from '@/components/QuizCard.vue'
import QuizResultModal from '@/components/QuizResultModal.vue'
import { groupQuizzes, groupStatus, quizApi } from '@/api/quiz'
import { useQuizActions } from '@/lib/quizActions'
import type { QuizSummary } from '@/types'

/** 生徒トップページの「小テスト」パネル。未提出・添削待ちと直近の添削結果を表示する */
const router = useRouter()
const quizzes = ref<QuizSummary[]>([])

async function load() {
  try {
    quizzes.value = await quizApi.list()
  } catch {
    quizzes.value = []
  }
}
onMounted(load)

const { state, openPdf, openCapture, openResult, onSubmitted } = useQuizActions(load)

const shown = computed(() => {
  const boxes = groupQuizzes(quizzes.value)
  const pending = boxes.filter((b) => groupStatus(b, 'owner') !== 'graded')
  const cutoff = new Date()
  cutoff.setDate(cutoff.getDate() - 14)
  const recent = boxes
    .filter((b) => groupStatus(b, 'owner') === 'graded' && b.some((q) => q.gradedAt && new Date(q.gradedAt.replace(' ', 'T')) >= cutoff))
    .slice(0, 3)
  return [...pending, ...recent]
})
</script>

<template>
  <template v-if="shown.length">
    <div style="display: flex; align-items: baseline; justify-content: space-between; margin: 2px 2px -6px">
      <span style="font-size: 13px; font-weight: 700">小テスト</span>
      <button class="more" @click="router.push({ name: 'quizzes' })">すべて見る・分析 ›</button>
    </div>
    <QuizCard
      v-for="b in shown"
      :key="b[0]!.id"
      :parts="b"
      role="owner"
      compact
      @pdf="openPdf($event)"
      @capture="openCapture($event)"
      @result="openResult($event)"
    />
    <CameraCapture v-if="state.capture" :quiz="state.capture" @close="state.capture = null" @submitted="onSubmitted" />
    <QuizResultModal v-if="state.resultId !== null" :quiz-id="state.resultId" @close="state.resultId = null" />
  </template>
</template>

<style scoped>
.more {
  border: none;
  background: none;
  color: #3b50cc;
  font-size: 11.5px;
  font-weight: 600;
  cursor: pointer;
}
</style>
