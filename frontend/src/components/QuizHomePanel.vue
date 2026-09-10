<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import CameraCapture from '@/components/CameraCapture.vue'
import QuizCard from '@/components/QuizCard.vue'
import QuizResultModal from '@/components/QuizResultModal.vue'
import { quizApi } from '@/api/quiz'
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
  const pending = quizzes.value.filter((q) => q.status !== 'graded')
  const cutoff = new Date()
  cutoff.setDate(cutoff.getDate() - 14)
  const recent = quizzes.value
    .filter((q) => q.status === 'graded' && q.gradedAt && new Date(q.gradedAt.replace(' ', 'T')) >= cutoff)
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
      v-for="q in shown"
      :key="q.id"
      :quiz="q"
      role="owner"
      compact
      @pdf="openPdf(q)"
      @capture="openCapture(q)"
      @result="openResult(q)"
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
