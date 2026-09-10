<script setup lang="ts">
import { onMounted, ref } from 'vue'
import AuthImage from '@/components/AuthImage.vue'
import { MARK_COLOR, MARK_LABEL, quizApi } from '@/api/quiz'
import { useUiStore } from '@/stores/ui'
import type { QuizDetail } from '@/types'

/** 添削結果の閲覧（生徒・講師共用）。注釈を合成した回答画像・判定・点数・コメントを表示する */
const props = defineProps<{ quizId: number }>()
const emit = defineEmits<{ close: [] }>()

const ui = useUiStore()
const quiz = ref<QuizDetail | null>(null)
const loading = ref(true)
const downloading = ref(false)

onMounted(async () => {
  try {
    quiz.value = await quizApi.show(props.quizId)
  } catch {
    ui.notify('結果の取得に失敗しました')
  } finally {
    loading.value = false
  }
})

async function download(kind: 'result' | 'quiz') {
  if (!quiz.value) return
  downloading.value = true
  try {
    // 問題 PDF は別タブでプレビュー、添削済み PDF は従来どおりダウンロード
    if (kind === 'result') await quizApi.downloadResultPdf(quiz.value.id, quiz.value.title)
    else await quizApi.previewQuizPdf(quiz.value.id)
  } catch {
    ui.notify(kind === 'result' ? 'ダウンロードに失敗しました' : 'PDF の表示に失敗しました')
  } finally {
    downloading.value = false
  }
}
</script>

<template>
  <div class="overlay" @click="emit('close')">
    <div class="modal" @click.stop>
      <div class="head">
        <div style="min-width: 0">
          <div style="font-size: 15px; font-weight: 700">{{ quiz?.title ?? '添削結果' }}</div>
          <div v-if="quiz" style="font-size: 11.5px; color: var(--mut); margin-top: 2px">
            {{ quiz.bookTitle }}・{{ quiz.pageCount }}ページ<template v-if="quiz.gradedAt">・添削 {{ quiz.gradedAt.slice(0, 10).replace(/-/g, '/') }}</template>
          </div>
        </div>
        <button class="x" @click="emit('close')">×</button>
      </div>

      <div v-if="loading" style="padding: 20px; text-align: center; font-size: 12.5px; color: var(--faint)">読み込み中…</div>
      <template v-else-if="quiz">
        <div class="total">
          <div class="big"><b>{{ quiz.score ?? '–' }}</b><span> / {{ quiz.maxScore }}点</span></div>
          <div class="bar"><span :style="{ width: (quiz.rate ?? 0) + '%' }"></span></div>
          <div class="rate">{{ quiz.rate !== null ? quiz.rate + '%' : '' }}</div>
          <div class="marks">
            <span v-for="m in (['o', 'tri', 'x'] as const)" :key="m" :style="{ color: MARK_COLOR[m] }">
              {{ MARK_LABEL[m] }} {{ quiz.pages.filter((p) => p.mark === m).length }}
            </span>
          </div>
        </div>

        <div class="pages">
          <div v-for="p in quiz.pages" :key="p.id" class="page">
            <div class="img">
              <AuthImage v-if="p.hasAnnotated" :src="quizApi.annotatedImageUrl(quiz.id, p.id, p.annotatedVersion)" />
              <AuthImage v-else-if="p.hasAnswer" :src="quizApi.answerImageUrl(quiz.id, p.id, p.answerVersion)" />
              <div v-else class="noimg">未提出</div>
            </div>
            <div class="info">
              <div style="display: flex; align-items: center; gap: 8px">
                <span class="mark" :style="{ color: p.mark ? MARK_COLOR[p.mark] : '#9aa1ab', borderColor: p.mark ? MARK_COLOR[p.mark] : '#d8dce1' }">{{ p.mark ? MARK_LABEL[p.mark] : '–' }}</span>
                <div style="min-width: 0">
                  <div style="font-size: 13px; font-weight: 700">{{ p.pageNo }}. {{ p.label }}</div>
                  <div style="font-size: 11px; color: var(--faint)">
                    <template v-if="p.kind === 'vocab'">英単語テスト・{{ p.vocabSpec?.resourceName }}</template>
                    <template v-else>{{ p.chapter }}<span v-if="p.difficulty" style="color: #d98a1a; margin-left: 6px">{{ p.difficulty }}</span></template>
                  </div>
                </div>
                <span class="pt"><b>{{ p.score ?? '–' }}</b> / {{ p.maxScore }}</span>
              </div>
              <div v-if="p.comment" class="comment">{{ p.comment }}</div>
            </div>
          </div>
        </div>

        <div class="foot">
          <button class="btn" :disabled="downloading" @click="download('quiz')">問題PDF</button>
          <button class="btn primary" :disabled="downloading" @click="download('result')">添削済みPDFをダウンロード</button>
        </div>
      </template>
    </div>
  </div>
</template>

<style scoped>
.overlay {
  position: fixed;
  inset: 0;
  background: rgba(20, 24, 32, 0.45);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 60;
  padding: 16px;
}
.modal {
  background: #fff;
  border-radius: 16px;
  padding: 20px 22px;
  width: 100%;
  max-width: 760px;
  max-height: 92vh;
  overflow-y: auto;
  box-shadow: 0 20px 50px rgba(0, 0, 0, 0.2);
}
.head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 10px;
  margin-bottom: 12px;
}
.x {
  border: none;
  background: transparent;
  font-size: 22px;
  color: #9aa1ab;
  cursor: pointer;
  line-height: 1;
}
.total {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px 14px;
  background: #f6f7f9;
  border-radius: 12px;
  margin-bottom: 12px;
  flex-wrap: wrap;
}
.big b {
  font-size: 24px;
}
.big span {
  font-size: 12px;
  color: var(--mut);
}
.bar {
  flex: 1;
  min-width: 120px;
  height: 8px;
  border-radius: 99px;
  background: #e2e6f0;
  overflow: hidden;
}
.bar span {
  display: block;
  height: 100%;
  background: #3b50cc;
}
.rate {
  font-size: 13px;
  font-weight: 700;
  width: 44px;
  text-align: right;
}
.marks {
  display: flex;
  gap: 10px;
  font-size: 12px;
  font-weight: 700;
}
.pages {
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.page {
  display: grid;
  grid-template-columns: 220px 1fr;
  gap: 12px;
  border: 1px solid var(--line);
  border-radius: 12px;
  padding: 10px;
}
@media (max-width: 640px) {
  .page {
    grid-template-columns: 1fr;
  }
}
.img {
  background: #f1f2f4;
  border-radius: 8px;
  overflow: hidden;
  min-height: 120px;
  display: flex;
  align-items: center;
  justify-content: center;
}
.img :deep(img) {
  width: 100%;
  display: block;
}
.noimg {
  font-size: 12px;
  color: var(--faint);
}
.info {
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.mark {
  width: 34px;
  height: 34px;
  border-radius: 50%;
  border: 2px solid;
  font-size: 18px;
  font-weight: 700;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.pt {
  margin-left: auto;
  font-size: 12px;
  color: var(--mut);
  white-space: nowrap;
}
.pt b {
  font-size: 16px;
  color: var(--ink);
}
.comment {
  font-size: 12.5px;
  background: #fff9e8;
  border: 1px solid #f5e3b0;
  border-radius: 8px;
  padding: 8px 10px;
  white-space: pre-wrap;
}
.foot {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  margin-top: 14px;
}
.btn {
  padding: 8px 14px;
  border: 1px solid #e3e6ea;
  border-radius: 9px;
  background: #fff;
  font-size: 12.5px;
  font-weight: 600;
  color: var(--mut);
  cursor: pointer;
}
.btn.primary {
  background: #1c2024;
  border-color: #1c2024;
  color: #fff;
}
.btn:disabled {
  opacity: 0.5;
}
</style>
