<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AnnotationEditor from '@/components/AnnotationEditor.vue'
import PdfThumb from '@/components/PdfThumb.vue'
import { MARK_COLOR, MARK_LABEL, fetchBlobUrl, quizApi, scoreForMark } from '@/api/quiz'
import { useUiStore } from '@/stores/ui'
import type { AnnotationDoc, QuizDetail, QuizMark, QuizPageDetail } from '@/types'

/**
 * 講師用: 添削・採点画面。
 * 左: 回答写真の添削エディタ（PC: マウス、iPad: Apple Pencil / 指）
 * 右: 問題ページ（出題元 PDF）・解答参照ページ、○△×採点・点数・コメント
 */
const route = useRoute()
const router = useRouter()
const ui = useUiStore()

const quizId = Number(route.params.id)
const quiz = ref<QuizDetail | null>(null)
const pageIdx = ref(0)
const page = computed<QuizPageDetail | null>(() => quiz.value?.pages[pageIdx.value] ?? null)
const editor = ref<InstanceType<typeof AnnotationEditor> | null>(null)
const saving = ref(false)
const dirty = ref(false)
const answerUrl = ref<string | null>(null)
const refMode = ref<'question' | 'answer'>('question')

const form = reactive<{ mark: QuizMark | null; score: number | null; comment: string; saving: boolean }>({ mark: null, score: null, comment: '', saving: false })

async function load(keepIdx = true) {
  const q = await quizApi.show(quizId)
  quiz.value = q
  if (!keepIdx || pageIdx.value >= q.pages.length) pageIdx.value = 0
}

async function loadAnswer() {
  if (answerUrl.value) URL.revokeObjectURL(answerUrl.value)
  answerUrl.value = null
  const p = page.value
  if (!p || !p.hasAnswer || !quiz.value) return
  try {
    answerUrl.value = await fetchBlobUrl(quizApi.answerImageUrl(quiz.value.id, p.id, p.answerVersion))
  } catch {
    ui.notify('回答画像の取得に失敗しました')
  }
}

function syncForm() {
  const p = page.value
  form.mark = p?.mark ?? null
  form.score = p?.score ?? null
  form.comment = p?.comment ?? ''
}

onMounted(async () => {
  try {
    await load(false)
    await loadAnswer()
    syncForm()
  } catch {
    ui.notify('小テストの取得に失敗しました')
    router.push({ name: 'tutor-quizzes' })
  }
})
onBeforeUnmount(() => {
  if (answerUrl.value) URL.revokeObjectURL(answerUrl.value)
})

watch(pageIdx, async () => {
  refMode.value = 'question'
  await loadAnswer()
  syncForm()
})

async function gotoPage(i: number) {
  if (i === pageIdx.value || !quiz.value || i < 0 || i >= quiz.value.pages.length) return
  if (dirty.value && !confirm('未保存の添削があります。保存せずにページを移動しますか？')) return
  dirty.value = false
  pageIdx.value = i
}

// ---- 添削の保存 ----
async function onSave(doc: AnnotationDoc, blob: Blob) {
  if (!quiz.value || !page.value) return
  saving.value = true
  try {
    await quizApi.saveAnnotations(quiz.value.id, page.value.id, doc, blob)
    const fresh = await quizApi.show(quiz.value.id)
    // 現在ページの注釈だけ差し替える（エディタの再読込を避ける）
    const updated = fresh.pages[pageIdx.value]
    if (updated && quiz.value.pages[pageIdx.value]) {
      quiz.value.pages[pageIdx.value]!.hasAnnotated = updated.hasAnnotated
      quiz.value.pages[pageIdx.value]!.annotatedVersion = updated.annotatedVersion
    }
    editor.value?.markSaved()
    dirty.value = false
    ui.notify('添削を保存しました')
  } catch {
    ui.notify('添削の保存に失敗しました')
  } finally {
    saving.value = false
  }
}

// ---- 採点 ----
const max = computed(() => quiz.value?.maxScorePerPage ?? 10)
function setMark(m: QuizMark) {
  form.mark = form.mark === m ? null : m
  form.score = form.mark ? scoreForMark(form.mark, max.value) : null
  saveGrade()
}
async function saveGrade() {
  if (!quiz.value || !page.value) return
  form.saving = true
  try {
    let score = form.score === null || form.score === ('' as unknown) ? null : Number(form.score)
    if (score !== null) score = Math.max(0, Math.min(max.value, Math.round(score)))
    await quizApi.grade(quiz.value.id, page.value.id, { mark: form.mark, score, comment: form.comment.trim() || null })
    const p = quiz.value.pages[pageIdx.value]!
    p.mark = form.mark
    p.score = score ?? (form.mark ? scoreForMark(form.mark, max.value) : null)
    p.comment = form.comment.trim() || null
    form.score = p.score
  } catch {
    ui.notify('採点の保存に失敗しました')
  } finally {
    form.saving = false
  }
}

const gradedCount = computed(() => quiz.value?.pages.filter((p) => p.score !== null).length ?? 0)
const total = computed(() => quiz.value?.pages.reduce((s, p) => s + (p.score ?? 0), 0) ?? 0)

async function finish() {
  if (!quiz.value) return
  if (dirty.value) {
    ui.notify('先に添削を保存してください')
    return
  }
  if (gradedCount.value < quiz.value.pages.length) {
    ui.notify(`未採点のページがあります（${gradedCount.value}/${quiz.value.pages.length}）`)
    return
  }
  if (!confirm(`添削を完了しますか？（合計 ${total.value} / ${quiz.value.maxScore}点）\n完了すると生徒に結果が表示されます。`)) return
  try {
    await quizApi.finish(quiz.value.id)
    ui.notify('添削を完了しました')
    await load()
  } catch (e: unknown) {
    const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message
    ui.notify(msg || '完了処理に失敗しました')
  }
}
async function reopen() {
  if (!quiz.value || !confirm('添削をやり直しますか？（生徒側では「提出済み」に戻ります）')) return
  try {
    await quizApi.reopen(quiz.value.id)
    await load()
    ui.notify('添削待ちに戻しました')
  } catch {
    ui.notify('処理に失敗しました')
  }
}
async function downloadResult() {
  if (!quiz.value) return
  try {
    await quizApi.downloadResultPdf(quiz.value.id, quiz.value.title)
  } catch {
    ui.notify('ダウンロードに失敗しました')
  }
}
</script>

<template>
  <div v-if="quiz" class="grade">
    <div class="head">
      <button class="back" @click="router.push({ name: 'tutor-quizzes' })">‹ 小テスト一覧</button>
      <div style="min-width: 0; flex: 1">
        <div style="font-size: 15px; font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis">{{ quiz.title }}</div>
        <div style="font-size: 11.5px; color: var(--mut)">
          {{ quiz.bookTitle }}・{{ quiz.pageCount }}ページ・満点 {{ quiz.maxScorePerPage }}点/問
          <template v-if="quiz.submittedAt">・提出 {{ quiz.submittedAt.slice(0, 16).replace(/-/g, '/') }}</template>
        </div>
      </div>
      <span class="chip" :class="quiz.status">{{ quiz.status === 'graded' ? '添削済み' : quiz.status === 'submitted' ? '添削待ち' : '未提出' }}</span>
      <div class="total">採点 {{ gradedCount }}/{{ quiz.pageCount }}・<b>{{ total }}</b> / {{ quiz.maxScore }}点</div>
      <button v-if="quiz.status === 'graded'" class="btn" @click="downloadResult">添削済みPDF</button>
      <button v-if="quiz.status === 'graded'" class="btn" @click="reopen">やり直す</button>
      <button v-else class="btn primary" :disabled="quiz.status === 'assigned'" @click="finish">添削を完了</button>
    </div>

    <div class="tabs">
      <button v-for="(p, i) in quiz.pages" :key="p.id" class="ptab" :class="{ on: i === pageIdx }" @click="gotoPage(i)">
        <span class="pn">{{ p.pageNo }}</span>
        <span class="pl">{{ p.label }}</span>
        <span v-if="p.mark" class="pm" :style="{ color: MARK_COLOR[p.mark] }">{{ MARK_LABEL[p.mark] }}</span>
        <span v-else-if="!p.hasAnswer" class="pm" style="color: #c0444f">未</span>
      </button>
    </div>

    <div v-if="page" class="body">
      <div class="main">
        <div class="label-bar">
          <b>{{ page.pageNo }}. {{ page.label }}</b>
          <span v-if="page.chapter">{{ page.chapter }}</span>
          <span v-if="page.difficulty" style="color: #d98a1a">{{ page.difficulty }}</span>
          <span v-if="page.answerUploadedAt" style="margin-left: auto">撮影 {{ page.answerUploadedAt.slice(0, 16).replace(/-/g, '/') }}</span>
        </div>
        <AnnotationEditor
          v-if="answerUrl"
          ref="editor"
          :key="page.id"
          :image-url="answerUrl"
          :model-value="page.annotations"
          :saving="saving"
          @save="onSave"
          @dirty="dirty = $event"
        />
        <div v-else-if="page.hasAnswer" class="empty">回答画像を読み込み中…</div>
        <div v-else class="empty">このページの回答はまだ提出されていません。</div>
      </div>

      <aside class="side">
        <div class="card ref">
          <div class="ref-head">
            <div class="seg">
              <button :class="{ on: refMode === 'question' }" @click="refMode = 'question'">問題ページ</button>
              <button v-if="page.refPage" :class="{ on: refMode === 'answer' }" @click="refMode = 'answer'">解答ページ</button>
            </div>
            <span style="font-size: 10.5px; color: var(--faint)">
              {{ refMode === 'answer' ? `${page.refPdfTitle} p.${page.refPage}` : `${page.pdfTitle} p.${page.pdfPage}` }}
            </span>
          </div>
          <div class="ref-body">
            <PdfThumb v-if="refMode === 'answer' && page.refPdfId && page.refPage" :key="'r' + page.id" :pdf-id="page.refPdfId" :page="page.refPage" :width="520" eager />
            <PdfThumb v-else-if="page.pdfId" :key="'q' + page.id" :pdf-id="page.pdfId" :page="page.pdfPage" :width="520" eager />
          </div>
        </div>

        <div class="card grading">
          <div style="font-size: 12.5px; font-weight: 700; margin-bottom: 8px">採点</div>
          <div class="marks">
            <button v-for="m in (['o', 'tri', 'x'] as const)" :key="m" class="mark" :class="{ on: form.mark === m }" :style="{ '--c': MARK_COLOR[m] }" :disabled="!page.hasAnswer" @click="setMark(m)">
              <span class="mk">{{ MARK_LABEL[m] }}</span>
              <span class="ms">{{ scoreForMark(m, max) }}点</span>
            </button>
          </div>
          <div class="score-line">
            <span>点数</span>
            <input v-model.number="form.score" type="number" min="0" :max="max" :disabled="!page.hasAnswer" @change="saveGrade" />
            <span>/ {{ max }}点</span>
            <span v-if="form.saving" style="font-size: 11px; color: var(--faint)">保存中…</span>
          </div>
          <textarea v-model="form.comment" rows="3" placeholder="コメント（生徒に表示されます）" :disabled="!page.hasAnswer" @blur="saveGrade"></textarea>
          <div class="nav">
            <button class="btn" :disabled="pageIdx === 0" @click="gotoPage(pageIdx - 1)">‹ 前のページ</button>
            <button class="btn" :disabled="pageIdx >= quiz.pages.length - 1" @click="gotoPage(pageIdx + 1)">次のページ ›</button>
          </div>
        </div>
      </aside>
    </div>
  </div>
  <div v-else class="empty">読み込み中…</div>
</template>

<style scoped>
.grade {
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.head {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
}
.back {
  border: none;
  background: none;
  color: #3b50cc;
  font-size: 12.5px;
  font-weight: 600;
  cursor: pointer;
  padding: 0;
  white-space: nowrap;
}
.chip {
  font-size: 10.5px;
  font-weight: 700;
  padding: 3px 9px;
  border-radius: 999px;
  background: #f1f2f4;
  color: var(--mut);
}
.chip.submitted {
  background: #e8eefb;
  color: #2e4a8f;
}
.chip.graded {
  background: #e6f5ec;
  color: #2f7a4f;
}
.total {
  font-size: 12px;
  color: var(--mut);
  white-space: nowrap;
}
.total b {
  font-size: 16px;
  color: var(--ink);
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
  white-space: nowrap;
}
.btn.primary {
  background: #1c2024;
  border-color: #1c2024;
  color: #fff;
}
.btn:disabled {
  opacity: 0.4;
  cursor: default;
}
.tabs {
  display: flex;
  gap: 6px;
  overflow-x: auto;
  scrollbar-width: none;
  padding-bottom: 2px;
}
.tabs::-webkit-scrollbar {
  display: none;
}
.ptab {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 7px 12px;
  border: 1px solid #e3e6ea;
  border-radius: 999px;
  background: #fff;
  font-size: 12px;
  color: var(--mut);
  cursor: pointer;
  white-space: nowrap;
  max-width: 260px;
}
.ptab.on {
  background: #1c2024;
  border-color: #1c2024;
  color: #fff;
}
.pn {
  width: 18px;
  height: 18px;
  border-radius: 50%;
  background: rgba(0, 0, 0, 0.08);
  font-size: 10.5px;
  font-weight: 700;
  display: inline-flex;
  align-items: center;
  justify-content: center;
}
.ptab.on .pn {
  background: rgba(255, 255, 255, 0.2);
}
.pl {
  overflow: hidden;
  text-overflow: ellipsis;
}
.pm {
  font-weight: 800;
  font-size: 13px;
}
.body {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 360px;
  gap: 12px;
  align-items: start;
}
@media (max-width: 1000px) {
  .body {
    grid-template-columns: 1fr;
  }
}
.main {
  min-width: 0;
}
.label-bar {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
  font-size: 12px;
  color: var(--mut);
  margin-bottom: 6px;
}
.label-bar b {
  color: var(--ink);
  font-size: 13px;
}
.empty {
  padding: 40px;
  text-align: center;
  font-size: 12.5px;
  color: var(--faint);
  background: #fff;
  border: 1px dashed #d8dce1;
  border-radius: 12px;
}
.side {
  display: flex;
  flex-direction: column;
  gap: 12px;
  position: sticky;
  top: 0;
}
.card {
  background: #fff;
  border: 1px solid var(--line);
  border-radius: 14px;
  padding: 12px;
}
.ref-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  margin-bottom: 8px;
}
.seg {
  display: inline-flex;
  border: 1px solid #e3e6ea;
  border-radius: 8px;
  overflow: hidden;
}
.seg button {
  padding: 5px 10px;
  border: none;
  background: #fff;
  font-size: 11.5px;
  font-weight: 600;
  color: var(--mut);
  cursor: pointer;
}
.seg button.on {
  background: #f1f2f4;
  color: var(--ink);
}
.ref-body {
  max-height: 46vh;
  overflow-y: auto;
}
.ref-body :deep(.thumb) {
  width: 100% !important;
  aspect-ratio: auto;
}
.marks {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 8px;
}
.mark {
  --c: #666;
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 10px 4px;
  border: 2px solid #e3e6ea;
  border-radius: 12px;
  background: #fff;
  cursor: pointer;
  color: var(--c);
}
.mark.on {
  border-color: var(--c);
  background: color-mix(in srgb, var(--c) 10%, #fff);
}
.mark:disabled {
  opacity: 0.4;
  cursor: default;
}
.mk {
  font-size: 26px;
  font-weight: 800;
  line-height: 1;
}
.ms {
  font-size: 10.5px;
  color: var(--mut);
  margin-top: 4px;
}
.score-line {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 12px;
  color: var(--mut);
  margin: 10px 0 8px;
}
.score-line input {
  width: 80px;
  padding: 7px 9px;
  border: 1px solid #e3e6ea;
  border-radius: 8px;
  font-size: 14px;
  font-weight: 700;
  text-align: right;
}
textarea {
  width: 100%;
  padding: 8px 10px;
  border: 1px solid #e3e6ea;
  border-radius: 9px;
  font-size: 12.5px;
  font-family: inherit;
  resize: vertical;
}
.nav {
  display: flex;
  justify-content: space-between;
  gap: 8px;
  margin-top: 10px;
}
</style>
