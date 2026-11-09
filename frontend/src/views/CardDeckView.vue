<script setup lang="ts">
/**
 * 学習カードの出題画面（鉄緑会 英文法の覚える表現・例文／合格る確率・合格る計算の問題）。
 * 設定（章・種類・形式・出題対象・出題順・問題数）→ 全画面で 1 枚ずつ解答 → 結果。
 * 回答は 1 枚ごとにサーバーへ記録し（解答時間つき）、忘却曲線の復習状態が更新される。
 * 「今日の復習」は復習期限が来ているカードだけを期限の古い順に出す。1 回の出題は「出題履歴」に残る。
 * 英語学習（EnglishView）・数学学習（MathView）のタブの中で使う。deckKey で束を指定する。
 */
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import CardQuestion, { type CardAnswerResult } from '@/components/CardQuestion.vue'
import HelpTip from '@/components/HelpTip.vue'
import QuizFullscreen from '@/components/QuizFullscreen.vue'
import SessionHistory from '@/components/SessionHistory.vue'
import { cardsApi } from '@/api/cards'
import { sessionsApi } from '@/api/sessions'
import { buildSubQuestions } from '@/lib/cardChoices'
import { openCardCheckSheet } from '@/lib/cardSheet'
import { shuffle } from '@/lib/design'
import { QuestionTimer } from '@/lib/quizTimer'
import { playResultSound } from '@/lib/sound'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'
import type { CardDeck, CardItem, CardKind, CardMode, CardStat } from '@/types'

const props = defineProps<{ deckKey: string; subject: 'english' | 'math' }>()
const ui = useUiStore()
const auth = useAuthStore()

type Phase = 'home' | 'quiz' | 'results'
const phase = ref<Phase>('home')
const loading = ref(true)
const deck = ref<CardDeck | null>(null)
const all = ref<CardItem[]>([])
/** 束ごとのカードのキャッシュ（タブを行き来しても取り直さない） */
const cache: Map<string, CardItem[]> = ((window as unknown as { __cardCache?: Map<string, CardItem[]> }).__cardCache ??= new Map())

async function load(force = false) {
  loading.value = true
  try {
    const decks = await cardsApi.decks(props.subject)
    deck.value = decks.find((d) => d.key === props.deckKey) ?? null
    if (!deck.value) {
      all.value = []
      return
    }
    const cached = cache.get(props.deckKey)
    if (cached && !force) all.value = cached
    else {
      all.value = await cardsApi.items(deck.value.id)
      cache.set(props.deckKey, all.value)
    }
    loadChapterSel()
  } catch {
    ui.notify('カードの取得に失敗しました')
  } finally {
    loading.value = false
  }
}
onMounted(() => {
  window.addEventListener('keydown', onKey)
  load()
})
onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKey)
  timer.dispose()
})
watch(() => props.deckKey, () => load())

const isMath = computed(() => props.subject === 'math')

// ---- 章 ----
const chapters = computed(() => {
  const m = new Map<number, { no: number; name: string; total: number; correct: number }>()
  for (const c of all.value) {
    let ch = m.get(c.chapterNo)
    if (!ch) {
      ch = { no: c.chapterNo, name: c.chapter, total: 0, correct: 0 }
      m.set(c.chapterNo, ch)
    }
    ch.total++
    if (c.stat?.last === true) ch.correct++
  }
  return Array.from(m.values()).sort((a, b) => a.no - b.no)
})
const CHAP_KEY = () => `sm_cards_chap_${props.deckKey}`
const chapSel = ref<Record<number, boolean>>({})
function loadChapterSel() {
  let saved: number[] | null = null
  try {
    const raw = localStorage.getItem(CHAP_KEY())
    if (raw) saved = JSON.parse(raw)
  } catch {
    saved = null
  }
  const set = new Set(Array.isArray(saved) ? saved : [])
  const sel: Record<number, boolean> = {}
  chapters.value.forEach((c) => (sel[c.no] = saved === null ? true : set.has(c.no)))
  chapSel.value = sel
}
watch(
  chapSel,
  () => {
    try {
      localStorage.setItem(CHAP_KEY(), JSON.stringify(chapters.value.filter((c) => chapSel.value[c.no]).map((c) => c.no)))
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
/** 章名の短い表示（「前期 第9週 動名詞(1)」→「第9週 動名詞(1)」） */
function shortChapter(name: string): string {
  return name.replace(/^(前期|後期)\s*/, '')
}

// ---- 種類・形式 ----
const KIND_INFO: Record<CardKind, { label: string; bg: string; fg: string }> = {
  phrase: { label: '表現', bg: '#eef1f6', fg: '#5b6b8c' },
  example: { label: '例文', bg: '#eef7f0', fg: '#3a8a5c' },
  problem: { label: '問題', bg: '#fff4e5', fg: '#b7681a' },
}
const kindCounts = computed(() => {
  const m: Partial<Record<CardKind, number>> = {}
  for (const c of all.value) m[c.kind] = (m[c.kind] ?? 0) + 1
  return m
})
const kinds = computed(() => (['phrase', 'example', 'problem'] as CardKind[]).filter((k) => kindCounts.value[k]))
const kindSel = ref<CardKind[]>([])
function toggleKind(k: CardKind) {
  kindSel.value = kindSel.value.includes(k) ? kindSel.value.filter((x) => x !== k) : [...kindSel.value, k]
}
const MODE_KEY = () => `sm_cards_mode_${props.deckKey}`
const exMode = ref<'translate' | 'fill'>('translate')
const phMode = ref<'ja2en' | 'en2ja'>('ja2en')
try {
  const saved = JSON.parse(localStorage.getItem(MODE_KEY()) ?? 'null')
  if (saved?.ex) exMode.value = saved.ex
  if (saved?.ph) phMode.value = saved.ph
} catch {
  // ignore
}
watch([exMode, phMode], () => {
  try {
    localStorage.setItem(MODE_KEY(), JSON.stringify({ ex: exMode.value, ph: phMode.value }))
  } catch {
    // ignore
  }
})
/** カードごとの出題形式 */
function modeOf(c: CardItem): CardMode {
  if (c.kind === 'problem') return 'image'
  if (c.kind === 'example') return exMode.value
  if (!c.back) return 'en2ja' // 表（画像だけ）などは表現→画像
  return phMode.value
}
const MODE_LABEL: Record<CardMode, string> = { translate: '和訳', fill: '空所補充', ja2en: '意味→表現', en2ja: '表現→意味', image: '問題' }

// ---- 出題対象・順・数 ----
type Target = 'all' | 'unanswered' | 'incorrect' | 'unsure' | 'due'
const target = ref<Target>('all')
const ordered = ref(true)
const count = ref(10)
const wantCount = computed(() => {
  const n = Number(count.value)
  return Number.isFinite(n) && n > 0 ? Math.floor(n) : 0
})
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
function matchTarget(c: CardItem): boolean {
  if (target.value === 'unanswered') return c.stat?.last === null || c.stat?.last === undefined
  if (target.value === 'incorrect') return c.stat?.last === false
  if (target.value === 'unsure') return c.stat?.last === true && !!c.stat.lastUnsure
  if (target.value === 'due') return !!c.stat?.review?.isDue
  return true
}
const pool = computed(() => {
  const list = all.value.filter(
    (c) =>
      (rangeActive.value ? c.no >= Number(range.from) && c.no <= Number(range.to) : chapSel.value[c.chapterNo]) &&
      (!kindSel.value.length || kindSel.value.includes(c.kind)) &&
      matchTarget(c),
  )
  // 復習は期限の古い順
  if (target.value === 'due') return list.slice().sort((a, b) => (a.stat?.review?.dueAt ?? '').localeCompare(b.stat?.review?.dueAt ?? ''))
  return list
})
const startCount = computed(() => (wantCount.value ? Math.min(wantCount.value, pool.value.length) : pool.value.length))
const dueAll = computed(() => all.value.filter((c) => c.stat?.review?.isDue))

// ---- 統計 ----
const stats = computed(() => {
  const total = all.value.length
  const correct = all.value.filter((c) => c.stat?.last === true).length
  const incorrect = all.value.filter((c) => c.stat?.last === false).length
  const answered = correct + incorrect
  return { total, correct, incorrect, unanswered: total - answered, rate: answered ? Math.round((correct / answered) * 100) : 0, due: dueAll.value.length }
})

// ---- チェックシート ----
const sheetBusy = ref(false)
async function openSheet() {
  if (sheetBusy.value || !deck.value) return
  sheetBusy.value = true
  try {
    const marks = await cardsApi.checksheet(deck.value.id)
    const d = new Date()
    const targets = rangeActive.value || selectedChapCount.value < chapters.value.length || kindSel.value.length ? new Set(all.value.filter((c) => (rangeActive.value ? c.no >= Number(range.from) && c.no <= Number(range.to) : chapSel.value[c.chapterNo]) && (!kindSel.value.length || kindSel.value.includes(c.kind))).map((c) => c.id)) : null
    const ok = openCardCheckSheet(deck.value, all.value, targets, marks, auth.user?.name ?? '', `${d.getFullYear()}/${d.getMonth() + 1}/${d.getDate()}`)
    if (!ok) ui.notify('ポップアップがブロックされました。ブラウザ設定で許可してください。')
  } catch {
    ui.notify('チェックシートの作成に失敗しました')
  } finally {
    sheetBusy.value = false
  }
}

// ---- クイズ ----
interface QuizResult extends CardAnswerResult {
  attemptId: number | null
  elapsedMs: number | null
}
const quiz = reactive<{ list: CardItem[]; index: number; results: (QuizResult | undefined)[]; review: boolean }>({ list: [], index: 0, results: [], review: false })
const session = ref<{ pool: CardItem[]; offset: number; size: number } | null>(null)
const cardRef = ref<InstanceType<typeof CardQuestion> | null>(null)
const canSubmit = ref(false)
const peeked = ref(false)
const timer = new QuestionTimer()
const sessionId = ref<number | null>(null)
const history = ref<InstanceType<typeof SessionHistory> | null>(null)

function scrollTop() {
  document.querySelector('.app-scroll')?.scrollTo({ top: 0 })
}
function scrollQuizTop() {
  document.querySelector('.qf-body')?.scrollTo({ top: 0 })
}
async function runQuiz(list: CardItem[], review = false) {
  quiz.list = list
  quiz.index = 0
  quiz.results = []
  quiz.review = review
  canSubmit.value = false
  peeked.value = false
  phase.value = 'quiz'
  timer.start()
  // 出題の記録（セッション）を開始。失敗しても出題は続ける
  sessionId.value = null
  if (deck.value) {
    try {
      const modes = new Set(list.map((c) => modeOf(c)))
      const s = await sessionsApi.start({ kind: 'card', refId: deck.value.id, mode: review ? 'review' : modes.size === 1 ? [...modes][0] : 'mixed' })
      sessionId.value = s.id
    } catch {
      sessionId.value = null
    }
  }
}
function start() {
  const base = ordered.value || target.value === 'due' ? pool.value : shuffle(pool.value)
  const size = wantCount.value || base.length
  const list = base.slice(0, size)
  if (!list.length) {
    ui.notify('対象のカードがありません')
    return
  }
  session.value = (ordered.value || target.value === 'due') && size < base.length ? { pool: base, offset: 0, size } : null
  runQuiz(list, target.value === 'due')
}
/** 今日の復習（期限が来ているカードを古い順に） */
function startReview() {
  const list = dueAll.value.slice().sort((a, b) => (a.stat?.review?.dueAt ?? '').localeCompare(b.stat?.review?.dueAt ?? ''))
  if (!list.length) {
    ui.notify('復習期限のカードはありません')
    return
  }
  session.value = null
  runQuiz(list, true)
}
function startFrom(c: CardItem) {
  const base = listPool.value
  const idx = base.findIndex((x) => x.id === c.id)
  if (idx < 0) return
  const size = wantCount.value || base.length - idx
  session.value = size < base.length - idx ? { pool: base, offset: idx, size } : null
  runQuiz(base.slice(idx, idx + size))
}
const sessionHasMore = computed(() => !!session.value && session.value.offset + session.value.size < session.value.pool.length)
const nextBatchSize = computed(() => (session.value ? Math.min(session.value.size, session.value.pool.length - session.value.offset - session.value.size) : 0))
function continueSession() {
  const s = session.value
  if (!s) return
  const offset = s.offset + s.size
  session.value = { ...s, offset }
  runQuiz(s.pool.slice(offset, offset + s.size))
}

const current = computed(() => quiz.list[quiz.index])
const currentMode = computed<CardMode>(() => (current.value ? modeOf(current.value) : 'translate'))
const currentResult = computed<QuizResult | null>(() => quiz.results[quiz.index] ?? null)
const progress = computed(() => (quiz.list.length ? Math.round(((quiz.index + 1) / quiz.list.length) * 100) : 0))
const isLast = computed(() => quiz.index + 1 >= quiz.list.length)
/** 4 択で出せるカード（選択肢をそろえてから「解答」）。4 択にできないカードは「答えを見る」→ ○／× */
const needsSubmit = computed(() => !!current.value && buildSubQuestions(current.value, currentMode.value, all.value) !== null)
const primaryLabel = computed(() => (currentResult.value ? (isLast.value ? '結果を見る' : '次の問題へ') : needsSubmit.value ? '解答' : peeked.value ? '○／× を選んでください' : '答えを見る'))
const primaryDisabled = computed(() => (currentResult.value ? false : needsSubmit.value ? !canSubmit.value : peeked.value))
function primary() {
  if (currentResult.value) next()
  else cardRef.value?.submit()
}
function giveUp() {
  cardRef.value?.giveUp()
}
function applyStat(itemId: number, stat: CardStat | null) {
  const c = all.value.find((x) => x.id === itemId)
  if (c) c.stat = stat
}
async function onAnswer(payload: { isCorrect: boolean; answer: string | null }) {
  const c = current.value
  if (!c || quiz.results[quiz.index]) return
  const r: QuizResult = { ...payload, overridden: false, unsure: false, attemptId: null, elapsedMs: timer.stop() }
  quiz.results[quiz.index] = r
  playResultSound(payload.isCorrect)
  try {
    const res = await cardsApi.attempt(c.id, { mode: currentMode.value, isCorrect: payload.isCorrect, answer: payload.answer, elapsedMs: r.elapsedMs, sessionId: sessionId.value })
    r.attemptId = res.attemptId
    applyStat(c.id, res.stat)
  } catch {
    r.attemptId = null
  }
}
async function onOverride(isCorrect: boolean) {
  const c = current.value
  const r = quiz.results[quiz.index]
  if (!c || !r) return
  r.isCorrect = isCorrect
  r.overridden = true
  if (!isCorrect) r.unsure = false
  try {
    const res = r.attemptId
      ? await cardsApi.updateAttempt(r.attemptId, { isCorrect })
      : await cardsApi.attempt(c.id, { mode: currentMode.value, isCorrect, answer: null, elapsedMs: r.elapsedMs, sessionId: sessionId.value })
    r.attemptId = res.attemptId
    applyStat(c.id, res.stat)
  } catch {
    // 変更に失敗しても出題は続ける
  }
}
async function onUnsure(unsure: boolean) {
  const c = current.value
  const r = quiz.results[quiz.index]
  if (!c || !r || !r.isCorrect) return
  r.unsure = unsure
  try {
    const res = r.attemptId
      ? await cardsApi.updateAttempt(r.attemptId, { isUnsure: unsure })
      : await cardsApi.attempt(c.id, { mode: currentMode.value, isCorrect: true, isUnsure: unsure, answer: null, elapsedMs: r.elapsedMs, sessionId: sessionId.value })
    r.attemptId = res.attemptId
    applyStat(c.id, res.stat)
  } catch {
    // ignore
  }
}
function next() {
  if (quiz.index + 1 >= quiz.list.length) {
    finishSession()
    phase.value = 'results'
    scrollTop()
    return
  }
  canSubmit.value = false
  peeked.value = false
  quiz.index++
  timer.start()
  scrollQuizTop()
}
function prev() {
  if (quiz.index === 0) return
  canSubmit.value = false
  peeked.value = false
  quiz.index--
  scrollQuizTop()
}
function finishSession() {
  const id = sessionId.value
  sessionId.value = null
  if (id) sessionsApi.finish(id).then(() => history.value?.reload()).catch(() => {})
}
function exitQuiz() {
  finishSession()
  phase.value = 'home'
  session.value = null
  load(true).catch(() => {})
}
function onKey(e: KeyboardEvent) {
  if (phase.value !== 'quiz') return
  const t = e.target as HTMLElement | null
  if (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA')) return
  if (e.key === 'ArrowLeft') prev()
  else if (currentResult.value && (e.key === 'Enter' || e.key === 'ArrowRight')) {
    e.preventDefault()
    next()
  } else if (!currentResult.value && (e.key === ' ' || e.key === 'Enter') && (!needsSubmit.value || canSubmit.value)) {
    e.preventDefault()
    cardRef.value?.submit()
  }
}
const score = computed(() => ({ correct: quiz.results.filter((r) => r?.isCorrect).length, total: quiz.list.length }))
const resultPct = computed(() => (score.value.total ? Math.round((score.value.correct / score.value.total) * 100) : 0))
const resultColor = computed(() => (resultPct.value >= 80 ? '#2e9d62' : resultPct.value >= 50 ? '#d98a2b' : '#cf5563'))
const incorrectList = computed(() => quiz.list.filter((_, i) => quiz.results[i] && !quiz.results[i]!.isCorrect))
const unsureList = computed(() => quiz.list.filter((_, i) => quiz.results[i]?.isCorrect && quiz.results[i]!.unsure))
const avgMs = computed(() => {
  const v = quiz.results.filter((r) => r && r.elapsedMs !== null).map((r) => r!.elapsedMs!)
  return v.length ? Math.round(v.reduce((a, b) => a + b, 0) / v.length) : null
})
function fmtSec(ms: number | null): string {
  if (ms === null) return '—'
  return ms < 10000 ? `${(ms / 1000).toFixed(1)}秒` : `${Math.round(ms / 1000)}秒`
}
function retryIncorrect() {
  session.value = null
  runQuiz([...incorrectList.value, ...unsureList.value])
}
/** 結果一覧・リスト用の表示 */
function preview(c: CardItem): string {
  if (c.kind === 'problem') return `${c.section ?? ''} ${c.front}`.trim()
  if (c.kind === 'phrase' && c.extra?.rows?.length) return `${c.extra.rows.map((r) => r.en).join(' ／ ')}`
  return c.front
}
function statusOf(c: CardItem): { t: string; c: string; key: 'correct' | 'unsure' | 'incorrect' | 'none' } {
  const last = c.stat?.last
  if (last === true && c.stat?.lastUnsure) return { t: '△', c: '#2f6fd6', key: 'unsure' }
  if (last === true) return { t: '○', c: '#2e9d62', key: 'correct' }
  if (last === false) return { t: '×', c: '#cf5563', key: 'incorrect' }
  return { t: '—', c: '#cdd2d9', key: 'none' }
}

// ---- 一覧 ----
const listQ = ref('')
const listChapter = ref(0)
const listStatus = ref<'all' | 'correct' | 'unsure' | 'incorrect' | 'none' | 'due'>('all')
const listLimit = ref(60)
const listPool = computed(() => {
  const term = listQ.value.trim().toLowerCase()
  return all.value.filter(
    (c) =>
      (!listChapter.value || c.chapterNo === listChapter.value) &&
      (listStatus.value === 'all' || (listStatus.value === 'due' ? !!c.stat?.review?.isDue : statusOf(c).key === listStatus.value)) &&
      (!term || `${c.front} ${c.back ?? ''} ${c.section ?? ''} ${c.no}`.toLowerCase().includes(term)),
  )
})
const listRows = computed(() => listPool.value.slice(0, listLimit.value))
watch([listQ, listChapter, listStatus], () => (listLimit.value = 60))
</script>

<template>
  <div>
    <template v-if="phase !== 'results'">
      <div class="row-between" style="margin-bottom: 14px; flex-wrap: wrap; gap: 10px">
        <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap">
          <div style="font-size: 15px; font-weight: 700">{{ deck?.name ?? '' }}</div>
          <span v-if="deck?.studentOnly" class="only">生徒限定</span>
        </div>
        <div style="display: flex; align-items: center; gap: 10px">
          <button class="sheet-btn" :disabled="sheetBusy || !deck" @click="openSheet">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M5 3h14v18H5z" /><path d="M9 8h6M9 12h6M9 16h4" /></svg>
            チェックシート
          </button>
          <HelpTip text="全カードを章の順に並べ、回数欄に解いた日付を入れたチェックシートを印刷用に出力します（章・種類で絞り込んでいるときはその範囲）。&#10;黒=正解、赤=不正解、青=あいまい。同じ日に何度か解いたときは 1 日 1 つにまとめ、1 度でも不正解なら赤になります。" align="right" />
        </div>
      </div>

      <div class="stat-grid">
        <div class="card vstat"><div class="vstat-l">総カード数</div><div class="dm vstat-n">{{ stats.total }}</div></div>
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
        <div class="card" style="padding: 20px">
          <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 14px">
            <div style="font-size: 15px; font-weight: 700">出題設定</div>
            <HelpTip :text="isMath ? '章・出題対象で絞り込み、「開始」で全画面で 1 問ずつ解きます。例題の画像を見て解き、小問ごとに答えを 4 つから選んで「解答」。解答後は「詳細を表示」で解説の画像を見られます。\n答えを読み取れなかった例題は「答えを見る」で解説を表示して ○／× を自分で付けます。読み取った答えが違うときは「正解にする／不正解にする」で直せます。\n正解でも自信がなければ「あいまい」を付けられます（チェックシートでは青の日付）。\n回答は忘却曲線に沿って次の復習日が決まり、期限が来ると「今日の復習」に数えられます。' : '週・種類・形式・出題対象で絞り込み、「開始」で全画面で 1 枚ずつ解答します。すべて 4 択です。\n例文は「和訳」（正しい和訳を選ぶ）か「空所補充」（太字の語に入る語句を選ぶ）、囲みの表現は「意味→表現」か「表現→意味」で、囲み 1 つを 1 枚として行ごとに選びます（全部正解で正解）。\n正解でも自信がなければ「あいまい」を付けられます（チェックシートでは青の日付）。\n回答は忘却曲線に沿って次の復習日が決まり、期限が来ると「今日の復習」に数えられます。'" />
          </div>

          <div class="row-between" style="margin-bottom: 6px">
            <div class="lab" style="margin: 0">{{ isMath ? '章' : '週' }} <span style="color: var(--faint); font-weight: 400">（{{ selectedChapCount }}/{{ chapters.length }}）</span></div>
            <span style="display: flex; gap: 12px">
              <button class="link-btn" @click="allChap">全選択</button>
              <button class="link-btn" style="color: #9aa1ab" @click="clearChap">解除</button>
            </span>
          </div>
          <div class="chap-list" :style="rangeActive ? 'opacity:.45' : ''">
            <button v-for="c in chapters" :key="c.no" class="chap" :class="{ on: chapSel[c.no] }" :title="c.name" @click="toggleChap(c.no)">
              <span class="chap-name">{{ shortChapter(c.name) }}</span>
              <span class="chap-prog dm"><span :style="{ color: chapSel[c.no] ? '#9ed4b3' : '#2e9d62' }">{{ c.correct }}</span>/{{ c.total }}</span>
            </button>
          </div>

          <div class="lab" style="margin-top: 14px">番号で指定（任意）</div>
          <div class="range-row">
            <span style="color: var(--mut)">No.</span>
            <input v-model.number="range.from" type="number" inputmode="numeric" min="1" :max="all.length" class="rng" placeholder="1" />
            <span style="color: var(--mut)">〜</span>
            <input v-model.number="range.to" type="number" inputmode="numeric" min="1" :max="all.length" class="rng" :placeholder="String(all.length)" />
            <button v-if="range.from !== null || range.to !== null" class="link-btn" style="color: #9aa1ab" @click="clearRange">クリア</button>
          </div>

          <template v-if="kinds.length > 1">
            <div class="lab" style="margin-top: 12px">種類</div>
            <div class="chips">
              <button v-for="k in kinds" :key="k" class="chip" :class="{ on: kindSel.includes(k) }" @click="toggleKind(k)">
                {{ KIND_INFO[k].label }}<span class="chip-n">{{ kindCounts[k] ?? 0 }}</span>
              </button>
            </div>
          </template>

          <template v-if="!isMath">
            <div style="display: flex; gap: 14px; margin-top: 12px; flex-wrap: wrap">
              <div v-if="kindCounts.example" style="flex: 1; min-width: 150px">
                <div class="lab">例文の形式</div>
                <div v-seg class="seg3">
                  <button :class="{ on: exMode === 'translate' }" @click="exMode = 'translate'">和訳</button>
                  <button :class="{ on: exMode === 'fill' }" @click="exMode = 'fill'">空所補充</button>
                </div>
              </div>
              <div v-if="kindCounts.phrase" style="flex: 1; min-width: 150px">
                <div class="lab">表現の形式</div>
                <div v-seg class="seg3">
                  <button :class="{ on: phMode === 'ja2en' }" @click="phMode = 'ja2en'">意味→表現</button>
                  <button :class="{ on: phMode === 'en2ja' }" @click="phMode = 'en2ja'">表現→意味</button>
                </div>
              </div>
            </div>
          </template>

          <div class="lab" style="margin-top: 12px">出題対象</div>
          <div v-seg class="seg3 wrap">
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
              <div class="lab">問題数</div>
              <div style="display: flex; align-items: center; gap: 6px">
                <button class="all-btn" :class="{ on: wantCount === 0 }" @click="count = 0">すべて</button>
                <input v-model.number="count" type="number" inputmode="numeric" min="1" :max="pool.length || undefined" class="num-input" @focus="count === 0 && (count = Math.min(10, pool.length) || 1)" />
              </div>
            </div>
          </div>

          <button class="start-btn" :disabled="loading || !pool.length" @click="start">開始（{{ startCount }}{{ isMath ? '問' : '枚' }}<span v-if="wantCount && wantCount < pool.length" style="font-weight: 500; opacity: 0.8"> / 対象 {{ pool.length }}</span>）</button>

          <SessionHistory ref="history" v-if="deck" kind="card" :ref-id="deck.id" :mode-label="(m) => (m === 'review' ? '復習' : m === 'mixed' ? '混合' : MODE_LABEL[m as CardMode] ?? m ?? '')" style="margin-top: 16px" />
        </div>

        <div class="card" style="padding: 18px 20px; min-width: 0">
          <div class="list-tools">
            <div style="position: relative; flex: 1; min-width: 150px">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#9aa1ab" stroke-width="2" style="position: absolute; left: 11px; top: 50%; transform: translateY(-50%)"><circle cx="11" cy="11" r="7" /><path d="M21 21l-4-4" /></svg>
              <input v-model="listQ" placeholder="内容・番号で検索" enterkeyhint="search" class="search" />
            </div>
            <select v-model="listChapter" class="mini-select">
              <option :value="0">{{ isMath ? '全章' : '全週' }}</option>
              <option v-for="c in chapters" :key="c.no" :value="c.no">{{ c.name }}</option>
            </select>
            <select v-model="listStatus" class="mini-select">
              <option value="all">すべて</option>
              <option value="correct">○ 正解</option>
              <option value="unsure">△ あいまい</option>
              <option value="incorrect">× 不正解</option>
              <option value="none">— 未回答</option>
              <option value="due">復習期限</option>
            </select>
          </div>
          <div v-if="loading" style="padding: 30px; text-align: center; color: var(--faint); font-size: 13px">読み込み中…</div>
          <div v-else-if="!deck" style="padding: 30px; text-align: center; color: var(--faint); font-size: 13px">この教材のカードはまだ登録されていません</div>
          <div v-else-if="!listRows.length" style="padding: 30px; text-align: center; color: var(--faint); font-size: 13px">該当するカードはありません</div>
          <div v-else class="qlist">
            <button v-for="c in listRows" :key="c.id" class="qrow" @click="startFrom(c)">
              <span class="q-no dm">{{ c.no }}</span>
              <span class="q-fmt" :style="{ background: KIND_INFO[c.kind].bg, color: KIND_INFO[c.kind].fg }">{{ KIND_INFO[c.kind].label }}</span>
              <span class="q-text" :class="{ dm: c.kind !== 'problem' }">{{ preview(c) }}</span>
              <span v-if="c.stat?.review?.isDue" class="q-due">復習</span>
              <span class="q-st" :style="{ color: statusOf(c).c }">{{ statusOf(c).t }}</span>
            </button>
            <button v-if="listPool.length > listRows.length" class="more-btn" @click="listLimit += 100">さらに表示（残り {{ listPool.length - listRows.length }}）</button>
          </div>
        </div>
      </div>
    </template>

    <!-- ============ RESULTS ============ -->
    <template v-else>
      <div style="max-width: 560px; margin: 0 auto">
        <div class="card" style="padding: 30px 26px; text-align: center; margin-bottom: 16px; border-radius: 18px">
          <div style="font-size: 13px; color: var(--faint); margin-bottom: 8px">{{ quiz.review ? '今日の復習 結果' : '結果' }}</div>
          <div class="dm" :style="{ fontSize: '48px', fontWeight: 700, color: resultColor, lineHeight: 1 }">{{ resultPct }}<span style="font-size: 22px">%</span></div>
          <div style="font-size: 14px; color: var(--mut); margin-top: 6px">{{ score.correct }} / {{ score.total }} 正解<span v-if="avgMs !== null">　・　1問あたり {{ fmtSec(avgMs) }}</span></div>
          <div v-if="session" style="font-size: 12px; color: var(--faint); margin-top: 8px">
            {{ session.offset + 1 }}〜{{ Math.min(session.offset + session.size, session.pool.length) }} 枚目 / 全 {{ session.pool.length }} 枚
          </div>
        </div>

        <div v-if="incorrectList.length" class="card" style="padding: 18px 20px; margin-bottom: 16px">
          <div style="font-size: 13px; font-weight: 700; margin-bottom: 6px">不正解（{{ incorrectList.length }}）</div>
          <div v-for="c in incorrectList" :key="c.id" class="res-row">
            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 3px">
              <span class="q-no dm">No.{{ c.no }}</span>
              <span class="q-fmt" :style="{ background: KIND_INFO[c.kind].bg, color: KIND_INFO[c.kind].fg }">{{ KIND_INFO[c.kind].label }}</span>
              <span style="font-size: 11px; color: var(--faint)">{{ c.chapter }}</span>
            </div>
            <div :class="{ dm: c.kind !== 'problem' }" style="font-size: 13.5px; line-height: 1.6">{{ preview(c) }}</div>
            <div v-if="c.back && c.kind !== 'problem'" style="font-size: 13px; color: var(--primary); font-weight: 600; margin-top: 2px">{{ c.back }}</div>
            <div v-if="c.stat?.review" style="font-size: 11px; color: var(--faint); margin-top: 2px">次回: {{ c.stat.review.intervalLabel }}後</div>
          </div>
        </div>
        <div v-else class="card" style="padding: 18px 20px; margin-bottom: 16px; border: 1.5px solid #9ed4b3; font-size: 14px; font-weight: 700; color: #1f7a45">🎉 全問正解です！</div>

        <div v-if="unsureList.length" class="card" style="padding: 18px 20px; margin-bottom: 16px">
          <div style="font-size: 13px; font-weight: 700; margin-bottom: 6px; color: #2f5fc4">あいまい（{{ unsureList.length }}）</div>
          <div v-for="c in unsureList" :key="c.id" class="res-row">
            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 3px">
              <span class="q-no dm">No.{{ c.no }}</span>
              <span class="q-fmt" :style="{ background: KIND_INFO[c.kind].bg, color: KIND_INFO[c.kind].fg }">{{ KIND_INFO[c.kind].label }}</span>
              <span style="font-size: 11px; color: var(--faint)">{{ c.chapter }}</span>
            </div>
            <div :class="{ dm: c.kind !== 'problem' }" style="font-size: 13.5px; line-height: 1.6">{{ preview(c) }}</div>
            <div v-if="c.back && c.kind !== 'problem'" style="font-size: 13px; color: var(--primary); font-weight: 600; margin-top: 2px">{{ c.back }}</div>
            <div v-if="c.stat?.review" style="font-size: 11px; color: var(--faint); margin-top: 2px">次回: {{ c.stat.review.intervalLabel }}後</div>
          </div>
        </div>

        <div style="display: flex; gap: 10px; flex-wrap: wrap">
          <button v-if="incorrectList.length || unsureList.length" class="next-btn" @click="retryIncorrect">{{ incorrectList.length && unsureList.length ? '不正解・あいまいだけ再挑戦' : incorrectList.length ? '不正解だけ再挑戦' : 'あいまいだけ再挑戦' }}</button>
          <button v-if="sessionHasMore" class="next-btn" @click="continueSession">続ける（次の {{ nextBatchSize }}）</button>
          <button class="prev-btn" style="flex: 1" @click="exitQuiz">設定に戻る</button>
        </div>
      </div>
    </template>

    <!-- ============ QUIZ（全画面） ============ -->
    <Transition name="ui-modal">
      <QuizFullscreen v-if="phase === 'quiz' && current" :progress="progress" :counter="`${quiz.index + 1}/${quiz.list.length}`" :fixed="!needsSubmit" @close="exitQuiz">
        <CardQuestion
          ref="cardRef"
          :key="current.id + ':' + currentMode"
          :item="current"
          :mode="currentMode"
          :result="currentResult"
          :pool="all"
          @answer="onAnswer"
          @can-submit="canSubmit = $event"
          @unsure="onUnsure"
          @override="onOverride"
          @revealed="peeked = true"
        />
        <div v-if="currentResult && current.stat?.review" class="next-chip">次の復習: {{ current.stat.review.intervalLabel }}後<span v-if="currentResult.elapsedMs !== null">　・　解答 {{ fmtSec(currentResult.elapsedMs) }}</span></div>
        <template #footer>
          <button class="qf-ghost" :disabled="quiz.index === 0" @click="prev">前へ</button>
          <button v-if="!currentResult" class="qf-ghost" @click="giveUp">わからない</button>
          <button class="qf-main" :disabled="primaryDisabled" @click="primary">{{ primaryLabel }}</button>
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
.only {
  padding: 2px 8px;
  border-radius: 99px;
  background: #fff4e5;
  color: #b7681a;
  font-size: 11px;
  font-weight: 700;
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
  text-align: left;
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
  max-height: 220px;
  overflow-y: auto;
}
.chap {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 6px 10px;
  border: 1px solid #e3e6ea;
  border-radius: 9px;
  background: #fff;
  color: #4b5563;
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
}
.chap.on {
  background: #1c2024;
  border-color: #1c2024;
  color: #fff;
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
  outline: none;
}
.chips {
  display: flex;
  flex-wrap: wrap;
  gap: 7px;
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
  font-weight: 600;
  cursor: pointer;
}
.chip.on {
  background: #1c2024;
  border-color: #1c2024;
  color: #fff;
}
.chip-n {
  font-size: 10.5px;
  opacity: 0.7;
}
.seg3 {
  display: flex;
  border: 1px solid #e3e6ea;
  border-radius: 10px;
  overflow: hidden;
  background: #fff;
}
.seg3.wrap {
  flex-wrap: wrap;
}
.seg3 button {
  flex: 1;
  min-width: 70px;
  padding: 9px 6px;
  border: none;
  background: transparent;
  color: var(--mut);
  font-size: 12.5px;
  font-weight: 600;
  cursor: pointer;
  white-space: nowrap;
}
.seg3 button.on {
  background: #1c2024;
  color: #fff;
}
.num-input {
  width: 72px;
  padding: 8px 10px;
  border: 1px solid #e3e6ea;
  border-radius: 9px;
  font-size: 13px;
  outline: none;
}
.all-btn {
  padding: 8px 12px;
  border: 1px solid #e3e6ea;
  border-radius: 9px;
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
  min-height: 48px;
  border: none;
  border-radius: 12px;
  background: #3b50cc;
  color: #fff;
  font-size: 14.5px;
  font-weight: 700;
  cursor: pointer;
}
.start-btn:disabled {
  opacity: 0.45;
  cursor: default;
}
.list-tools {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
  margin-bottom: 12px;
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
  padding: 8px 10px;
  border: 1px solid #e3e6ea;
  border-radius: 9px;
  font-size: 12.5px;
  background: #fff;
  max-width: 200px;
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
  padding: 9px 6px;
  border: none;
  border-top: 1px solid #f4f5f7;
  background: transparent;
  text-align: left;
  cursor: pointer;
  font-family: inherit;
}
.qrow:hover {
  background: #fafbfc;
}
.q-no {
  min-width: 34px;
  font-size: 12px;
  font-weight: 700;
  color: #9aa1ab;
  text-align: right;
}
.q-fmt {
  flex-shrink: 0;
  padding: 2px 7px;
  border-radius: 99px;
  font-size: 10.5px;
  font-weight: 700;
}
.q-text {
  flex: 1;
  min-width: 0;
  font-size: 13px;
  color: #1c2024;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.q-due {
  flex-shrink: 0;
  padding: 1px 6px;
  border-radius: 99px;
  background: #fff4e5;
  color: #b7681a;
  font-size: 10px;
  font-weight: 700;
}
.q-st {
  width: 18px;
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
  min-height: 46px;
  padding: 10px 18px;
  border: 1px solid #e3e6ea;
  border-radius: 12px;
  background: #fff;
  color: #4b5563;
  font-size: 14px;
  font-weight: 600;
  cursor: pointer;
}
.next-btn {
  flex: 1;
  min-height: 46px;
  padding: 10px 18px;
  border: none;
  border-radius: 12px;
  background: #3b50cc;
  color: #fff;
  font-size: 14px;
  font-weight: 700;
  cursor: pointer;
}
.next-chip {
  margin-top: 10px;
  text-align: center;
  font-size: 12px;
  color: var(--faint);
}
</style>
