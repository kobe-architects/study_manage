<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AnnotationEditor from '@/components/AnnotationEditor.vue'
import AuthImage from '@/components/AuthImage.vue'
import PdfThumb from '@/components/PdfThumb.vue'
import { MARK_COLOR, MARK_LABEL, fetchBlobUrl, quizApi } from '@/api/quiz'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'
import type { AnnotationDoc, QuizDetail, QuizMark, QuizPageDetail } from '@/types'

/**
 * 講師用: 採点・添削画面。
 * 左レール: 添削ツール（固定）＋ページリスト / 中央: 回答写真の添削エディタ /
 * 右レール: ステータス・完了ボタン（固定）＋採点＋英単語テストの解答一覧。
 * 添削はペン等で変更すると自動保存される。
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
/** 英単語テストの解答一覧（採点用） */
const vocabAnswers = computed(() => (page.value?.kind === 'vocab' ? page.value.vocabWords ?? [] : []))

const form = reactive<{ mark: QuizMark | null; comment: string; saving: boolean }>({ mark: null, comment: '', saving: false })

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
  form.comment = p?.comment ?? ''
}

onMounted(async () => {
  // 生徒側の設定で小テストメニューが非表示のときは直接アクセスも許可しない
  const auth = useAuthStore()
  if (auth.user?.role === 'tutor' && auth.user.student?.tutorQuizEnabled !== true) {
    router.replace({ name: 'tutor-home' })
    return
  }
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
  await loadAnswer()
  syncForm()
})

/** 未保存の添削があれば保存を完了させる（自動保存の flush） */
let saveTask: Promise<void> | null = null
async function flushAnnotations() {
  if (!dirty.value || !editor.value) return
  await editor.value.save()
  if (saveTask) await saveTask
}

async function gotoPage(i: number) {
  if (i === pageIdx.value || !quiz.value || i < 0 || i >= quiz.value.pages.length) return
  await flushAnnotations()
  dirty.value = false
  pageIdx.value = i
}

// ---- 添削の保存（エディタから自動保存で呼ばれる） ----
async function onSave(doc: AnnotationDoc, blob: Blob) {
  if (!quiz.value || !page.value) return
  const task = (async () => {
    saving.value = true
    try {
      await quizApi.saveAnnotations(quiz.value!.id, page.value!.id, doc, blob)
      const fresh = await quizApi.show(quiz.value!.id)
      // 現在ページの注釈だけ差し替える（エディタの再読込を避ける）
      const updated = fresh.pages[pageIdx.value]
      if (updated && quiz.value!.pages[pageIdx.value]) {
        quiz.value!.pages[pageIdx.value]!.hasAnnotated = updated.hasAnnotated
        quiz.value!.pages[pageIdx.value]!.annotatedVersion = updated.annotatedVersion
      }
      editor.value?.markSaved()
      dirty.value = editor.value?.isDirty() ?? false
    } catch {
      ui.notify('添削の保存に失敗しました')
    } finally {
      saving.value = false
    }
  })()
  saveTask = task
  await task
}

// ---- 採点（○△× のみ。点数はサーバー側でマークから自動設定される） ----
const hasVocab = computed(() => quiz.value?.pages.some((p) => p.kind === 'vocab') ?? false)
const sheetOpen = ref(false)
/** 解答ページ（解答つき PDF）の拡大表示 */
const ansOpen = ref(false)
watch(pageIdx, () => (ansOpen.value = false))
async function downloadAnswers() {
  if (!quiz.value) return
  try {
    await quizApi.downloadAnswersPdf(quiz.value.id, quiz.value.title)
  } catch {
    ui.notify('ダウンロードに失敗しました')
  }
}
function setMark(m: QuizMark) {
  form.mark = form.mark === m ? null : m
  saveGrade()
}
async function saveGrade() {
  if (!quiz.value || !page.value) return
  form.saving = true
  try {
    // 点数はサーバー側でマークから自動設定（○=満点・△=半分・×=0）。表示には使わない
    await quizApi.grade(quiz.value.id, page.value.id, { mark: form.mark, score: null, comment: form.comment.trim() || null })
    const p = quiz.value.pages[pageIdx.value]!
    p.mark = form.mark
    p.comment = form.comment.trim() || null
  } catch {
    ui.notify('採点の保存に失敗しました')
  } finally {
    form.saving = false
  }
}

const gradedCount = computed(() => quiz.value?.pages.filter((p) => p.mark !== null).length ?? 0)
const markCounts = computed(() => {
  const t = { o: 0, tri: 0, x: 0 }
  for (const p of quiz.value?.pages ?? []) {
    if (p.mark) t[p.mark]++
  }
  return t
})

async function finish() {
  if (!quiz.value) return
  await flushAnnotations()
  if (gradedCount.value < quiz.value.pages.length) {
    ui.notify(`未評価のページがあります（${gradedCount.value}/${quiz.value.pages.length}）`)
    return
  }
  if (!confirm(`採点・添削を完了しますか？（○${markCounts.value.o} △${markCounts.value.tri} ×${markCounts.value.x}）\n完了すると生徒に結果が表示されます。`)) return
  try {
    await quizApi.finish(quiz.value.id)
    ui.notify('採点・添削を完了しました')
    await load()
  } catch (e: unknown) {
    const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message
    ui.notify(msg || '完了処理に失敗しました')
  }
}
async function reopen() {
  if (!quiz.value || !confirm('採点・添削をやり直しますか？（生徒側では「提出済み」に戻ります）')) return
  try {
    await quizApi.reopen(quiz.value.id)
    await load()
    ui.notify('採点・添削待ちに戻しました')
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
    <!-- 左レール: 添削ツール（エディタからテレポート）＋ページリスト -->
    <aside class="rail rail-l">
      <div v-if="page && page.hasAnswer" class="card tools-card">
        <div class="rail-title">添削ツール</div>
        <div id="grade-tools"></div>
      </div>
      <div class="card">
        <div class="rail-title">ページ</div>
        <div class="plist-body">
          <button v-for="(p, i) in quiz.pages" :key="p.id" class="prow" :class="{ on: i === pageIdx }" @click="gotoPage(i)">
            <span class="pn">{{ p.pageNo }}</span>
            <span class="pl">{{ p.label }}</span>
            <span v-if="p.mark" class="pm" :style="{ color: i === pageIdx ? '#fff' : MARK_COLOR[p.mark] }">{{ MARK_LABEL[p.mark] }}</span>
            <span v-else-if="!p.hasAnswer" class="pm ng">未</span>
          </button>
        </div>
      </div>
    </aside>

    <!-- 中央: 添削対象の画像 -->
    <div class="main">
      <div class="head-line">
        <button class="back" @click="router.push({ name: 'tutor-quizzes' })">‹ 小テスト一覧</button>
        <b class="ttl">{{ quiz.title }}</b>
        <span class="meta">
          {{ quiz.bookTitle ?? '英単語テスト' }}・{{ quiz.pageCount }}ページ
          <template v-if="quiz.submittedAt">・提出 {{ quiz.submittedAt.slice(0, 16).replace(/-/g, '/') }}</template>
        </span>
      </div>
      <div v-if="page" class="label-bar">
        <b>{{ page.pageNo }}. {{ page.label }}</b>
        <span v-if="page.kind === 'vocab'" class="vtag">英単語テスト・{{ page.maxScore }}問</span>
        <span v-if="page.chapter">{{ page.chapter }}</span>
        <span v-if="page.difficulty" style="color: #d98a1a">{{ page.difficulty }}</span>
        <span class="bar-ctrl">
          <template v-if="answerUrl">
            <button class="mini" title="縮小" @click="editor?.zoomBy(1 / 1.25)">−</button>
            <button class="mini" title="拡大" @click="editor?.zoomBy(1.25)">＋</button>
            <button class="mini" title="画像全体を表示" @click="editor?.fit()">全体</button>
            <span class="ctrl-sep"></span>
          </template>
          <button class="mini" :disabled="pageIdx === 0" @click="gotoPage(pageIdx - 1)">‹ 前へ</button>
          <span class="pcount">{{ pageIdx + 1 }} / {{ quiz.pages.length }}</span>
          <button class="mini" :disabled="pageIdx >= quiz.pages.length - 1" @click="gotoPage(pageIdx + 1)">次へ ›</button>
        </span>
      </div>
      <template v-if="page">
        <AnnotationEditor
          v-if="answerUrl"
          ref="editor"
          :key="page.id"
          :image-url="answerUrl"
          :model-value="page.annotations"
          :saving="saving"
          toolbar-target="#grade-tools"
          @save="onSave"
          @dirty="dirty = $event"
        />
        <div v-else-if="page.hasAnswer" class="empty">回答画像を読み込み中…</div>
        <div v-else class="empty">このページの回答はまだ提出されていません。</div>
      </template>
    </div>

    <!-- 右レール: ステータス・完了（固定）＋採点＋英単語の解答 -->
    <aside class="rail rail-r">
      <div class="card status-card">
        <div class="status-line">
          <span class="chip" :class="quiz.status">{{ quiz.status === 'graded' ? '採点・添削済み' : quiz.status === 'submitted' ? '採点・添削待ち' : '未提出' }}</span>
          <span class="total">
            評価 {{ gradedCount }}/{{ quiz.pageCount }}
            <span v-for="m in (['o', 'tri', 'x'] as const)" :key="m" :style="{ color: MARK_COLOR[m], fontWeight: 700, marginLeft: '6px' }">{{ MARK_LABEL[m] }}{{ markCounts[m] }}</span>
          </span>
        </div>
        <div class="status-btns">
          <button v-if="quiz.status !== 'graded'" class="btn primary" :disabled="quiz.status === 'assigned'" @click="finish">採点・添削を完了</button>
          <button v-if="quiz.status === 'graded'" class="btn" @click="reopen">やり直す</button>
          <button v-if="quiz.status === 'graded'" class="btn" @click="downloadResult">採点・添削済みPDF</button>
          <button v-if="hasVocab" class="btn" @click="downloadAnswers">英単語 解答PDF</button>
        </div>
      </div>

      <div v-if="page" class="card grading">
        <div style="font-size: 12.5px; font-weight: 700; margin-bottom: 8px">
          評価（○△×）
          <span v-if="form.saving" style="font-size: 11px; font-weight: 400; color: var(--faint); margin-left: 6px">保存中…</span>
        </div>
        <div class="marks">
          <button v-for="m in (['o', 'tri', 'x'] as const)" :key="m" class="mark" :class="{ on: form.mark === m }" :style="{ '--c': MARK_COLOR[m] }" :disabled="!page.hasAnswer" @click="setMark(m)">
            <span class="mk">{{ MARK_LABEL[m] }}</span>
          </button>
        </div>
        <textarea v-model="form.comment" rows="3" style="margin-top: 10px" placeholder="コメント（生徒に表示されます）" :disabled="!page.hasAnswer" @blur="saveGrade"></textarea>
        <div class="nav">
          <button class="btn" :disabled="pageIdx === 0" @click="gotoPage(pageIdx - 1)">‹ 前へ</button>
          <button class="btn" :disabled="pageIdx >= quiz.pages.length - 1" @click="gotoPage(pageIdx + 1)">次へ ›</button>
        </div>
      </div>

      <!-- 解答ページ（解答つき PDF がある場合に自動表示） -->
      <div v-if="page && page.kind === 'pdf' && page.ansPdfId && page.ansPage" class="card ref">
        <div class="ref-head">
          <div style="font-size: 12.5px; font-weight: 700">解答（{{ page.ansPdfTitle }} p.{{ page.ansPage }}）</div>
          <button class="btn" style="padding: 5px 10px; font-size: 11.5px" @click="ansOpen = true">拡大</button>
        </div>
        <div class="ref-body ans-click" title="クリックで拡大表示" @click="ansOpen = true">
          <PdfThumb :key="'ans' + page.id" :pdf-id="page.ansPdfId" :page="page.ansPage" :width="560" eager />
        </div>
      </div>

      <div v-if="page && page.kind === 'vocab'" class="card ref">
        <div class="ref-head">
          <div style="font-size: 12.5px; font-weight: 700">解答一覧（{{ vocabAnswers.length }}問）</div>
          <button class="btn" style="padding: 5px 10px; font-size: 11.5px" @click="sheetOpen = true">問題用紙</button>
        </div>
        <div class="ref-body">
          <table class="ans">
            <tbody>
              <tr v-for="(w, i) in vocabAnswers" :key="w.id">
                <td class="n">{{ i + 1 }}</td>
                <td class="q">{{ w.question }}</td>
                <td class="a">{{ w.answer }}<span v-if="w.choices?.length" class="ch">（{{ 'ABCD'[w.choices.indexOf(w.answer)] ?? '?' }}）</span></td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </aside>
  </div>
  <div v-else class="empty">読み込み中…</div>
  <div v-if="sheetOpen && quiz && page" class="sheet-overlay" @click="sheetOpen = false">
    <div class="sheet-modal" @click.stop>
      <button class="sheet-x" @click="sheetOpen = false">×</button>
      <AuthImage :src="quizApi.renderImageUrl(quiz.id, page.id)" />
    </div>
  </div>
  <!-- 解答ページの拡大表示 -->
  <div v-if="ansOpen && page && page.ansPdfId && page.ansPage" class="sheet-overlay" @click="ansOpen = false">
    <div class="sheet-modal" @click.stop>
      <button class="sheet-x" @click="ansOpen = false">×</button>
      <div class="ans-zoom"><PdfThumb :key="'ansz' + page.id" :pdf-id="page.ansPdfId" :page="page.ansPage" :width="1400" eager /></div>
    </div>
  </div>
</template>

<style scoped>
/* コンテンツ幅（max-width: 1100px）の外側の余白まで使う3カラム。左右レールは上部に固定（sticky） */
.grade {
  width: calc(100vw - 44px);
  margin-left: calc(50% - 50vw + 22px);
  display: grid;
  grid-template-columns: 190px minmax(0, 1fr) 290px;
  gap: 14px;
  align-items: start;
}
.rail {
  position: sticky;
  top: 0;
  max-height: calc(100vh - 80px);
  overflow-y: auto;
  display: flex;
  flex-direction: column;
  gap: 10px;
  scrollbar-width: thin;
}
/* タブレット・スマホ: 1カラムに。ツール＋ページリストは上部に固定し、ページは横スクロールのチップ列にする */
@media (max-width: 1100px) {
  .grade {
    width: auto;
    margin-left: 0;
    grid-template-columns: 1fr;
  }
  .rail {
    max-height: none;
    overflow: visible;
  }
  .rail-r {
    position: static;
  }
  .rail-l {
    position: sticky;
    top: 0;
    z-index: 6;
    background: #f6f7f9;
    padding-bottom: 4px;
    gap: 8px;
  }
  .plist-body {
    display: flex;
    gap: 6px;
    overflow-x: auto;
    scrollbar-width: none;
    padding-bottom: 2px;
  }
  .plist-body::-webkit-scrollbar {
    display: none;
  }
  .plist-body .prow {
    width: auto;
    flex-shrink: 0;
    border: 1px solid #e3e6ea;
    border-radius: 999px;
    padding: 6px 12px;
  }
  .plist-body .pl {
    max-width: 150px;
    flex: none;
  }
}
.card {
  background: #fff;
  border: 1px solid var(--line);
  border-radius: 14px;
  padding: 10px 12px;
}
.rail-title {
  font-size: 11.5px;
  font-weight: 700;
  color: var(--mut);
  margin-bottom: 6px;
}
/* ページリスト */
.prow {
  display: flex;
  align-items: center;
  gap: 7px;
  width: 100%;
  padding: 7px 8px;
  border: none;
  border-radius: 9px;
  background: transparent;
  font-size: 12px;
  color: var(--mut);
  cursor: pointer;
  text-align: left;
}
.prow:hover {
  background: #f3f4f7;
}
.prow.on {
  background: #1c2024;
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
  flex-shrink: 0;
}
.prow.on .pn {
  background: rgba(255, 255, 255, 0.2);
}
.pl {
  flex: 1;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.pm {
  font-weight: 800;
  font-size: 13px;
  flex-shrink: 0;
}
.pm.ng {
  color: #c0444f;
}
.prow.on .pm.ng {
  color: #ffb3ba;
}
/* 中央 */
.main {
  min-width: 0;
}
.head-line {
  display: flex;
  align-items: baseline;
  gap: 10px;
  flex-wrap: wrap;
  margin-bottom: 6px;
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
.ttl {
  font-size: 14px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  max-width: 46%;
}
.meta {
  font-size: 11.5px;
  color: var(--mut);
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
.bar-ctrl {
  margin-left: auto;
  display: flex;
  align-items: center;
  gap: 5px;
}
.mini {
  padding: 5px 10px;
  border: 1px solid #e3e6ea;
  border-radius: 8px;
  background: #fff;
  font-size: 11.5px;
  font-weight: 600;
  color: var(--mut);
  cursor: pointer;
  white-space: nowrap;
}
.mini:disabled {
  opacity: 0.4;
  cursor: default;
}
.ctrl-sep {
  width: 1px;
  height: 16px;
  background: #e3e6ea;
  margin: 0 3px;
}
.pcount {
  font-size: 11px;
  color: var(--faint);
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
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
/* 右レール */
.status-line {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  margin-bottom: 8px;
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
  font-size: 11.5px;
  color: var(--mut);
  white-space: nowrap;
}
.total b {
  font-size: 15px;
  color: var(--ink);
}
.status-btns {
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.btn {
  padding: 8px 12px;
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
.vtag {
  font-size: 11px;
  font-weight: 700;
  color: #2f7a4f;
  background: #e6f5ec;
  padding: 2px 8px;
  border-radius: 999px;
}
.ref-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  margin-bottom: 8px;
}
.ref-body {
  max-height: 46vh;
  overflow-y: auto;
}
.ref-body :deep(.thumb) {
  width: 100% !important;
  aspect-ratio: auto;
}
.ans-click {
  cursor: zoom-in;
}
.ans-zoom :deep(.thumb) {
  width: 100% !important;
  aspect-ratio: auto;
  border: none;
}
.ans {
  width: 100%;
  border-collapse: collapse;
  font-size: 12px;
}
.ans td {
  padding: 4px 6px;
  border-bottom: 1px solid #f1f2f4;
  vertical-align: top;
}
.ans .n {
  width: 26px;
  color: var(--faint);
  text-align: right;
}
.ans .q {
  color: var(--mut);
  width: 42%;
}
.ans .a {
  font-weight: 700;
}
.ans .ch {
  color: #2e4a8f;
  font-weight: 600;
  margin-left: 4px;
}
.sheet-overlay {
  position: fixed;
  inset: 0;
  background: rgba(20, 24, 32, 0.6);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 70;
  padding: 16px;
}
.sheet-modal {
  position: relative;
  background: #fff;
  border-radius: 12px;
  max-width: 900px;
  width: 100%;
  max-height: 94vh;
  overflow: auto;
  padding: 10px;
}
.sheet-modal :deep(img) {
  width: 100%;
  display: block;
}
.sheet-x {
  position: absolute;
  top: 8px;
  right: 8px;
  width: 32px;
  height: 32px;
  border-radius: 50%;
  border: none;
  background: #1c2024;
  color: #fff;
  font-size: 18px;
  cursor: pointer;
  z-index: 1;
}
</style>
