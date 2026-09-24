<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AnnotationEditor from '@/components/AnnotationEditor.vue'
import AuthImage from '@/components/AuthImage.vue'
import PdfThumb from '@/components/PdfThumb.vue'
import { fetchBlobUrl, quizApi, rateColor } from '@/api/quiz'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'
import type { AnnotationDoc, QuizDetail, QuizPageDetail } from '@/types'

/**
 * 講師用: 採点・添削画面。
 * PC: 左レール: 添削ツール（固定）＋ページリスト / 中央: 回答写真の添削エディタ /
 *     右レール: ステータス・完了ボタン・採点（小テスト全体の得点／満点）＋ページのコメント＋英単語テストの解答一覧。
 * タブレット・スマホ（幅 1100px 以下）: ツール・ページリストは表示せず、回答写真（閲覧用）をタップすると
 *     全画面の添削モードを開く。全画面では左に縦型ツール、上にページ一覧（横スクロール）、
 *     「解答を表示」で上下分割して下に解答ページ（境界はドラッグで移動）。PC でも「全画面」ボタンで同じ画面を使える。
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

const form = reactive<{ comment: string; saving: boolean }>({ comment: '', saving: false })
/** 小テスト全体の採点（得点＝分子・満点＝分母）。ページ別の○△×は廃止 */
const scoreForm = reactive<{ score: number | null; maxScore: number | null; saving: boolean }>({ score: null, maxScore: null, saving: false })
const scoreEntered = computed(() => scoreForm.score !== null && scoreForm.maxScore !== null && scoreForm.maxScore > 0)
const scoreRate = computed(() => (scoreEntered.value ? Math.round((scoreForm.score! / scoreForm.maxScore!) * 100) : null))
/** 完了時の採点未入力の警告モーダル */
const warnOpen = ref(false)
const scoreInput = ref<HTMLInputElement | null>(null)
function syncScoreForm() {
  const q = quiz.value
  scoreForm.score = q?.enteredScore ?? null
  // 満点が未入力ならページ満点の合計（英単語テストは出題数）を既定値にする
  scoreForm.maxScore = q?.enteredMaxScore ?? q?.defaultMaxScore ?? null
}
async function saveScore() {
  if (!quiz.value) return
  const sc = scoreForm.score === null || scoreForm.score === ('' as unknown) ? null : Math.max(0, Math.floor(Number(scoreForm.score)))
  const mx = scoreForm.maxScore === null || scoreForm.maxScore === ('' as unknown) ? null : Math.max(1, Math.floor(Number(scoreForm.maxScore)))
  if (sc !== null && mx !== null && sc > mx) {
    ui.notify('得点が満点を超えています')
    return
  }
  scoreForm.saving = true
  try {
    const r = await quizApi.score(quiz.value.id, { score: sc, maxScore: mx })
    quiz.value.enteredScore = r.score
    quiz.value.enteredMaxScore = r.maxScore
    quiz.value.scoreEntered = r.score !== null && r.maxScore !== null
    if (r.maxScore !== null) quiz.value.maxScore = r.maxScore
    scoreForm.score = r.score
    scoreForm.maxScore = r.maxScore ?? quiz.value.defaultMaxScore
  } catch (e: unknown) {
    const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message
    ui.notify(msg || '採点の保存に失敗しました')
  } finally {
    scoreForm.saving = false
  }
}

// ---- 画面幅（タブレット・スマホ判定）と全画面添削モード ----
const mq = typeof window !== 'undefined' ? window.matchMedia('(max-width: 1100px)') : null
const narrow = ref(mq?.matches ?? false)
const onMq = (e: MediaQueryListEvent) => (narrow.value = e.matches)
const fsOpen = ref(false)
/** オーバーレイが DOM に入ってからエディタを描画する（ツールバーのテレポート先 #fs-tools を先に作るため） */
const fsReady = ref(false)
const showAns = ref(false)
/** 上下分割の上側（添削）の比率 */
const splitRatio = ref(0.55)
const fsMain = ref<HTMLElement | null>(null)
/** 全画面中はページを完全に固定する（iOS のゴムスクロール・引っ張って更新・ダブルタップ拡大を防ぐ） */
let lockedScrollY = 0
function lockPage() {
  lockedScrollY = window.scrollY
  const b = document.body.style
  b.position = 'fixed'
  b.top = `-${lockedScrollY}px`
  b.left = '0'
  b.right = '0'
  b.width = '100%'
  b.overflow = 'hidden'
  b.overscrollBehavior = 'none'
  document.documentElement.style.overscrollBehavior = 'none'
  document.addEventListener('touchmove', onDocTouchMove, { passive: false })
  document.addEventListener('gesturestart', preventEvent)
  document.addEventListener('gesturechange', preventEvent)
}
function unlockPage() {
  const b = document.body.style
  b.position = ''
  b.top = ''
  b.left = ''
  b.right = ''
  b.width = ''
  b.overflow = ''
  b.overscrollBehavior = ''
  document.documentElement.style.overscrollBehavior = ''
  document.removeEventListener('touchmove', onDocTouchMove)
  document.removeEventListener('gesturestart', preventEvent)
  document.removeEventListener('gesturechange', preventEvent)
  window.scrollTo(0, lockedScrollY)
}
function preventEvent(e: Event) {
  e.preventDefault()
}
/** 全画面中のタッチ移動: スクロールさせる領域（解答ペイン・ページ一覧・ツール列）以外は既定動作を止める */
function onDocTouchMove(e: TouchEvent) {
  const t = e.target as HTMLElement | null
  if (e.touches.length > 1 || !t || !t.closest('.fs-answer, .fs-pages, .fs-side')) e.preventDefault()
}
async function openFs() {
  if (!page.value?.hasAnswer) return
  fsOpen.value = true
  fsReady.value = false
  lockPage()
  await nextTick()
  fsReady.value = true
}
/** 全画面の終了時に添削をまとめて保存する（全画面中は変更のたびの自動保存をしない）。保存中はモーダルを表示 */
const closing = ref(false)
async function closeFs() {
  if (closing.value) return
  if (dirty.value && editor.value) {
    closing.value = true
    saving.value = true
    try {
      await flushAnnotations()
    } finally {
      saving.value = false
      closing.value = false
    }
  }
  fsOpen.value = false
  fsReady.value = false
  unlockPage()
}
/** 全画面中に未保存の添削があるままページを閉じようとしたら確認する */
function onBeforeUnload(e: BeforeUnloadEvent) {
  if (fsOpen.value && dirty.value) {
    e.preventDefault()
    e.returnValue = ''
  }
}
/** 解答ペインに出せる内容があるか（解答つき PDF のページ、または英単語テストの解答一覧） */
const hasAnsPane = computed(() => {
  const p = page.value
  if (!p) return false
  return (p.kind === 'pdf' && !!p.ansPdfId && !!p.ansPage) || (p.kind === 'vocab' && vocabAnswers.value.length > 0)
})
let dragDiv: number | null = null
function onDivDown(e: PointerEvent) {
  dragDiv = e.pointerId
  ;(e.currentTarget as HTMLElement).setPointerCapture(e.pointerId)
  e.preventDefault()
}
function onDivMove(e: PointerEvent) {
  if (dragDiv !== e.pointerId || !fsMain.value) return
  const r = fsMain.value.getBoundingClientRect()
  splitRatio.value = Math.min(0.85, Math.max(0.2, (e.clientY - r.top) / r.height))
}
function onDivUp(e: PointerEvent) {
  if (dragDiv === e.pointerId) dragDiv = null
}
function onKey(e: KeyboardEvent) {
  if (e.key === 'Escape' && fsOpen.value) {
    const t = e.target as HTMLElement | null
    if (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA')) return
    closeFs()
  }
}

async function load(keepIdx = true) {
  const q = await quizApi.show(quizId)
  quiz.value = q
  if (!keepIdx || pageIdx.value >= q.pages.length) pageIdx.value = 0
  syncScoreForm()
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
  mq?.addEventListener('change', onMq)
  window.addEventListener('keydown', onKey)
  window.addEventListener('beforeunload', onBeforeUnload)
})
onBeforeUnmount(() => {
  if (answerUrl.value) URL.revokeObjectURL(answerUrl.value)
  mq?.removeEventListener('change', onMq)
  window.removeEventListener('keydown', onKey)
  window.removeEventListener('beforeunload', onBeforeUnload)
  if (fsOpen.value) unlockPage()
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
        quiz.value!.pages[pageIdx.value]!.annotations = doc
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
/** ページのコメント（生徒に表示） */
async function saveGrade() {
  if (!quiz.value || !page.value) return
  const p = quiz.value.pages[pageIdx.value]!
  const next = form.comment.trim() || null
  if ((p.comment ?? null) === next) return
  form.saving = true
  try {
    await quizApi.grade(quiz.value.id, page.value.id, { comment: next })
    p.comment = next
  } catch {
    ui.notify('コメントの保存に失敗しました')
  } finally {
    form.saving = false
  }
}

async function finish() {
  if (!quiz.value) return
  await flushAnnotations()
  if (!scoreEntered.value || !quiz.value.scoreEntered) {
    // 採点が未入力ならモーダルで警告する
    warnOpen.value = true
    return
  }
  if (!confirm(`採点・添削を完了しますか？（得点 ${scoreForm.score} / ${scoreForm.maxScore}点・${scoreRate.value}%）\n完了すると生徒に結果が表示されます。`)) return
  try {
    await quizApi.finish(quiz.value.id)
    ui.notify('採点・添削を完了しました')
    await load()
  } catch (e: unknown) {
    const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message
    ui.notify(msg || '完了処理に失敗しました')
  }
}
function goToScoreInput() {
  warnOpen.value = false
  nextTick(() => scoreInput.value?.focus())
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
            <span v-if="!p.hasAnswer" class="pm ng">未</span>
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
          <template v-if="answerUrl && !narrow">
            <button class="mini" title="縮小" @click="editor?.zoomBy(1 / 1.25)">−</button>
            <button class="mini" title="拡大" @click="editor?.zoomBy(1.25)">＋</button>
            <button class="mini" title="画像全体を表示" @click="editor?.fit()">全体</button>
            <button class="mini" title="全画面で添削" @click="openFs">全画面</button>
            <span class="ctrl-sep"></span>
          </template>
          <button class="mini" :disabled="pageIdx === 0" @click="gotoPage(pageIdx - 1)">‹ 前へ</button>
          <span class="pcount">{{ pageIdx + 1 }} / {{ quiz.pages.length }}</span>
          <button class="mini" :disabled="pageIdx >= quiz.pages.length - 1" @click="gotoPage(pageIdx + 1)">次へ ›</button>
        </span>
      </div>
      <template v-if="page">
        <!-- タブレット・スマホ: 閲覧用（タップで全画面添削） -->
        <div v-if="narrow && answerUrl && !fsOpen" class="preview" @click="openFs">
          <AnnotationEditor :key="'pv' + page.id" :image-url="answerUrl" :model-value="page.annotations" readonly />
          <div class="preview-hint">タップして添削（全画面）</div>
        </div>
        <AnnotationEditor
          v-else-if="!narrow && answerUrl && !fsOpen"
          ref="editor"
          :key="page.id"
          :image-url="answerUrl"
          :model-value="page.annotations"
          :saving="saving"
          toolbar-target="#grade-tools"
          @save="onSave"
          @dirty="dirty = $event"
        />
        <div v-else-if="page.hasAnswer && !fsOpen" class="empty">回答画像を読み込み中…</div>
        <div v-else-if="!page.hasAnswer" class="empty">このページの回答はまだ提出されていません。</div>
      </template>
    </div>

    <!-- 右レール: ステータス・完了（固定）＋採点＋英単語の解答 -->
    <aside class="rail rail-r">
      <div class="card status-card">
        <div class="status-line">
          <span class="chip" :class="quiz.status">{{ quiz.status === 'graded' ? '採点・添削済み' : quiz.status === 'submitted' ? '採点・添削待ち' : '未提出' }}</span>
          <span v-if="quiz.selfGraded" class="chip self" title="生徒が提出時に自己採点した得点です。「やり直す」で講師の採点・添削に置き換えられます">自己採点</span>
          <span class="total">
            <template v-if="scoreEntered"><b :style="{ color: rateColor(scoreRate) }">{{ scoreForm.score }}</b> / {{ scoreForm.maxScore }}点（{{ scoreRate }}%）</template>
            <template v-else>採点未入力</template>
          </span>
        </div>
        <!-- 採点: 小テスト全体の得点（分子）と満点（分母） -->
        <div class="score-box">
          <div class="score-title">
            採点（得点 / 満点）
            <span v-if="scoreForm.saving" style="font-size: 11px; font-weight: 400; color: var(--faint); margin-left: 6px">保存中…</span>
          </div>
          <div class="score-inputs">
            <input ref="scoreInput" v-model.number="scoreForm.score" type="number" min="0" inputmode="numeric" placeholder="得点" :disabled="quiz.status === 'assigned'" @change="saveScore" />
            <span class="slash">/</span>
            <input v-model.number="scoreForm.maxScore" type="number" min="1" inputmode="numeric" placeholder="満点" :disabled="quiz.status === 'assigned'" @change="saveScore" />
            <span class="unit">点</span>
            <span v-if="scoreEntered" class="rate" :style="{ background: rateColor(scoreRate) }">{{ scoreRate }}%</span>
          </div>
          <div class="score-hint">満点の初期値はページ満点の合計（英単語テストは出題数）です。自由に変更できます。</div>
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
          このページへのコメント
          <span v-if="form.saving" style="font-size: 11px; font-weight: 400; color: var(--faint); margin-left: 6px">保存中…</span>
        </div>
        <textarea v-model="form.comment" rows="3" placeholder="コメント（生徒に表示されます）" :disabled="!page.hasAnswer" @blur="saveGrade"></textarea>
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
  <!-- 全画面添削: 上=ページ一覧（横スクロール）・左=縦型ツール・中央=添削キャンバス（「解答を表示」で上下分割） -->
  <div v-if="fsOpen && quiz && page" class="fs">
    <div class="fs-top">
      <button class="fs-close" @click="closeFs">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18" /></svg>
        閉じる
      </button>
      <div class="fs-pages">
        <button v-for="(p, i) in quiz.pages" :key="p.id" class="fs-page" :class="{ on: i === pageIdx }" @click="gotoPage(i)">
          <span class="pn">{{ p.pageNo }}</span>
          <span class="pl">{{ p.label }}</span>
          <span v-if="!p.hasAnswer" class="pm ng">未</span>
        </button>
      </div>
      <button v-if="hasAnsPane" class="fs-ans-btn" :class="{ on: showAns }" @click="showAns = !showAns">{{ showAns ? '解答を閉じる' : '解答を表示' }}</button>
    </div>
    <!-- 閉じるときの保存中モーダル -->
    <div v-if="closing" class="fs-saving">
      <div class="fs-saving-box">
        <span class="spinner"></span>
        添削を保存しています…
      </div>
    </div>
    <div class="fs-body">
      <aside class="fs-side"><div id="fs-tools"></div></aside>
      <div ref="fsMain" class="fs-main">
        <div class="fs-canvas" :style="{ height: showAns && hasAnsPane ? 'calc(' + Math.round(splitRatio * 100) + '% - 6px)' : '100%' }">
          <AnnotationEditor
            v-if="fsReady && answerUrl"
            ref="editor"
            :key="'fs' + page.id"
            :image-url="answerUrl"
            :model-value="page.annotations"
            :saving="saving"
            toolbar-target="#fs-tools"
            compact
            fit-to-container
            :auto-save="false"
            @save="onSave"
            @dirty="dirty = $event"
          />
          <div v-else-if="!page.hasAnswer" class="fs-empty">このページの回答はまだ提出されていません。</div>
          <div v-else class="fs-empty">回答画像を読み込み中…</div>
        </div>
        <template v-if="showAns && hasAnsPane">
          <div class="fs-divider" title="ドラッグで境界を移動" @pointerdown="onDivDown" @pointermove="onDivMove" @pointerup="onDivUp" @pointercancel="onDivUp"><span></span></div>
          <div class="fs-answer">
            <div v-if="page.kind === 'pdf' && page.ansPdfId && page.ansPage" class="fs-ans-pdf">
              <div class="fs-ans-title">解答（{{ page.ansPdfTitle }} p.{{ page.ansPage }}）</div>
              <PdfThumb :key="'fsans' + page.id" :pdf-id="page.ansPdfId" :page="page.ansPage" :width="1400" eager />
            </div>
            <div v-else class="fs-ans-pdf">
              <div class="fs-ans-title">解答一覧（{{ vocabAnswers.length }}問）</div>
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
        </template>
      </div>
    </div>
  </div>
  <!-- 採点未入力の警告 -->
  <div v-if="warnOpen" class="sheet-overlay" style="z-index: 90" @click="warnOpen = false">
    <div class="warn-modal" @click.stop>
      <div class="warn-title">採点が入力されていません</div>
      <div class="warn-body">「採点・添削を完了」するには、右側の採点欄に得点と満点を入力してください。完了すると生徒に結果（得点・添削）が表示されます。</div>
      <div class="warn-btns">
        <button class="btn" @click="warnOpen = false">閉じる</button>
        <button class="btn primary" @click="goToScoreInput">採点を入力する</button>
      </div>
    </div>
  </div>
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
/* タブレット・スマホ: 1カラム。ツール・ページリスト（左レール）は表示せず、回答写真のタップで全画面添削を開く */
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
    display: none;
  }
}
/* 閲覧用プレビュー（タブレット） */
.preview {
  position: relative;
  cursor: pointer;
}
.preview :deep(.stage) {
  max-height: 70vh;
  min-height: 200px;
}
.preview-hint {
  position: absolute;
  left: 50%;
  bottom: 12px;
  transform: translateX(-50%);
  padding: 7px 14px;
  border-radius: 999px;
  background: rgba(28, 32, 36, 0.78);
  color: #fff;
  font-size: 12px;
  font-weight: 700;
  pointer-events: none;
  white-space: nowrap;
}
/* 全画面添削 */
.fs {
  position: fixed;
  inset: 0;
  z-index: 80;
  display: flex;
  flex-direction: column;
  background: #1f2328;
  color: #fff;
  overscroll-behavior: none;
  /* ダブルタップ拡大・ピンチ・引っ張って更新をブラウザに任せない */
  touch-action: none;
  -webkit-user-select: none;
  user-select: none;
}
/* スクロールさせる領域だけパンを許可（ダブルタップ拡大・ピンチは許可しない） */
.fs-pages,
.fs-side,
.fs-answer {
  touch-action: pan-x pan-y;
  overscroll-behavior: contain;
}
.fs-saving {
  position: absolute;
  inset: 0;
  z-index: 5;
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgba(20, 24, 32, 0.55);
}
.fs-saving-box {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 18px 26px;
  border-radius: 14px;
  background: #fff;
  color: #1c2024;
  font-size: 14px;
  font-weight: 700;
  box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
}
.spinner {
  width: 20px;
  height: 20px;
  border-radius: 50%;
  border: 3px solid #e3e6ea;
  border-top-color: #1c2024;
  animation: fs-spin 0.8s linear infinite;
}
@keyframes fs-spin {
  to {
    transform: rotate(360deg);
  }
}
.fs-top {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 6px 10px;
  background: #2a2f36;
  flex-shrink: 0;
  padding-top: max(6px, env(safe-area-inset-top));
}
.fs-close {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 7px 10px;
  border: 1px solid #444a53;
  border-radius: 9px;
  background: transparent;
  color: #fff;
  font-size: 12.5px;
  font-weight: 700;
  cursor: pointer;
  white-space: nowrap;
  flex-shrink: 0;
}
.fs-pages {
  flex: 1;
  min-width: 0;
  display: flex;
  gap: 6px;
  overflow-x: auto;
  scrollbar-width: none;
  padding: 2px 0;
}
.fs-pages::-webkit-scrollbar {
  display: none;
}
.fs-page {
  flex-shrink: 0;
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 6px 10px;
  border: 1px solid #444a53;
  border-radius: 999px;
  background: transparent;
  color: #cfd3d9;
  font-size: 12px;
  cursor: pointer;
  max-width: 220px;
}
.fs-page .pn {
  background: rgba(255, 255, 255, 0.14);
}
.fs-page.on {
  background: #fff;
  border-color: #fff;
  color: #1c2024;
}
.fs-page.on .pn {
  background: rgba(0, 0, 0, 0.08);
}
.fs-page .pl {
  max-width: 140px;
}
.fs-ans-btn {
  flex-shrink: 0;
  padding: 7px 12px;
  border: 1px solid #444a53;
  border-radius: 9px;
  background: transparent;
  color: #fff;
  font-size: 12.5px;
  font-weight: 700;
  cursor: pointer;
  white-space: nowrap;
}
.fs-ans-btn.on {
  background: #fff;
  border-color: #fff;
  color: #1c2024;
}
.fs-state {
  flex-shrink: 0;
  font-size: 11px;
  color: #8fd1a5;
  white-space: nowrap;
}
.fs-state.dirty {
  color: #f0c36d;
}
.fs-body {
  flex: 1;
  min-height: 0;
  display: flex;
}
.fs-side {
  width: 56px;
  flex-shrink: 0;
  overflow-y: auto;
  scrollbar-width: none;
  background: #2a2f36;
  padding: 4px 6px;
  padding-left: max(6px, env(safe-area-inset-left));
}
.fs-side::-webkit-scrollbar {
  display: none;
}
.fs-main {
  flex: 1;
  min-width: 0;
  min-height: 0;
  display: flex;
  flex-direction: column;
}
.fs-canvas {
  min-height: 0;
  display: flex;
  overflow: hidden;
}
.fs-canvas :deep(.editor.fill) {
  width: 100%;
  height: 100%;
}
.fs-canvas :deep(.stage) {
  background: #3a3f46;
}
.fs-empty {
  margin: auto;
  color: #9aa1ab;
  font-size: 13px;
}
.fs-divider {
  height: 12px;
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  background: #2a2f36;
  cursor: row-resize;
  touch-action: none;
}
.fs-divider span {
  width: 56px;
  height: 5px;
  border-radius: 3px;
  background: #9aa1ab;
}
.fs-answer {
  flex: 1;
  min-height: 0;
  overflow: auto;
  background: #fff;
  color: #1c2024;
}
.fs-ans-pdf {
  padding: 8px 10px;
}
.fs-ans-title {
  font-size: 12px;
  font-weight: 700;
  color: var(--mut);
  margin-bottom: 6px;
}
.fs-ans-pdf :deep(.thumb) {
  width: 100% !important;
  aspect-ratio: auto;
  border: none;
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
.chip.self {
  background: #efe9fb;
  color: #5b3fa0;
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
.score-box {
  margin-top: 10px;
  padding-top: 10px;
  border-top: 1px solid #f0f1f3;
}
.score-title {
  font-size: 12.5px;
  font-weight: 700;
  margin-bottom: 6px;
}
.score-inputs {
  display: flex;
  align-items: center;
  gap: 6px;
}
.score-inputs input {
  width: 74px;
  padding: 8px 8px;
  border: 1px solid #e3e6ea;
  border-radius: 9px;
  font-size: 16px;
  font-weight: 700;
  text-align: right;
}
.score-inputs .slash {
  font-size: 18px;
  color: var(--faint);
}
.score-inputs .unit {
  font-size: 12px;
  color: var(--mut);
}
.score-inputs .rate {
  margin-left: auto;
  padding: 3px 9px;
  border-radius: 999px;
  color: #fff;
  font-size: 12px;
  font-weight: 700;
}
.score-hint {
  font-size: 10.5px;
  color: var(--faint);
  margin-top: 6px;
  line-height: 1.5;
}
.warn-modal {
  background: #fff;
  border-radius: 14px;
  max-width: 420px;
  width: 100%;
  padding: 20px 20px 16px;
}
.warn-title {
  font-size: 15px;
  font-weight: 700;
  margin-bottom: 8px;
}
.warn-body {
  font-size: 12.5px;
  color: var(--mut);
  line-height: 1.7;
}
.warn-btns {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  margin-top: 16px;
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
