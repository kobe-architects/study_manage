<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import CameraCapture from '@/components/CameraCapture.vue'
import HelpTip from '@/components/HelpTip.vue'
import QuizTable from '@/components/QuizTable.vue'
import QuizResultModal from '@/components/QuizResultModal.vue'
import QuizStats from '@/components/QuizStats.vue'
import { quizApi } from '@/api/quiz'
import { useQuizActions } from '@/lib/quizActions'
import { useUiStore } from '@/stores/ui'
import type { QuizStats as QuizStatsT, QuizSummary } from '@/types'

/** 生徒用: 小テスト一覧（ダウンロード・撮影して提出・結果閲覧）と分析 */
const ui = useUiStore()
const tab = ref<'list' | 'stats'>('list')
const quizzes = ref<QuizSummary[]>([])
const loading = ref(true)
const stats = ref<QuizStatsT | null>(null)

async function load() {
  try {
    quizzes.value = await quizApi.list()
  } catch {
    ui.notify('小テストの取得に失敗しました')
  } finally {
    loading.value = false
  }
}
async function loadStats() {
  try {
    stats.value = await quizApi.stats()
  } catch {
    ui.notify('分析データの取得に失敗しました')
  }
}
onMounted(load)
watch(tab, (t) => {
  if (t === 'stats') loadStats()
})

const { state, openPdf, openCapture, openResult, onSubmitted } = useQuizActions(async () => {
  await load()
  if (tab.value === 'stats') await loadStats()
})

</script>

<template>
  <div>
    <div style="display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; margin-bottom: 14px">
      <div style="display: flex; align-items: center; gap: 8px">
        <div style="font-size: 17px; font-weight: 700">小テスト</div>
        <HelpTip
          text="先生が出題した小テストに回答して提出します。複数の教材から出題されている場合、問題PDFや提出は教材ごとに行います。&#10;流れ: 問題PDFを開いて印刷 → 用紙に回答 → 「撮影して提出」でページごとに撮影 → 先生が採点・添削 → 結果と分析を確認"
        />
      </div>
      <div class="seg">
        <button :class="{ on: tab === 'list' }" @click="tab = 'list'">一覧</button>
        <button :class="{ on: tab === 'stats' }" @click="tab = 'stats'">分析</button>
      </div>
    </div>

    <template v-if="tab === 'list'">
      <div v-if="loading" class="hint">読み込み中…</div>
      <div v-else-if="!quizzes.length" class="hint">
        小テストはまだありません。先生が出題すると、ここに問題 PDF のプレビューと回答の提出ボタンが表示されます。
      </div>
      <QuizTable v-else :quizzes="quizzes" role="owner" @pdf="openPdf($event)" @capture="openCapture($event)" @result="openResult($event)" />
    </template>

    <template v-else>
      <QuizStats v-if="stats" :stats="stats" />
      <div v-else class="hint">読み込み中…</div>
    </template>

    <CameraCapture v-if="state.capture" :quiz="state.capture" @close="state.capture = null" @submitted="onSubmitted" />
    <QuizResultModal v-if="state.resultId !== null" :quiz-id="state.resultId" @close="state.resultId = null" />
  </div>
</template>

<style scoped>
.seg {
  display: inline-flex;
  border: 1px solid #e3e6ea;
  border-radius: 9px;
  overflow: hidden;
  background: #fff;
}
.seg button {
  padding: 7px 14px;
  border: none;
  background: #fff;
  font-size: 12.5px;
  font-weight: 600;
  color: var(--mut);
  cursor: pointer;
}
.seg button.on {
  background: #1c2024;
  color: #fff;
}
.group {
  margin-bottom: 18px;
}
.group-title {
  font-size: 13px;
  font-weight: 700;
  margin: 0 2px 8px;
  display: flex;
  align-items: center;
  gap: 6px;
}
.cnt {
  font-size: 10.5px;
  background: #f1f2f4;
  color: var(--mut);
  padding: 1px 7px;
  border-radius: 999px;
}
.cards {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
  gap: 12px;
}
.hint {
  background: #f8f9fb;
  border: 1px dashed #d8dce1;
  border-radius: 14px;
  padding: 15px 18px;
  margin-top: 6px;
  font-size: 12.5px;
  color: var(--faint);
  line-height: 1.7;
}
</style>
