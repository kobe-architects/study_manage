<script setup lang="ts">
/**
 * 英文法・語法 問題演習（スクランブル英文法・語法、英文法ファーストステップ）。
 * series で問題集のまとまりを切り替える（scramble = スクランブルの 7 冊、first_step = 高校リード問題集 英文法ファーストステップ）。
 * 設定（問題集・章・形式・重要度／区分・出題対象・出題順・問題数）→ 全画面で 1 問ずつ解答 → 結果。
 * 出題中は QuizFullscreen（上: 進み具合、中: 問題、下: 固定の選択肢とボタン）。解説は下部の「解説」でモーダル表示。
 * 問題は 1 冊分をまとめて取得し、絞り込み・出題順は画面側で決める。回答は 1 問ごとにサーバーへ記録する。
 */
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import FirstStepCard from '@/components/FirstStepCard.vue'
import GrammarExplain from '@/components/GrammarExplain.vue'
import GrammarQuestionCard, { type GrammarAnswerResult } from '@/components/GrammarQuestionCard.vue'
import HelpTip from '@/components/HelpTip.vue'
import QuizFullscreen from '@/components/QuizFullscreen.vue'
import SessionHistory from '@/components/SessionHistory.vue'
import { grammarApi } from '@/api/grammar'
import { sessionsApi } from '@/api/sessions'
import { shuffle } from '@/lib/design'
import { QuestionTimer } from '@/lib/quizTimer'
import { CIRCLED, FORMATS, FORMAT_INFO, chapterParts, questionPreview, statusOf } from '@/lib/grammar'
import { openFirstStepCheckSheet } from '@/lib/firstStepSheet'
import { openGrammarCheckSheet } from '@/lib/grammarSheet'
import { playResultSound } from '@/lib/sound'
import { useAuthStore } from '@/stores/auth'
import { useGrammarStore } from '@/stores/grammar'
import { useUiStore } from '@/stores/ui'
import type { GrammarBookKey, GrammarFormat, GrammarQuestion } from '@/types'

const props = defineProps<{ series?: 'scramble' | 'first_step' }>()
const SERIES_BOOKS: Record<'scramble' | 'first_step', GrammarBookKey[]> = {
  scramble: ['grammar', 'usage', 'vocab', 'idiom', 'conversation', 'pronunciation', 'special'],
  first_step: ['first_step'],
}
const series = computed(() => props.series ?? 'scramble')
const isFirstStep = computed(() => series.value === 'first_step')
const g = useGrammarStore()
const ui = useUiStore()
const auth = useAuthStore()
/** この画面で扱う問題集（series に属するものだけ） */
const books = computed(() => g.books.filter((b) => SERIES_BOOKS[series.value].includes(b.key)))

type Phase = 'home' | 'quiz' | 'results'
const phase = ref<Phase>('home')
const loading = ref(true)

onMounted(async () => {
  window.addEventListener('keydown', onKey)
  // 別のまとまりの問題集が選ばれていたら、このまとまりの最初の問題集にする
  if (!SERIES_BOOKS[series.value].includes(g.book)) g.selectBook(SERIES_BOOKS[series.value][0]!)
  try {
    await Promise.all([g.fetchBooks(), g.fetchQuestions()])
    loadChapterSel()
  } catch {
    ui.notify('問題の取得に失敗しました')
  } finally {
    loading.value = false
  }
})
onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKey)
  timer.dispose()
})

async function selectBook(key: GrammarBookKey) {
  if (g.book === key) return
  g.selectBook(key)
  loading.value = true
  try {
    await g.fetchQuestions(key)
  } catch {
    ui.notify('問題の取得に失敗しました')
  } finally {
    loading.value = false
  }
  loadChapterSel()
  clearRange()
  impSel.value = []
  listChapter.value = 0
  listLimit.value = 60
}

const all = computed(() => g.current)

// ---- 章 ----
interface ChapterRow {
  no: number
  name: string
  full: string
  total: number
  correct: number
  incorrect: number
}
const chapters = computed<ChapterRow[]>(() => {
  const m = new Map<number, ChapterRow>()
  for (const q of all.value) {
    let c = m.get(q.chapterNo)
    if (!c) {
      c = { no: q.chapterNo, name: chapterParts(q.chapter).name, full: q.chapter, total: 0, correct: 0, incorrect: 0 }
      m.set(q.chapterNo, c)
    }
    c.total++
    if (q.stat?.last === true) c.correct++
    else if (q.stat?.last === false) c.incorrect++
  }
  return Array.from(m.values()).sort((a, b) => a.no - b.no)
})

const chapSel = ref<Record<number, boolean>>({})
const CHAP_KEY = (book: string) => `sm_grammar_chapters_${book}`
function loadChapterSel() {
  let saved: number[] | null = null
  try {
    const raw = localStorage.getItem(CHAP_KEY(g.book))
    if (raw) saved = JSON.parse(raw)
  } catch {
    saved = null
  }
  const sel: Record<number, boolean> = {}
  const set = new Set(Array.isArray(saved) ? saved : [])
  // 初回は全章を選択
  chapters.value.forEach((c) => (sel[c.no] = saved === null ? true : set.has(c.no)))
  chapSel.value = sel
}
watch(
  chapSel,
  () => {
    try {
      localStorage.setItem(CHAP_KEY(g.book), JSON.stringify(chapters.value.filter((c) => chapSel.value[c.no]).map((c) => c.no)))
    } catch {
      // 記憶できない環境では無視
    }
  },
  { deep: true },
)
const selectedChapCount = computed(() => chapters.value.filter((c) => chapSel.value[c.no]).length)
function toggleChap(no: number) {
  chapSel.value = { ...chapSel.value, [no]: !chapSel.value[no] }
}
function allChap() {
  const sel: Record<number, boolean> = {}
  chapters.value.forEach((c) => (sel[c.no] = true))
  chapSel.value = sel
}
function clearChap() {
  chapSel.value = {}
}

// ---- 絞り込み ----
const fmtSel = ref<GrammarFormat[]>([])
function toggleFmt(f: GrammarFormat) {
  fmtSel.value = fmtSel.value.includes(f) ? fmtSel.value.filter((x) => x !== f) : [...fmtSel.value, f]
}
const fmtCounts = computed(() => {
  const m: Record<string, number> = {}
  for (const q of all.value) m[q.format] = (m[q.format] ?? 0) + 1
  return m
})
/** 重要度（語法のみ。無印は「無印」として選べる）。ファーストステップでは区分（STEP 1 / STEP 2 / まとめのテスト / 総合問題）を同じ欄で選ぶ */
const IMP_ORDER = ['STEP 1', 'STEP 2', 'まとめのテスト', '総合問題', '超頻', '基本・頻出', '頻出', '頻出・発展', '基本', '発展', '無印']
const importances = computed(() => {
  const set = new Set<string>()
  for (const q of all.value) set.add(q.importance ?? '無印')
  const list = Array.from(set)
  return list.length > 1 ? list.sort((a, b) => IMP_ORDER.indexOf(a) - IMP_ORDER.indexOf(b)) : []
})
const impSel = ref<string[]>([])
function toggleImp(v: string) {
  impSel.value = impSel.value.includes(v) ? impSel.value.filter((x) => x !== v) : [...impSel.value, v]
}
type Target = 'all' | 'unanswered' | 'incorrect' | 'unsure' | 'due'
const target = ref<Target>('all')
const ordered = ref(true)
const count = ref(10)
const wantCount = computed(() => {
  const n = Number(count.value)
  return Number.isFinite(n) && n > 0 ? Math.floor(n) : 0
})

// 番号で範囲指定（任意。指定時は章の選択より優先）
const range = reactive<{ from: number | null; to: number | null }>({ from: null, to: null })
const rangeActive = computed(() => {
  const f = Number(range.from)
  const t = Number(range.to)
  return Number.isInteger(f) && Number.isInteger(t) && f >= 1 && t >= f
})
function clearRange() {
  range.from = null
  range.to = null
}
const noMin = computed(() => all.value[0]?.no ?? 1)
const noMax = computed(() => all.value[all.value.length - 1]?.no ?? 1)

function matchTarget(q: GrammarQuestion): boolean {
  if (target.value === 'unanswered') return q.stat?.last === null || q.stat?.last === undefined
  if (target.value === 'incorrect') return q.stat?.last === false
  if (target.value === 'unsure') return q.stat?.last === true && !!q.stat.lastUnsure
  if (target.value === 'due') return !!q.stat?.review?.isDue
  return true
}
/** 忘却曲線の復習期限が来ている問題（期限の古い順） */
const dueAll = computed(() => all.value.filter((q) => q.stat?.review?.isDue).sort((a, b) => (a.stat?.review?.dueAt ?? '').localeCompare(b.stat?.review?.dueAt ?? '')))

// ---- チェックシート（文法・語法 725 問の解いた日を、付属のチェックシートと同じ並びで印刷用に出力） ----
const sheetBusy = ref(false)
async function openSheet() {
  if (sheetBusy.value) return
  sheetBusy.value = true
  try {
    const marks = await grammarApi.checksheet(isFirstStep.value ? 'first_step' : undefined)
    const d = new Date()
    const date = `${d.getFullYear()}/${d.getMonth() + 1}/${d.getDate()}`
    const ok = isFirstStep.value ? openFirstStepCheckSheet(all.value, marks, auth.user?.name ?? '', date) : openGrammarCheckSheet(marks, auth.user?.name ?? '', date)
    if (!ok) ui.notify('ポップアップがブロックされました。ブラウザ設定で許可してください。')
  } catch {
    ui.notify('チェックシートの作成に失敗しました')
  } finally {
    sheetBusy.value = false
  }
}
/** 設定に合う問題（番号順。復習期限は期限の古い順） */
const pool = computed(() => {
  const list = all.value.filter(
    (q) =>
      (rangeActive.value ? q.no >= Number(range.from) && q.no <= Number(range.to) : chapSel.value[q.chapterNo]) &&
      (!fmtSel.value.length || fmtSel.value.includes(q.format)) &&
      (!impSel.value.length || impSel.value.includes(q.importance ?? '無印')) &&
      matchTarget(q),
  )
  return target.value === 'due' ? list.slice().sort((a, b) => (a.stat?.review?.dueAt ?? '').localeCompare(b.stat?.review?.dueAt ?? '')) : list
})
const startCount = computed(() => batchFrom(pool.value, 0).length)

// ---- 統計 ----
const stats = computed(() => {
  const total = all.value.length
  const correct = all.value.filter((q) => q.stat?.last === true).length
  const incorrect = all.value.filter((q) => q.stat?.last === false).length
  const answered = correct + incorrect
  return { total, correct, incorrect, unanswered: total - answered, rate: answered ? Math.round((correct / answered) * 100) : 0, due: dueAll.value.length }
})

// ---- クイズ ----
// 出題の単位（ユニット）: スクランブルは 1 問、ファーストステップは大問（連続する小問をまとめて 1 画面で解答）。
// quiz.list は問題の平らな並び、quiz.results は同じ添字、quiz.index はユニットの番号。
interface QuizResult extends GrammarAnswerResult {
  attemptId: number | null
  /** 解答までの時間（ミリ秒） */
  elapsedMs: number | null
}
const quiz = reactive<{ list: GrammarQuestion[]; index: number; results: (QuizResult | undefined)[]; review: boolean }>({ list: [], index: 0, results: [], review: false })
/** 大問のキー（「p.5 STEP 1・1A〜C (1)」→「p.5 STEP 1・1A〜C」） */
function groupKeyOf(q: GrammarQuestion): string {
  return (q.source ?? '').replace(/\s\(\d+\)$/, '')
}
/** 問題の並びをユニット（開始位置と長さ）に分ける */
function unitsOf(list: GrammarQuestion[]): { start: number; len: number }[] {
  if (!isFirstStep.value) return list.map((_, i) => ({ start: i, len: 1 }))
  const out: { start: number; len: number }[] = []
  let i = 0
  while (i < list.length) {
    const k = groupKeyOf(list[i]!)
    let j = i + 1
    while (j < list.length && groupKeyOf(list[j]!) === k) j++
    out.push({ start: i, len: j - i })
    i = j
  }
  return out
}
/** offset から 1 回分（問題数の設定ぶん。ファーストステップは大問の数）を取り出す */
function batchFrom(list: GrammarQuestion[], offset: number): GrammarQuestion[] {
  if (!wantCount.value) return list.slice(offset)
  if (!isFirstStep.value) return list.slice(offset, offset + wantCount.value)
  const us = unitsOf(list).filter((u) => u.start >= offset).slice(0, wantCount.value)
  const last = us[us.length - 1]
  return last ? list.slice(offset, last.start + last.len) : []
}
/** ランダム: ファーストステップは大問の単位で混ぜる */
function shuffled(list: GrammarQuestion[]): GrammarQuestion[] {
  if (!isFirstStep.value) return shuffle(list)
  return shuffle(unitsOf(list).map((u) => list.slice(u.start, u.start + u.len))).flat()
}
const units = computed(() => unitsOf(quiz.list))
const unit = computed(() => units.value[quiz.index])
/** 解答時間の計測と、1 回の出題（セッション）の記録 */
const timer = new QuestionTimer()
const sessionId = ref<number | null>(null)
const history = ref<InstanceType<typeof SessionHistory> | null>(null)
async function startSession(review: boolean) {
  sessionId.value = null
  try {
    const s = await sessionsApi.start({ kind: 'grammar', refKey: g.book, mode: review ? 'review' : 'quiz' })
    sessionId.value = s.id
  } catch {
    sessionId.value = null
  }
}
function finishSession() {
  const id = sessionId.value
  sessionId.value = null
  if (id) sessionsApi.finish(id).then(() => history.value?.reload()).catch(() => {})
}
/** 「順番」×問題数指定のとき、続きを出すための情報（offset・size は問題の数） */
const session = ref<{ pool: GrammarQuestion[]; offset: number; size: number } | null>(null)
const cardRef = ref<InstanceType<typeof GrammarQuestionCard> | null>(null)
const fsRef = ref<InstanceType<typeof FirstStepCard> | null>(null)
const canSubmit = ref(false) // 整序・空所補充・大問で「解答」を押せるか（カードから通知）
const explainOpen = ref(false)
/** ファーストステップ: 「解答」を押して答えを表示したユニット */
const revealedUnits = ref<Record<number, boolean>>({})

function scrollTop() {
  document.querySelector('.app-scroll')?.scrollTo({ top: 0 })
}
function scrollQuizTop() {
  document.querySelector('.qf-body')?.scrollTo({ top: 0 })
}
function runQuiz(list: GrammarQuestion[], review = false) {
  quiz.list = list
  quiz.index = 0
  quiz.results = []
  quiz.review = review
  revealedUnits.value = {}
  unitElapsed = null
  explainOpen.value = false
  canSubmit.value = false
  phase.value = 'quiz'
  timer.start()
  startSession(review)
}
function start() {
  const base = ordered.value || target.value === 'due' ? pool.value : shuffled(pool.value)
  const list = batchFrom(base, 0)
  if (!list.length) {
    ui.notify('対象の問題がありません')
    return
  }
  session.value = (ordered.value || target.value === 'due') && list.length < base.length ? { pool: base, offset: 0, size: list.length } : null
  runQuiz(list, target.value === 'due')
}
/** 今日の復習（忘却曲線で期限が来ている問題を古い順に） */
function startReview() {
  const list = dueAll.value
  if (!list.length) {
    ui.notify('復習期限の問題はありません')
    return
  }
  session.value = null
  runQuiz(list, true)
}
/** 一覧の問題をタップ: その問題から順番に（設定の絞り込みは使わず、一覧の絞り込みで）。ファーストステップは大問の先頭から */
function startFrom(q: GrammarQuestion) {
  // ファーストステップは検索で絞っていても大問の小問が揃うように、全問の並びから始める
  const base = isFirstStep.value ? all.value : listPool.value
  let idx = base.findIndex((x) => x.id === q.id)
  if (idx < 0) return
  if (isFirstStep.value) {
    while (idx > 0 && groupKeyOf(base[idx - 1]!) === groupKeyOf(q)) idx--
  }
  const list = batchFrom(base, idx)
  session.value = idx + list.length < base.length ? { pool: base, offset: idx, size: list.length } : null
  runQuiz(list)
}
const sessionHasMore = computed(() => !!session.value && session.value.offset + session.value.size < session.value.pool.length)
const nextBatch = computed(() => (session.value ? batchFrom(session.value.pool, session.value.offset + session.value.size) : []))
const nextBatchSize = computed(() => (isFirstStep.value ? unitsOf(nextBatch.value).length : nextBatch.value.length))
function continueSession() {
  const s = session.value
  if (!s) return
  const offset = s.offset + s.size
  const list = batchFrom(s.pool, offset)
  session.value = { ...s, offset, size: list.length }
  runQuiz(list)
}

const current = computed(() => (unit.value ? quiz.list[unit.value.start] : undefined))
/** ユニットの問題（ファーストステップは大問の小問）と、その結果（問題 id →） */
const unitQuestions = computed(() => (unit.value ? quiz.list.slice(unit.value.start, unit.value.start + unit.value.len) : []))
const unitResults = computed(() => {
  const m: Record<number, QuizResult | undefined> = {}
  unitQuestions.value.forEach((q, k) => {
    m[q.id] = quiz.results[(unit.value?.start ?? 0) + k]
  })
  return m
})
const currentResult = computed<QuizResult | null>(() => (unit.value ? (quiz.results[unit.value.start] ?? null) : null))
/** 解答済み（次へ進める状態）: スクランブルは結果あり、ファーストステップは答えを表示して全小問に ○／× が付いた */
const unitRevealed = computed(() => !!revealedUnits.value[quiz.index])
const answered = computed(() => (isFirstStep.value ? unitRevealed.value : !!currentResult.value))
const unitComplete = computed(() => (isFirstStep.value ? unitRevealed.value && unitQuestions.value.every((q) => !!unitResults.value[q.id]) : !!currentResult.value))
const progress = computed(() => (units.value.length ? Math.round(((quiz.index + 1) / units.value.length) * 100) : 0))
const isLast = computed(() => quiz.index + 1 >= units.value.length)
/** 整序・空所補充は「解答」ボタンで採点（選択・誤り指摘はタップで即採点）。画像出題は、記入（answers あり）は「解答」、自己判定は「答えを見る」。大問は常に「解答」 */
const imageTyped = computed(() => current.value?.format === 'image' && !!current.value.answers?.length)
const imageSelfJudge = computed(() => !isFirstStep.value && current.value?.format === 'image' && !(current.value.choiceCount && current.value.answerIndex !== null) && !imageTyped.value)
const needsSubmit = computed(() => isFirstStep.value || current.value?.format === 'order' || current.value?.format === 'fill' || imageTyped.value || imageSelfJudge.value)
const primaryLabel = computed(() => (answered.value ? (isLast.value ? '結果を見る' : '次の問題へ') : imageSelfJudge.value ? '答えを見る' : needsSubmit.value ? '解答' : '次の問題へ'))
const primaryDisabled = computed(() => (answered.value ? !unitComplete.value : needsSubmit.value ? !canSubmit.value : true))
function primary() {
  if (answered.value) next()
  else if (needsSubmit.value) (isFirstStep.value ? fsRef.value : cardRef.value)?.submit()
}
function giveUp() {
  ;(isFirstStep.value ? fsRef.value : cardRef.value)?.giveUp()
}

/** 大問の解答時間: 最初の小問の記録時に計り、同じ大問の残りの小問にも同じ値を付ける */
let unitElapsed: number | null = null
async function recordAnswer(q: GrammarQuestion, flat: number, payload: { isCorrect: boolean; answer: string | null }) {
  if (quiz.results[flat]) return
  const ms = unitElapsed ?? timer.stop()
  unitElapsed = ms
  const r: QuizResult = { ...payload, overridden: false, unsure: false, attemptId: null, elapsedMs: ms }
  quiz.results[flat] = r
  r.attemptId = await g.recordAttempt(q, payload.isCorrect, payload.answer, r.elapsedMs, sessionId.value)
}
async function onAnswer(payload: { isCorrect: boolean; answer: string | null }) {
  const q = current.value
  if (!q || !unit.value) return
  playResultSound(payload.isCorrect)
  await recordAnswer(q, unit.value.start, payload)
}
/** ファーストステップ: 小問ごとの解答（音は大問の全部が正解なら正解の音） */
async function onUnitAnswer(q: GrammarQuestion, payload: { isCorrect: boolean; answer: string | null }) {
  const k = unitQuestions.value.findIndex((x) => x.id === q.id)
  if (k < 0 || !unit.value) return
  await recordAnswer(q, unit.value.start + k, payload)
}
function revealUnit() {
  revealedUnits.value = { ...revealedUnits.value, [quiz.index]: true }
  const rs = unitQuestions.value.map((q) => unitResults.value[q.id]).filter((r): r is QuizResult => !!r)
  if (rs.length) playResultSound(rs.every((r) => r.isCorrect))
}
async function overrideFor(q: GrammarQuestion, flat: number, isCorrect: boolean) {
  const r = quiz.results[flat]
  if (!r) return
  r.isCorrect = isCorrect
  r.overridden = true
  if (!isCorrect) r.unsure = false // 不正解にしたら「あいまい」は外れる
  r.attemptId = await g.overrideAttempt(q, r.attemptId, isCorrect)
}
async function onOverride(isCorrect: boolean) {
  if (current.value && unit.value) await overrideFor(current.value, unit.value.start, isCorrect)
}
async function onUnitOverride(q: GrammarQuestion, isCorrect: boolean) {
  const k = unitQuestions.value.findIndex((x) => x.id === q.id)
  if (k >= 0 && unit.value) await overrideFor(q, unit.value.start + k, isCorrect)
}
/** 正解のときの「あいまい」（自信がない）の付け外し。チェックシートでは青の日付になる。大問では正解だった小問すべて */
async function onUnsure(unsure: boolean) {
  if (!unit.value) return
  for (let k = 0; k < unitQuestions.value.length; k++) {
    const q = unitQuestions.value[k]!
    const r = quiz.results[unit.value.start + k]
    if (!r || !r.isCorrect || r.unsure === unsure) continue
    r.unsure = unsure
    r.attemptId = await g.setUnsure(q, r.attemptId, unsure)
  }
}
function next() {
  explainOpen.value = false
  if (quiz.index + 1 >= units.value.length) {
    finishSession()
    phase.value = 'results'
    scrollTop()
    return
  }
  canSubmit.value = false
  quiz.index++
  unitElapsed = null
  timer.start()
  scrollQuizTop()
}
function prev() {
  if (quiz.index === 0) return
  explainOpen.value = false
  canSubmit.value = false
  quiz.index--
  scrollQuizTop()
}
function exitQuiz() {
  finishSession()
  phase.value = 'home'
  explainOpen.value = false
  session.value = null
  g.fetchBooks().catch(() => {})
}
/** 結果画面用: 1 問あたりの解答時間 */
const avgMs = computed(() => {
  const v = quiz.results.filter((r) => r && r.elapsedMs !== null).map((r) => r!.elapsedMs!)
  return v.length ? Math.round(v.reduce((a, b) => a + b, 0) / v.length) : null
})
function fmtSec(ms: number | null): string {
  if (ms === null) return '—'
  return ms < 10000 ? `${(ms / 1000).toFixed(1)}秒` : `${Math.round(ms / 1000)}秒`
}
/** キーボード（PC）: 解答後は Enter／→ で次へ、← で前へ（選択や解答はカード側。解説が開いている間は何もしない） */
function onKey(e: KeyboardEvent) {
  if (phase.value !== 'quiz') return
  const t = e.target as HTMLElement | null
  if (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA')) return
  // 解説モーダルは「閉じる」でだけ閉じる（キーでは閉じない）
  if (explainOpen.value) return
  if (e.key === 'ArrowLeft') prev()
  else if (answered.value && !primaryDisabled.value && (e.key === 'Enter' || e.key === 'ArrowRight')) {
    e.preventDefault()
    next()
  }
}
const score = computed(() => ({ correct: quiz.results.filter((r) => r?.isCorrect).length, total: quiz.list.length }))
const resultPct = computed(() => (score.value.total ? Math.round((score.value.correct / score.value.total) * 100) : 0))
const resultColor = computed(() => (resultPct.value >= 80 ? '#2e9d62' : resultPct.value >= 50 ? '#d98a2b' : '#cf5563'))
const incorrectList = computed(() => quiz.list.filter((_, i) => quiz.results[i] && !quiz.results[i]!.isCorrect))
/** 正解だったが「あいまい」を付けた問題 */
const unsureList = computed(() => quiz.list.filter((_, i) => quiz.results[i]?.isCorrect && quiz.results[i]!.unsure))
function retryIncorrect() {
  session.value = null
  const list = [...incorrectList.value, ...unsureList.value]
  // ファーストステップは同じ大問の小問が続くように番号順に
  runQuiz(isFirstStep.value ? list.slice().sort((a, b) => a.no - b.no) : list)
}
/** 結果一覧用の正解表示 */
function answerLabel(q: GrammarQuestion): string {
  if (q.format === 'choice') return `${CIRCLED[q.answerIndex ?? 0]} ${q.answerText ?? ''}`
  if (q.format === 'error') return `${CIRCLED[q.answerIndex ?? 0]} ${q.answerText ?? ''}`
  if (q.format === 'order') return (q.answerText ?? '').replace(/[()]/g, '')
  if (q.format === 'image') {
    if (q.answerIndex !== null && q.choiceCount) return q.choices?.[q.answerIndex] ?? CIRCLED[q.answerIndex] ?? ''
    if (q.answers?.length) return q.answers.map((a) => a.join(' / ')).join('，')
    return q.answerText ?? '解答欄を参照'
  }
  return (q.answers ?? []).map((a) => a.join(' / ')).join('，')
}

// ---- 一覧 ----
const listQ = ref('')
const listChapter = ref(0) // 0 = 全章
const listStatus = ref<'all' | 'correct' | 'unsure' | 'incorrect' | 'none'>('all')
const listLimit = ref(60)
/** 一覧の絞り込み結果（番号順）。一覧の問題をタップしたときの出題元 */
const listPool = computed(() => {
  const term = listQ.value.trim().toLowerCase()
  return all.value.filter(
    (q) =>
      (!listChapter.value || q.chapterNo === listChapter.value) &&
      (listStatus.value === 'all' || statusOf(q).key === listStatus.value) &&
      (!term || `${q.question} ${q.translation ?? ''} ${q.instruction ?? ''} ${q.no}`.toLowerCase().includes(term)),
  )
})
const listRows = computed(() => listPool.value.slice(0, listLimit.value))
watch([listQ, listChapter, listStatus], () => (listLimit.value = 60))
</script>

<template>
  <div>
    <!-- ============ HOME（出題中は全画面の下に残しておく） ============ -->
    <template v-if="phase !== 'results'">
      <!-- 問題集の切替 -->
      <div class="row-between" style="margin-bottom: 14px; flex-wrap: wrap; gap: 10px">
        <!-- 問題集が 1 冊だけ（ファーストステップ）のときは切替を出さず、教材名を見出しにする -->
        <div v-if="books.length === 1" class="book-title">
          <div class="book-name">英文法ファーストステップ</div>
          <div class="book-sub">高校リード問題集 ・ {{ books[0]?.total ?? 0 }}問</div>
        </div>
        <div v-else v-seg class="book-switch" role="tablist" aria-label="問題集">
          <button v-for="b in books" :key="b.key" class="opt" :class="{ on: g.book === b.key }" role="tab" :aria-selected="g.book === b.key" @click="selectBook(b.key)">
            {{ b.name }}<span class="cnt">{{ b.total }}問</span>
          </button>
        </div>
        <div style="display: flex; align-items: center; gap: 10px">
          <div v-if="!isFirstStep" style="font-size: 12px; color: var(--faint)">Scramble 英文法・語法</div>
          <button class="sheet-btn" :disabled="sheetBusy" @click="openSheet">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M5 3h14v18H5z" /><path d="M9 8h6M9 12h6M9 16h4" /></svg>
            チェックシート
          </button>
          <HelpTip :text="(isFirstStep ? 'STEP 1・STEP 2・まとめのテスト・総合問題の全問を、章 → 区分（ページ）→ 小問の並びで印刷用に出力します。' : '文法・語法 全725問を、教材付属のチェックシートと同じ並び（章・Theme・連番・形式・区分）で印刷用に出力します。') + '\n回数欄には解いた日付が入ります。黒=正解、赤=不正解、青=あいまい（正解だったが自信がないとき、解答後に「あいまい」を付けた問題）。\n同じ日に何度か解いたときは 1 日 1 つにまとめ、1 度でも不正解なら赤になります。'" />
        </div>
      </div>

      <!-- 統計 -->
      <div class="stat-grid">
        <div class="card vstat"><div class="vstat-l">総問題数</div><div class="dm vstat-n">{{ stats.total }}</div></div>
        <div class="card vstat"><div class="vstat-l">正解</div><div class="dm vstat-n" style="color: #2e9d62">{{ stats.correct }}</div></div>
        <div class="card vstat"><div class="vstat-l">不正解</div><div class="dm vstat-n" style="color: #cf5563">{{ stats.incorrect }}</div></div>
        <div class="card vstat"><div class="vstat-l">未回答</div><div class="dm vstat-n" style="color: #9aa1ab">{{ stats.unanswered }}</div></div>
        <div class="card vstat"><div class="vstat-l">正答率</div><div class="dm vstat-n">{{ stats.rate }}<span style="font-size: 12px">%</span></div></div>
        <button class="card vstat due" :disabled="!stats.due" @click="startReview">
          <div class="vstat-l">今日の復習</div>
          <div class="dm vstat-n" style="color: #d98a2b">{{ stats.due }}</div>
          <div class="due-go">{{ stats.due ? '始める ›' : '期限なし' }}</div>
        </button>
      </div>

      <div class="home-grid">
        <!-- 設定 -->
        <div class="card" style="padding: 20px">
          <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 14px">
            <div style="font-size: 15px; font-weight: 700">出題設定</div>
            <HelpTip :text="(isFirstStep ? '章・区分（STEP 1／STEP 2／まとめのテスト／総合問題）・出題対象で絞り込み、「開始」で全画面で 1 問ずつ解答します。教材のページを画像で表示し、記号選択はア〜エをタップ、記入は答えを入力して「解答」、書きかえ・和訳などは「答えを見る」で答えを表示して ○／× を自分で付けます。採点が合わないときは「解説」の中で正解にする／不正解にする。' : '章・形式・重要度・出題対象で絞り込み、「開始」で全画面で 1 問ずつ解答します。') + '\n一覧の問題をタップすると、その問題から順番に始められます。\n選択・誤り指摘は下の選択肢をタップで即採点。整序は下の語句を順にタップ（並べた語句はドラッグで前後に移動）、空所補充は空所に入力して「解答」。\n正解でも自信がなければ「あいまい」を付けられます（一覧では △、チェックシートでは青の日付）。\n解答後は下の「解説」で和訳・出典などを表示。\nPC: 1〜5 キーで選択、Enter で解答・次へ、← → で前後、Backspace で整序を 1 つ戻す。'" />
          </div>

          <div class="row-between" style="margin-bottom: 6px">
            <div class="lab" style="margin: 0">章 <span style="color: var(--faint); font-weight: 400">（{{ selectedChapCount }}/{{ chapters.length }}）</span></div>
            <span style="display: flex; gap: 12px">
              <button class="link-btn" @click="allChap">全選択</button>
              <button class="link-btn" style="color: #9aa1ab" @click="clearChap">解除</button>
            </span>
          </div>
          <div class="chap-list" :style="rangeActive ? 'opacity:.45' : ''">
            <button v-for="c in chapters" :key="c.no" class="chap" :class="{ on: chapSel[c.no] }" :title="c.full" @click="toggleChap(c.no)">
              <span class="chap-no dm">{{ c.no }}</span>
              <span class="chap-name">{{ c.name }}</span>
              <span class="chap-prog dm"><span :style="{ color: chapSel[c.no] ? '#9ed4b3' : '#2e9d62' }">{{ c.correct }}</span>/{{ c.total }}</span>
            </button>
          </div>

          <div class="lab" style="margin-top: 14px">番号で指定（任意）</div>
          <div class="range-row">
            <span style="color: var(--mut)">No.</span>
            <input v-model.number="range.from" type="number" inputmode="numeric" :min="noMin" :max="noMax" class="rng" :placeholder="String(noMin)" />
            <span style="color: var(--mut)">〜</span>
            <input v-model.number="range.to" type="number" inputmode="numeric" :min="noMin" :max="noMax" class="rng" :placeholder="String(noMax)" />
            <button v-if="range.from !== null || range.to !== null" class="link-btn" style="color: #9aa1ab" @click="clearRange">クリア</button>
          </div>

          <div v-if="Object.keys(fmtCounts).length > 1" class="lab" style="margin-top: 12px">形式</div>
          <div v-if="Object.keys(fmtCounts).length > 1" class="chips">
            <button v-for="f in FORMATS" :key="f" class="chip" :class="{ on: fmtSel.includes(f) }" :disabled="!fmtCounts[f]" @click="toggleFmt(f)">
              {{ FORMAT_INFO[f].label }}<span class="chip-n">{{ fmtCounts[f] ?? 0 }}</span>
            </button>
          </div>

          <template v-if="importances.length">
            <div class="lab" style="margin-top: 12px">{{ isFirstStep ? '区分' : '重要度' }}</div>
            <div class="chips">
              <button v-for="v in importances" :key="v" class="chip" :class="{ on: impSel.includes(v) }" @click="toggleImp(v)">{{ v }}</button>
            </div>
          </template>

          <div class="lab" style="margin-top: 12px">出題対象</div>
          <div v-seg class="seg3">
            <button :class="{ on: target === 'all' }" @click="target = 'all'">すべて</button>
            <button :class="{ on: target === 'unanswered' }" @click="target = 'unanswered'">未回答</button>
            <button :class="{ on: target === 'incorrect' }" @click="target = 'incorrect'">不正解</button>
            <button :class="{ on: target === 'unsure' }" @click="target = 'unsure'">あいまい</button>
            <button :class="{ on: target === 'due' }" @click="target = 'due'">復習期限</button>
          </div>

          <div style="display: flex; gap: 14px; margin: 12px 0 14px">
            <div style="flex: 1">
              <div class="lab">出題順</div>
              <div v-seg class="seg3">
                <button :class="{ on: ordered }" @click="ordered = true">順番</button>
                <button :class="{ on: !ordered }" @click="ordered = false">ランダム</button>
              </div>
            </div>
            <div style="flex: 1">
              <div class="lab">{{ isFirstStep ? '大問の数' : '問題数' }}</div>
              <div style="display: flex; align-items: center; gap: 6px">
                <button class="all-btn" :class="{ on: wantCount === 0 }" @click="count = 0">すべて</button>
                <input v-model.number="count" type="number" inputmode="numeric" min="1" :max="pool.length || undefined" class="num-input" @focus="count === 0 && (count = Math.min(10, pool.length) || 1)" />
              </div>
            </div>
          </div>

          <button class="start-btn" :disabled="loading || !pool.length" @click="start">開始（{{ startCount }}問<span v-if="startCount < pool.length" style="font-weight: 500; opacity: 0.8"> / 対象 {{ pool.length }}問</span>）</button>

          <SessionHistory ref="history" kind="grammar" :ref-key="g.book" :mode-label="(m) => (m === 'review' ? '復習' : '出題')" style="margin-top: 16px" />
        </div>

        <!-- 問題一覧 -->
        <div class="card" style="padding: 18px 20px; min-width: 0">
          <div class="list-tools">
            <div style="position: relative; flex: 1; min-width: 150px">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#9aa1ab" stroke-width="2" style="position: absolute; left: 11px; top: 50%; transform: translateY(-50%)"><circle cx="11" cy="11" r="7" /><path d="M21 21l-4-4" /></svg>
              <input v-model="listQ" placeholder="問題文・和訳・番号で検索" enterkeyhint="search" class="search" />
            </div>
            <select v-model="listChapter" class="mini-select">
              <option :value="0">全章</option>
              <option v-for="c in chapters" :key="c.no" :value="c.no">{{ c.full }}</option>
            </select>
            <select v-model="listStatus" class="mini-select">
              <option value="all">すべて</option>
              <option value="correct">○ 正解</option>
              <option value="unsure">△ あいまい</option>
              <option value="incorrect">× 不正解</option>
              <option value="none">— 未回答</option>
            </select>
          </div>
          <div v-if="loading" style="padding: 30px; text-align: center; color: var(--faint); font-size: 13px">読み込み中…</div>
          <div v-else-if="!listRows.length" style="padding: 30px; text-align: center; color: var(--faint); font-size: 13px">該当する問題はありません</div>
          <div v-else class="qlist">
            <button v-for="q in listRows" :key="q.id" class="qrow" @click="startFrom(q)">
              <span class="q-no dm">{{ q.no }}</span>
              <span class="q-fmt" :style="{ background: FORMAT_INFO[q.format].bg, color: FORMAT_INFO[q.format].fg }">{{ FORMAT_INFO[q.format].short }}</span>
              <span class="q-text dm">{{ questionPreview(q) }}</span>
              <span class="q-st" :style="{ color: statusOf(q).c }">{{ statusOf(q).t }}</span>
            </button>
            <button v-if="listPool.length > listRows.length" class="more-btn" @click="listLimit += 100">さらに表示（残り {{ listPool.length - listRows.length }} 問）</button>
          </div>
        </div>
      </div>
    </template>

    <!-- ============ RESULTS ============ -->
    <template v-else>
      <div style="max-width: 560px; margin: 0 auto">
        <div class="card" style="padding: 30px 26px; text-align: center; margin-bottom: 16px; border-radius: 18px">
          <div style="font-size: 13px; color: var(--faint); margin-bottom: 8px">結果</div>
          <div class="dm" :style="{ fontSize: '48px', fontWeight: 700, color: resultColor, lineHeight: 1 }">{{ resultPct }}<span style="font-size: 22px">%</span></div>
          <div style="font-size: 14px; color: var(--mut); margin-top: 6px">{{ score.correct }} / {{ score.total }} 問 正解<span v-if="avgMs !== null">　・　1問あたり {{ fmtSec(avgMs) }}</span></div>
          <div v-if="session" style="font-size: 12px; color: var(--faint); margin-top: 8px">
            {{ session.offset + 1 }}〜{{ Math.min(session.offset + session.size, session.pool.length) }} 問目 / 全 {{ session.pool.length }} 問
          </div>
        </div>

        <div v-if="incorrectList.length" class="card" style="padding: 18px 20px; margin-bottom: 16px">
          <div style="font-size: 13px; font-weight: 700; margin-bottom: 6px">不正解の問題（{{ incorrectList.length }}）</div>
          <div v-for="q in incorrectList" :key="q.id" class="res-row">
            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 3px">
              <span class="q-no dm">No.{{ q.no }}</span>
              <span class="q-fmt" :style="{ background: FORMAT_INFO[q.format].bg, color: FORMAT_INFO[q.format].fg }">{{ q.formatLabel }}</span>
              <span style="font-size: 11px; color: var(--faint)">{{ q.chapter }}</span>
            </div>
            <div class="dm" style="font-size: 13.5px; line-height: 1.6">{{ questionPreview(q) }}</div>
            <div style="font-size: 13px; color: var(--primary); font-weight: 600; margin-top: 2px" class="dm">{{ answerLabel(q) }}</div>
          </div>
        </div>
        <div v-else class="card" style="padding: 18px 20px; margin-bottom: 16px; border: 1.5px solid #9ed4b3; font-size: 14px; font-weight: 700; color: #1f7a45">🎉 全問正解です！</div>

        <div v-if="unsureList.length" class="card" style="padding: 18px 20px; margin-bottom: 16px">
          <div style="font-size: 13px; font-weight: 700; margin-bottom: 6px; color: #2f5fc4">あいまいな問題（{{ unsureList.length }}）</div>
          <div v-for="q in unsureList" :key="q.id" class="res-row">
            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 3px">
              <span class="q-no dm">No.{{ q.no }}</span>
              <span class="q-fmt" :style="{ background: FORMAT_INFO[q.format].bg, color: FORMAT_INFO[q.format].fg }">{{ q.formatLabel }}</span>
              <span style="font-size: 11px; color: var(--faint)">{{ q.chapter }}</span>
            </div>
            <div class="dm" style="font-size: 13.5px; line-height: 1.6">{{ questionPreview(q) }}</div>
            <div style="font-size: 13px; color: var(--primary); font-weight: 600; margin-top: 2px" class="dm">{{ answerLabel(q) }}</div>
          </div>
        </div>

        <div style="display: flex; gap: 10px; flex-wrap: wrap">
          <button v-if="incorrectList.length || unsureList.length" class="next-btn" @click="retryIncorrect">{{ incorrectList.length && unsureList.length ? '不正解・あいまいだけ再挑戦' : incorrectList.length ? '不正解だけ再挑戦' : 'あいまいだけ再挑戦' }}</button>
          <button v-if="sessionHasMore" class="next-btn" @click="continueSession">続ける（次の {{ nextBatchSize }} {{ isFirstStep ? '大問' : '問' }}）</button>
          <button class="prev-btn" style="flex: 1" @click="exitQuiz">設定に戻る</button>
        </div>
      </div>
    </template>

    <!-- ============ QUIZ（全画面） ============ -->
    <Transition name="ui-modal">
      <QuizFullscreen
        v-if="phase === 'quiz' && current"
        :progress="progress"
        :counter="`${quiz.index + 1}/${units.length}`"
        :fixed="!isFirstStep && current.format !== 'fill' && !imageTyped"
        @close="exitQuiz"
      >
        <FirstStepCard
          v-if="isFirstStep"
          ref="fsRef"
          :key="current.id"
          :questions="unitQuestions"
          :results="unitResults"
          :revealed="unitRevealed"
          @answer="onUnitAnswer"
          @override="onUnitOverride"
          @unsure="onUnsure"
          @can-submit="canSubmit = $event"
          @reveal="revealUnit"
        />
        <GrammarQuestionCard
          v-else
          ref="cardRef"
          :key="current.id"
          :question="current"
          :result="currentResult"
          @answer="onAnswer"
          @can-submit="canSubmit = $event"
          @unsure="onUnsure"
        />
        <div v-if="answered && current.stat?.review" class="next-chip">次の復習: {{ current.stat.review.intervalLabel }}後<span v-if="currentResult && currentResult.elapsedMs !== null">　・　解答 {{ fmtSec(currentResult.elapsedMs) }}</span></div>
        <template #footer>
          <button class="qf-ghost" :disabled="quiz.index === 0" @click="prev">前へ</button>
          <button v-if="answered" class="qf-ghost" @click="explainOpen = true">解説</button>
          <button v-else class="qf-ghost" @click="giveUp">わからない</button>
          <button class="qf-main" :disabled="primaryDisabled" @click="primary">{{ primaryLabel }}</button>
        </template>
        <template #modals>
          <Transition name="ui-modal">
            <GrammarExplain v-if="explainOpen" :question="current" :result="isFirstStep ? null : currentResult" :simple="isFirstStep" @close="explainOpen = false" @override="onOverride" @unsure="onUnsure" />
          </Transition>
        </template>
      </QuizFullscreen>
    </Transition>
  </div>
</template>

<style scoped>
.row-between {
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.book-switch {
  display: inline-flex;
  flex-wrap: wrap;
  border: 1px solid #e3e6ea;
  border-radius: 10px;
  overflow: hidden;
  background: #fff;
}
.book-switch .opt {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 8px 14px;
  border: none;
  background: #fff;
  font-size: 13px;
  font-weight: 600;
  color: var(--mut);
  cursor: pointer;
  white-space: nowrap;
}
.book-switch .opt + .opt {
  border-left: 1px solid #e3e6ea;
}
.book-switch .opt.on {
  background: #1c2024;
  color: #fff;
}
.cnt {
  font-size: 10px;
  font-weight: 500;
  opacity: 0.7;
}
.book-title {
  display: flex;
  align-items: baseline;
  gap: 10px;
  flex-wrap: wrap;
}
.book-name {
  font-size: 17px;
  font-weight: 800;
  color: var(--ink);
}
.book-sub {
  font-size: 12px;
  color: var(--faint);
}
.sheet-btn {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  min-height: 34px;
  padding: 6px 12px;
  border: 1px solid #e3e6ea;
  border-radius: 9px;
  background: #fff;
  color: #1c2024;
  font-size: 12.5px;
  font-weight: 600;
  cursor: pointer;
  white-space: nowrap;
}
.sheet-btn:disabled {
  opacity: 0.5;
  cursor: default;
}
.stat-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(108px, 1fr));
  gap: 12px;
  margin-bottom: 18px;
}
.vstat {
  padding: 13px 15px;
}
.vstat-l {
  font-size: 11px;
  color: var(--faint);
  margin-bottom: 3px;
}
.vstat-n {
  font-size: 22px;
  font-weight: 700;
}
button.vstat.due {
  cursor: pointer;
  border-color: #f0d0a8;
  background: #fffaf3;
  text-align: left;
  font-family: inherit;
}
button.vstat.due:disabled {
  cursor: default;
  border-color: var(--line);
  background: #fff;
}
.due-go {
  font-size: 11px;
  font-weight: 700;
  color: #d98a2b;
  margin-top: 2px;
}
button.vstat.due:disabled .due-go {
  color: var(--faint);
  font-weight: 500;
}
.next-chip {
  margin-top: 10px;
  text-align: center;
  font-size: 12px;
  color: var(--faint);
}
.home-grid {
  display: grid;
  grid-template-columns: minmax(0, 400px) minmax(0, 1fr);
  gap: 18px;
  align-items: start;
}
@media (max-width: 860px) {
  .home-grid {
    grid-template-columns: minmax(0, 1fr);
  }
}
.lab {
  font-size: 12px;
  color: var(--mut);
  font-weight: 500;
  margin-bottom: 6px;
}
.link-btn {
  border: none;
  background: none;
  color: #3b50cc;
  font-size: 11px;
  cursor: pointer;
  font-weight: 600;
}
.chap-list {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}
.chap {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 6px 10px 6px 8px;
  border: 1px solid #e3e6ea;
  border-radius: 9px;
  background: #fff;
  color: #4b5563;
  font-size: 12.5px;
  font-weight: 600;
  cursor: pointer;
}
.chap.on {
  background: #1c2024;
  border-color: #1c2024;
  color: #fff;
}
.chap-no {
  min-width: 18px;
  font-size: 11px;
  font-weight: 700;
  color: #9aa1ab;
  text-align: right;
}
.chap.on .chap-no {
  color: #c3c7cf;
}
.chap-prog {
  font-size: 10.5px;
  font-weight: 500;
  color: #9aa1ab;
}
.chap.on .chap-prog {
  color: #c3c7cf;
}
.range-row {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 12.5px;
}
.rng {
  width: 78px;
  padding: 8px 10px;
  border: 1px solid #e3e6ea;
  border-radius: 9px;
  font-size: 13px;
  text-align: right;
  background: #fff;
}
.chips {
  display: flex;
  gap: 7px;
  flex-wrap: wrap;
}
.chip {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 6px 11px;
  border: 1px solid #e3e6ea;
  border-radius: 99px;
  background: #fff;
  color: #6b7280;
  font-size: 12px;
  font-weight: 500;
  cursor: pointer;
}
.chip.on {
  border-color: #1c2024;
  background: #1c2024;
  color: #fff;
}
.chip:disabled {
  opacity: 0.4;
  cursor: default;
}
.chip-n {
  font-size: 10px;
  opacity: 0.7;
}
.seg3 {
  display: flex;
  background: #f1f3f5;
  border-radius: 9px;
  padding: 3px;
}
.seg3 button {
  flex: 1;
  padding: 7px;
  border: none;
  border-radius: 7px;
  cursor: pointer;
  font-size: 12.5px;
  font-weight: 600;
  background: transparent;
  color: var(--mut);
}
.seg3 button.on {
  background: #fff;
  color: var(--ink);
}
.num-input {
  width: 64px;
  padding: 7px 10px;
  border: 1px solid #e3e6ea;
  border-radius: 8px;
  font-size: 13px;
  text-align: right;
  outline: none;
}
.all-btn {
  padding: 7px 10px;
  border: 1px solid #e3e6ea;
  border-radius: 8px;
  background: #fff;
  color: #6b7280;
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
}
.all-btn.on {
  background: #1c2024;
  border-color: #1c2024;
  color: #fff;
}
.start-btn {
  width: 100%;
  padding: 13px;
  border: none;
  border-radius: 11px;
  background: #1c2024;
  color: #fff;
  font-size: 14px;
  font-weight: 700;
  cursor: pointer;
}
.start-btn:disabled {
  opacity: 0.4;
  cursor: default;
}
.list-tools {
  display: flex;
  gap: 8px;
  margin-bottom: 12px;
  flex-wrap: wrap;
}
.search {
  width: 100%;
  padding: 8px 11px 8px 34px;
  border: 1px solid #e3e6ea;
  border-radius: 9px;
  font-size: 13px;
  outline: none;
}
.mini-select {
  padding: 7px 10px;
  border: 1px solid #e3e6ea;
  border-radius: 8px;
  font-size: 12.5px;
  cursor: pointer;
  outline: none;
  background: #fff;
  max-width: 100%;
}
.qlist {
  display: flex;
  flex-direction: column;
}
.qrow {
  display: flex;
  align-items: center;
  gap: 10px;
  width: 100%;
  min-height: 44px;
  padding: 8px 6px;
  border: none;
  border-top: 1px solid #f0f1f3;
  background: transparent;
  text-align: left;
  cursor: pointer;
  color: var(--ink);
}
.qrow:hover {
  background: #f8f9fb;
}
.q-no {
  min-width: 34px;
  font-size: 12px;
  font-weight: 700;
  color: #9aa1ab;
}
.q-fmt {
  flex-shrink: 0;
  font-size: 10.5px;
  font-weight: 700;
  padding: 2px 7px;
  border-radius: 99px;
}
.q-text {
  flex: 1;
  min-width: 0;
  font-size: 13.5px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.q-st {
  flex-shrink: 0;
  width: 22px;
  text-align: center;
  font-weight: 700;
  font-size: 14px;
}
.more-btn {
  margin-top: 8px;
  padding: 10px;
  border: 1px dashed #d8dce1;
  border-radius: 10px;
  background: #fff;
  color: #3b50cc;
  font-size: 12.5px;
  font-weight: 600;
  cursor: pointer;
}
.res-row {
  padding: 10px 0;
  border-top: 1px solid #f4f5f7;
}
.prev-btn {
  padding: 11px 16px;
  border: 1px solid #e3e6ea;
  border-radius: 11px;
  background: #fff;
  color: #6b7280;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
}
.next-btn {
  flex: 1;
  padding: 11px;
  border: none;
  border-radius: 11px;
  background: #1c2024;
  color: #fff;
  font-size: 14px;
  font-weight: 700;
  cursor: pointer;
}
</style>
