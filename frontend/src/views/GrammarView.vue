<script setup lang="ts">
/**
 * 英文法・語法 問題演習（スクランブル英文法・語法）。
 * 設定（問題集・章・形式・重要度・出題対象・出題順・問題数）→ 1 問ずつ画面で解答 → 結果。
 * 問題は 1 冊分をまとめて取得し、絞り込み・出題順は画面側で決める。回答は 1 問ごとにサーバーへ記録する。
 */
import { computed, onMounted, reactive, ref, watch } from 'vue'
import GrammarQuestionCard, { type GrammarAnswerResult } from '@/components/GrammarQuestionCard.vue'
import HelpTip from '@/components/HelpTip.vue'
import { shuffle } from '@/lib/design'
import { CIRCLED, FORMATS, FORMAT_INFO, chapterParts, questionPreview, statusOf } from '@/lib/grammar'
import { playResultSound } from '@/lib/sound'
import { useGrammarStore } from '@/stores/grammar'
import { useUiStore } from '@/stores/ui'
import type { GrammarBookKey, GrammarFormat, GrammarQuestion } from '@/types'

const g = useGrammarStore()
const ui = useUiStore()

type Phase = 'home' | 'quiz' | 'results'
const phase = ref<Phase>('home')
const loading = ref(true)

onMounted(async () => {
  try {
    await Promise.all([g.fetchBooks(), g.fetchQuestions()])
    loadChapterSel()
  } catch {
    ui.notify('問題の取得に失敗しました')
  } finally {
    loading.value = false
  }
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
/** 重要度（語法のみ。無印は「無印」として選べる） */
const IMP_ORDER = ['超頻', '基本・頻出', '頻出', '頻出・発展', '基本', '発展', '無印']
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
type Target = 'all' | 'unanswered' | 'incorrect'
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
  return true
}
/** 設定に合う問題（番号順） */
const pool = computed(() =>
  all.value.filter(
    (q) =>
      (rangeActive.value ? q.no >= Number(range.from) && q.no <= Number(range.to) : chapSel.value[q.chapterNo]) &&
      (!fmtSel.value.length || fmtSel.value.includes(q.format)) &&
      (!impSel.value.length || impSel.value.includes(q.importance ?? '無印')) &&
      matchTarget(q),
  ),
)
const startCount = computed(() => (wantCount.value ? Math.min(wantCount.value, pool.value.length) : pool.value.length))

// ---- 統計 ----
const stats = computed(() => {
  const total = all.value.length
  const correct = all.value.filter((q) => q.stat?.last === true).length
  const incorrect = all.value.filter((q) => q.stat?.last === false).length
  const answered = correct + incorrect
  return { total, correct, incorrect, unanswered: total - answered, rate: answered ? Math.round((correct / answered) * 100) : 0 }
})

// ---- クイズ ----
interface QuizResult extends GrammarAnswerResult {
  attemptId: number | null
}
const quiz = reactive<{ list: GrammarQuestion[]; index: number; results: (QuizResult | undefined)[] }>({ list: [], index: 0, results: [] })
/** 「順番」×問題数指定のとき、続きを出すための情報 */
const session = ref<{ pool: GrammarQuestion[]; offset: number; size: number } | null>(null)

function scrollTop() {
  document.querySelector('.app-scroll')?.scrollTo({ top: 0 })
}
function runQuiz(list: GrammarQuestion[]) {
  quiz.list = list
  quiz.index = 0
  quiz.results = []
  phase.value = 'quiz'
  scrollTop()
}
function start() {
  const base = ordered.value ? pool.value : shuffle(pool.value)
  const size = wantCount.value || base.length
  const list = base.slice(0, size)
  if (!list.length) {
    ui.notify('対象の問題がありません')
    return
  }
  session.value = ordered.value && size < base.length ? { pool: base, offset: 0, size } : null
  runQuiz(list)
}
/** 一覧の問題をタップ: その問題から順番に（設定の絞り込みは使わず、一覧の絞り込みで） */
function startFrom(q: GrammarQuestion) {
  const base = listPool.value
  const idx = base.findIndex((x) => x.id === q.id)
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
const currentResult = computed<QuizResult | null>(() => quiz.results[quiz.index] ?? null)
const progress = computed(() => (quiz.list.length ? Math.round(((quiz.index + 1) / quiz.list.length) * 100) : 0))

async function onAnswer(payload: { isCorrect: boolean; answer: string | null }) {
  const q = current.value
  if (!q || quiz.results[quiz.index]) return
  const r: QuizResult = { ...payload, overridden: false, attemptId: null }
  quiz.results[quiz.index] = r
  playResultSound(payload.isCorrect)
  r.attemptId = await g.recordAttempt(q, payload.isCorrect, payload.answer)
}
async function onOverride(isCorrect: boolean) {
  const q = current.value
  const r = quiz.results[quiz.index]
  if (!q || !r) return
  r.isCorrect = isCorrect
  r.overridden = true
  r.attemptId = await g.overrideAttempt(q, r.attemptId, isCorrect)
}
function next() {
  if (quiz.index + 1 >= quiz.list.length) {
    phase.value = 'results'
    scrollTop()
    return
  }
  quiz.index++
  scrollTop()
}
function prev() {
  if (quiz.index > 0) quiz.index--
}
function exitQuiz() {
  phase.value = 'home'
  session.value = null
  g.fetchBooks().catch(() => {})
}
const score = computed(() => ({ correct: quiz.results.filter((r) => r?.isCorrect).length, total: quiz.list.length }))
const resultPct = computed(() => (score.value.total ? Math.round((score.value.correct / score.value.total) * 100) : 0))
const resultColor = computed(() => (resultPct.value >= 80 ? '#2e9d62' : resultPct.value >= 50 ? '#d98a2b' : '#cf5563'))
const incorrectList = computed(() => quiz.list.filter((_, i) => quiz.results[i] && !quiz.results[i]!.isCorrect))
function retryIncorrect() {
  session.value = null
  runQuiz(incorrectList.value.slice())
}
/** 結果一覧用の正解表示 */
function answerLabel(q: GrammarQuestion): string {
  if (q.format === 'choice') return `${CIRCLED[q.answerIndex ?? 0]} ${q.answerText ?? ''}`
  if (q.format === 'error') return `${CIRCLED[q.answerIndex ?? 0]} ${q.answerText ?? ''}`
  if (q.format === 'order') return (q.answerText ?? '').replace(/[()]/g, '')
  return (q.answers ?? []).map((a) => a.join(' / ')).join('，')
}

// ---- 一覧 ----
const listQ = ref('')
const listChapter = ref(0) // 0 = 全章
const listStatus = ref<'all' | 'correct' | 'incorrect' | 'none'>('all')
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
    <!-- ============ HOME ============ -->
    <template v-if="phase === 'home'">
      <!-- 問題集の切替 -->
      <div class="row-between" style="margin-bottom: 14px; flex-wrap: wrap; gap: 10px">
        <div v-seg class="book-switch" role="tablist" aria-label="問題集">
          <button v-for="b in g.books" :key="b.key" class="opt" :class="{ on: g.book === b.key }" role="tab" :aria-selected="g.book === b.key" @click="selectBook(b.key)">
            {{ b.name }}<span class="cnt">{{ b.total }}問</span>
          </button>
        </div>
        <div style="font-size: 12px; color: var(--faint)">スクランブル英文法・語法</div>
      </div>

      <!-- 統計 -->
      <div class="stat-grid">
        <div class="card vstat"><div class="vstat-l">総問題数</div><div class="dm vstat-n">{{ stats.total }}</div></div>
        <div class="card vstat"><div class="vstat-l">正解</div><div class="dm vstat-n" style="color: #2e9d62">{{ stats.correct }}</div></div>
        <div class="card vstat"><div class="vstat-l">不正解</div><div class="dm vstat-n" style="color: #cf5563">{{ stats.incorrect }}</div></div>
        <div class="card vstat"><div class="vstat-l">未回答</div><div class="dm vstat-n" style="color: #9aa1ab">{{ stats.unanswered }}</div></div>
        <div class="card vstat"><div class="vstat-l">正答率</div><div class="dm vstat-n">{{ stats.rate }}<span style="font-size: 12px">%</span></div></div>
      </div>

      <div class="home-grid">
        <!-- 設定 -->
        <div class="card" style="padding: 20px">
          <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 14px">
            <div style="font-size: 15px; font-weight: 700">出題設定</div>
            <HelpTip text="章・形式・重要度・出題対象で絞り込み、「開始」で 1 問ずつ解答します。&#10;一覧の問題をタップすると、その問題から順番に始められます。&#10;選択・誤り指摘はタップで即採点。整序は語句を順にタップ、空所補充は入力して「解答」。&#10;PC: 1〜5 キーで選択、Enter で解答・次へ、← → で前後、Backspace で整序を 1 つ戻す。" />
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

          <div class="lab" style="margin-top: 12px">形式</div>
          <div class="chips">
            <button v-for="f in FORMATS" :key="f" class="chip" :class="{ on: fmtSel.includes(f) }" :disabled="!fmtCounts[f]" @click="toggleFmt(f)">
              {{ FORMAT_INFO[f].label }}<span class="chip-n">{{ fmtCounts[f] ?? 0 }}</span>
            </button>
          </div>

          <template v-if="importances.length">
            <div class="lab" style="margin-top: 12px">重要度</div>
            <div class="chips">
              <button v-for="v in importances" :key="v" class="chip" :class="{ on: impSel.includes(v) }" @click="toggleImp(v)">{{ v }}</button>
            </div>
          </template>

          <div class="lab" style="margin-top: 12px">出題対象</div>
          <div v-seg class="seg3">
            <button :class="{ on: target === 'all' }" @click="target = 'all'">すべて</button>
            <button :class="{ on: target === 'unanswered' }" @click="target = 'unanswered'">未回答</button>
            <button :class="{ on: target === 'incorrect' }" @click="target = 'incorrect'">不正解</button>
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

          <button class="start-btn" :disabled="loading || !pool.length" @click="start">開始（{{ startCount }}問<span v-if="wantCount && wantCount < pool.length" style="font-weight: 500; opacity: 0.8"> / 対象 {{ pool.length }}問</span>）</button>
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

    <!-- ============ QUIZ ============ -->
    <template v-else-if="phase === 'quiz' && current">
      <div class="quiz-wrap">
        <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 14px">
          <button class="sq-btn" title="中断" @click="exitQuiz">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 6l12 12M18 6L6 18" /></svg>
          </button>
          <div class="track" style="flex: 1; height: 7px"><div :style="{ height: '100%', width: progress + '%', background: '#3b50cc', borderRadius: '99px' }"></div></div>
          <span style="font-size: 12.5px; color: var(--mut)">{{ quiz.index + 1 }}/{{ quiz.list.length }}</span>
        </div>
        <GrammarQuestionCard
          :key="current.id"
          :question="current"
          :result="currentResult"
          :has-prev="quiz.index > 0"
          :is-last="quiz.index + 1 >= quiz.list.length"
          @answer="onAnswer"
          @override="onOverride"
          @next="next"
          @prev="prev"
        />
        <div v-if="!currentResult && quiz.index > 0" style="margin-top: 10px; text-align: center">
          <button class="link-btn" style="color: #9aa1ab" @click="prev">前の問題に戻る</button>
        </div>
      </div>
    </template>

    <!-- ============ RESULTS ============ -->
    <template v-else-if="phase === 'results'">
      <div style="max-width: 560px; margin: 0 auto">
        <div class="card" style="padding: 30px 26px; text-align: center; margin-bottom: 16px; border-radius: 18px">
          <div style="font-size: 13px; color: var(--faint); margin-bottom: 8px">結果</div>
          <div class="dm" :style="{ fontSize: '48px', fontWeight: 700, color: resultColor, lineHeight: 1 }">{{ resultPct }}<span style="font-size: 22px">%</span></div>
          <div style="font-size: 14px; color: var(--mut); margin-top: 6px">{{ score.correct }} / {{ score.total }} 問 正解</div>
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

        <div style="display: flex; gap: 10px; flex-wrap: wrap">
          <button v-if="incorrectList.length" class="next-btn" @click="retryIncorrect">不正解だけ再挑戦</button>
          <button v-if="sessionHasMore" class="next-btn" @click="continueSession">続ける（次の {{ nextBatchSize }} 問）</button>
          <button class="prev-btn" style="flex: 1" @click="exitQuiz">設定に戻る</button>
        </div>
      </div>
    </template>
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
.quiz-wrap {
  max-width: 680px;
  margin: 0 auto;
}
.track {
  background: #eef0f3;
  border-radius: 99px;
  overflow: hidden;
}
.sq-btn {
  border: 1px solid #e3e6ea;
  background: #fff;
  border-radius: 9px;
  width: 30px;
  height: 30px;
  cursor: pointer;
  color: #6b7280;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
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
