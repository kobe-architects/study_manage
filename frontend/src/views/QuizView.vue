<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { shuffle, speak } from '@/lib/design'
import { useVocabularyStore } from '@/stores/vocabulary'
import { useUiStore } from '@/stores/ui'
import type {
  PrintTestFormat,
  PrintTestType,
  QuizSettings,
  QuizType,
  Vocabulary,
  VocabularyLabel,
} from '@/types'
import FlashcardPlayer from '@/components/FlashcardPlayer.vue'
import TestSheetPrint from '@/components/TestSheetPrint.vue'
import AuthImage from '@/components/AuthImage.vue'

const router = useRouter()
const vocab = useVocabularyStore()
const ui = useUiStore()

type Phase = 'home' | 'quiz' | 'results' | 'flash'
const phase = ref<Phase>('home')

// ---- settings state ----
const secSel = ref<Record<number, boolean>>({})
const impSel = ref<number[]>([]) // 重要度絞り込み（空=全件）
const labelSel = ref<VocabularyLabel[]>([]) // ラベル絞り込み（空=全件）
const quizType = ref<QuizType>('choice')
const ordered = ref(false)
const count = ref(10)
const incorrectOnly = ref(false)
const wq = ref('')
const wSec = ref<'all' | number>('all')

const labelChoices: { v: VocabularyLabel; t: string }[] = [
  { v: 'easy', t: '易' },
  { v: 'normal', t: '普' },
  { v: 'hard', t: '難' },
]
const impChoices: { v: number; t: string }[] = [
  { v: 0, t: '無印' },
  { v: 1, t: '★' },
  { v: 2, t: '★★' },
]
function toggleImp(v: number) {
  impSel.value = impSel.value.includes(v) ? impSel.value.filter((x) => x !== v) : [...impSel.value, v]
}
function toggleLabel(v: VocabularyLabel) {
  labelSel.value = labelSel.value.includes(v)
    ? labelSel.value.filter((x) => x !== v)
    : [...labelSel.value, v]
}

// ---- print (小テスト) ----
const printOpen = ref(false)
const printType = ref<PrintTestType>('meaning')
const printFormat = ref<PrintTestFormat>('free')
const printWords = computed(() =>
  vocab.items.filter(
    (w) =>
      secSel.value[w.sectionId] &&
      (!impSel.value.length || impSel.value.includes(w.importance)) &&
      (!labelSel.value.length || labelSel.value.includes(w.label)),
  ),
)
// 小テストの出題：クイズ設定（セクション/重要度/ラベル/出題順/問題数）を反映
const printSelection = computed(() => {
  const base = printWords.value.slice()
  const ordered2 = ordered.value
    ? [...base].sort((a, b) => a.sortOrder - b.sortOrder)
    : shuffle(base)
  return wantCount.value > 0 ? ordered2.slice(0, wantCount.value) : ordered2
})
function openPrint() {
  if (!printWords.value.length) {
    ui.notify('対象の単語がありません')
    return
  }
  printOpen.value = true
}

const resourceId = computed(() => vocab.resource?.id ?? 0)
const sections = computed(() => vocab.resource?.sections ?? [])

onMounted(async () => {
  if (!vocab.resource) await vocab.fetchResources()
  if (resourceId.value) {
    await Promise.all([vocab.fetchByResource(resourceId.value), vocab.fetchStats(resourceId.value)])
  }
  // default all sections selected
  const sel: Record<number, boolean> = {}
  sections.value.forEach((s) => (sel[s.id] = true))
  secSel.value = sel
  // resumed quiz (e.g. from review)
  if (vocab.quizQuestions.length && !vocab.isQuizComplete) {
    quizType.value = vocab.quizType
    phase.value = 'quiz'
    primeDisplay()
  }
})

// ---- stats ----
const stats = computed(
  () => vocab.stats ?? { totalWords: 0, masteredCount: 0, learningCount: 0, newCount: 0, overallAccuracy: 0, dueForReview: 0 },
)

const selCount = computed(() => printWords.value.length)
// 問題数の自由入力を正規化（空欄・不正値・0以下は「全件」扱い）
const wantCount = computed(() => {
  const n = Number(count.value)
  return Number.isFinite(n) && n > 0 ? Math.floor(n) : 0
})
const secModalOpen = ref(false)
const selectedSecCount = computed(() => sections.value.filter((s) => secSel.value[s.id]).length)

// ---- word list ----
const labelMap: Record<VocabularyLabel, { t: string; bg: string; fg: string }> = {
  easy: { t: '易', bg: '#e9f5ee', fg: '#2e9d62' },
  normal: { t: '普', bg: '#eef1f6', fg: '#5b6b8c' },
  hard: { t: '難', bg: '#fdeef0', fg: '#cf5563' },
}
const profMap = { high: { t: '高', c: '#2e9d62' }, medium: { t: '中', c: '#d98a2b' }, low: { t: '低', c: '#cf5563' } }

function statusOf(w: Vocabulary): { t: string; c: string } {
  const st = w.learningStat
  if (!st) return { t: '新規', c: '#9aa1ab' }
  if (st.repetitionCount >= 3 && st.intervalDays >= 7) return { t: '習得', c: '#2e9d62' }
  return { t: '学習中', c: '#3b50cc' }
}

const wordRows = computed(() => {
  const term = wq.value.trim().toLowerCase()
  return vocab.items
    .filter((w) => (wSec.value === 'all' || w.sectionId === wSec.value) && (!term || (w.word + w.meaning).toLowerCase().includes(term)))
    .slice(0, 60)
    .map((w) => {
      const prof = w.proficiency
      const sm = statusOf(w)
      return {
        id: w.id,
        word: w.word,
        meaning: w.meaning,
        pos: w.partOfSpeech ?? '',
        stars: '★'.repeat(w.importance) || '–',
        prof: prof ? profMap[prof].t : '—',
        profColor: prof ? profMap[prof].c : '#cdd2d9',
        status: sm.t,
        statusColor: sm.c,
      }
    })
})

const wSecOptions = computed(() => [{ val: 'all' as const, label: '全セクション' }, ...sections.value.map((s) => ({ val: s.id, label: s.name }))])

// ---- quiz running ----
const displayed = ref<{ vocab: Vocabulary; choices?: { meaning: string; isCorrect: boolean }[] } | null>(null)
const revealed = ref(false)
const selected = ref<number | null>(null)
const inputVal = ref('')
const choicesShown = ref(false) // 4択の段階表示
const showTrans = ref(false) // 和訳トグル
const showExpl = ref(false) // 例文説明トグル

function primeDisplay() {
  const q = vocab.currentQuestion
  displayed.value = q ? { vocab: q.vocabulary, choices: q.choices } : null
  const ans = vocab.currentAnswer
  revealed.value = !!ans
  selected.value = ans?.selected ?? null
  inputVal.value = ans?.input ?? ''
  choicesShown.value = !!ans // 回答済みなら選択肢を復元表示
  showTrans.value = false
  showExpl.value = false
  if (!ans && displayed.value) speak(displayed.value.vocab.word)
}

// ---- メモのその場編集 ----
const memoOpen = ref(false)
const memoText = ref('')
function openMemo() {
  if (!displayed.value) return
  memoText.value = displayed.value.vocab.memo ?? ''
  memoOpen.value = true
}
async function saveMemo() {
  if (!displayed.value) return
  const id = displayed.value.vocab.id
  const memo = memoText.value.trim() || null
  await vocab.update(id, { memo })
  vocab.updateQuizVocabulary(id, { memo })
  displayed.value.vocab.memo = memo
  memoOpen.value = false
  ui.notify('メモを更新しました')
}

function settingsObj(extra?: Partial<QuizSettings>): QuizSettings {
  const sectionIds = sections.value.filter((s) => secSel.value[s.id]).map((s) => s.id)
  return {
    sectionIds,
    quizType: quizType.value,
    count: wantCount.value,
    ordered: ordered.value,
    importances: impSel.value.length ? impSel.value : undefined,
    labels: labelSel.value.length ? labelSel.value : undefined,
    ...extra,
  }
}

async function startQuiz(vocabularyIds?: number[]) {
  const extra: Partial<QuizSettings> = {}
  if (vocabularyIds) extra.vocabularyIds = vocabularyIds
  if (incorrectOnly.value && !vocabularyIds) {
    // 誤答した単語のみ：直近1ヶ月の誤答を対象
    const since = new Date()
    since.setMonth(since.getMonth() - 1)
    const { words } = await vocab.fetchIncorrect(resourceId.value, since.toISOString().slice(0, 10))
    if (!words.length) {
      ui.notify('誤答した単語がありません')
      return
    }
    extra.vocabularyIds = words.map((w) => w.id)
  }
  const n = await vocab.startQuiz(resourceId.value, settingsObj(extra))
  if (!n) {
    ui.notify('対象の単語がありません')
    return
  }
  phase.value = 'quiz'
  primeDisplay()
}

async function startFlash() {
  const sectionIds = sections.value.filter((s) => secSel.value[s.id]).map((s) => s.id)
  if (!sectionIds.length) {
    ui.notify('セクションを選択してください')
    return
  }
  phase.value = 'flash'
}

// quiz interactions
function answerChoice(i: number) {
  if (revealed.value || !displayed.value?.choices) return
  const ok = displayed.value.choices[i].isCorrect
  selected.value = i
  revealed.value = true
  vocab.submitAnswer({ isCorrect: ok, selected: i })
  if (!ok) speak(displayed.value.vocab.word)
}
function dontKnow() {
  if (revealed.value) return
  selected.value = -1
  revealed.value = true
  vocab.submitAnswer({ isCorrect: false, selected: -1 })
}
function submitInput() {
  if (revealed.value || !displayed.value) return
  const ok = inputVal.value.trim().toLowerCase() === displayed.value.vocab.word.toLowerCase()
  revealed.value = true
  vocab.submitAnswer({ isCorrect: ok, input: inputVal.value })
}
function nextQ() {
  vocab.advanceQuiz()
  if (vocab.isQuizComplete) {
    phase.value = 'results'
    return
  }
  primeDisplay()
}
function prevQ() {
  vocab.goBackQuiz()
  primeDisplay()
}
async function setProf(p: 'high' | 'medium' | 'low') {
  if (!displayed.value) return
  const id = displayed.value.vocab.id
  await vocab.update(id, { proficiency: p })
  vocab.updateQuizVocabulary(id, { proficiency: p })
  displayed.value.vocab.proficiency = p
  ui.notify('習熟度を更新しました')
}

function exitQuiz() {
  vocab.resetQuiz()
  phase.value = 'home'
  if (resourceId.value) vocab.fetchStats(resourceId.value)
}
function retryIncorrect() {
  const ids = vocab.incorrectResults.map((w) => w.id)
  startQuiz(ids)
}

// derived display for current question
const ex = computed(() => {
  const w = displayed.value?.vocab
  if (!w || !w.exampleSentence) return { before: '', word: '', after: '' }
  try {
    const re = new RegExp('\\b' + w.word + '[a-z]*', 'i')
    const m = w.exampleSentence.match(re)
    if (m && m.index !== undefined) {
      return {
        before: w.exampleSentence.slice(0, m.index),
        word: m[0],
        after: w.exampleSentence.slice(m.index + m[0].length),
      }
    }
  } catch {
    // ignore
  }
  return { before: w.exampleSentence, word: '', after: '' }
})

const progress = computed(() => {
  const total = vocab.quizQuestions.length
  return total ? Math.round(((vocab.quizIndex + 1) / total) * 100) : 0
})
const score = computed(() => vocab.quizScore)
const resultPct = computed(() => (score.value.total ? Math.round((score.value.correct / score.value.total) * 1000) / 10 : 0))
const resultColor = computed(() => (resultPct.value >= 80 ? '#2e9d62' : resultPct.value >= 50 ? '#d98a2b' : '#cf5563'))
const showResultMeaning = ref(true) // 誤答一覧の意味表示トグル

const curLabel = computed(() => (displayed.value ? labelMap[displayed.value.vocab.label] : labelMap.normal))
const curProf = computed(() => {
  const p = displayed.value?.vocab.proficiency
  return p ? profMap[p].t : '未設定'
})
const feedback = computed(() => {
  const ans = vocab.currentAnswer
  if (!ans) return { text: '', color: '', bg: '' }
  return ans.isCorrect
    ? { text: '正解！', color: '#1f7a45', bg: '#eaf7ef' }
    : { text: '不正解', color: '#c0444f', bg: '#fdeef0' }
})

function allSec() {
  const sel: Record<number, boolean> = {}
  sections.value.forEach((s) => (sel[s.id] = true))
  secSel.value = sel
}
function clearSec() {
  secSel.value = {}
}
function toggleSec(id: number) {
  secSel.value = { ...secSel.value, [id]: !secSel.value[id] }
}
</script>

<template>
  <div>
    <!-- ============ HOME ============ -->
    <template v-if="phase === 'home'">
      <!-- stats -->
      <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(108px, 1fr)); gap: 12px; margin-bottom: 18px">
        <div class="card vstat"><div class="vstat-l">総単語数</div><div class="dm vstat-n">{{ stats.totalWords }}</div></div>
        <div class="card vstat"><div class="vstat-l">習得</div><div class="dm vstat-n" style="color: #2e9d62">{{ stats.masteredCount }}</div></div>
        <div class="card vstat"><div class="vstat-l">学習中</div><div class="dm vstat-n" style="color: #3b50cc">{{ stats.learningCount }}</div></div>
        <div class="card vstat"><div class="vstat-l">新規</div><div class="dm vstat-n" style="color: #9aa1ab">{{ stats.newCount }}</div></div>
        <div class="card vstat"><div class="vstat-l">正答率</div><div class="dm vstat-n">{{ stats.overallAccuracy }}<span style="font-size: 12px">%</span></div></div>
        <div class="card vstat"><div class="vstat-l">復習期限</div><div class="dm vstat-n" style="color: #d98a2b">{{ stats.dueForReview }}</div></div>
      </div>

      <div style="display: grid; grid-template-columns: minmax(0, 380px) minmax(0, 1fr); gap: 18px; align-items: start" class="vq-grid">
        <!-- settings -->
        <div class="card" style="padding: 20px">
          <div class="row-between" style="margin-bottom: 2px">
            <div style="font-size: 15px; font-weight: 700">クイズ設定</div>
            <div style="display: flex; gap: 8px">
              <button class="link-btn" @click="router.push({ name: 'vocabulary' })">単語管理</button>
              <button class="link-btn" @click="router.push({ name: 'review' })">復習</button>
            </div>
          </div>
          <div style="font-size: 12px; color: var(--faint); margin-bottom: 16px">{{ vocab.resource?.name }}</div>

          <div class="lab">出題セクション</div>
          <button class="sec-open" @click="secModalOpen = true">
            <span>{{ selectedSecCount === sections.length ? 'すべて' : selectedSecCount === 0 ? '未選択' : selectedSecCount + ' セクション' }}<span style="color: var(--faint); font-weight: 400">（{{ selectedSecCount }}/{{ sections.length }}）</span></span>
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#9aa1ab" stroke-width="2"><path d="M9 6l6 6-6 6" /></svg>
          </button>

          <div style="margin-bottom: 14px">
            <div class="lab">重要度</div>
            <div style="display: flex; gap: 7px; flex-wrap: wrap">
              <button
                v-for="im in impChoices"
                :key="im.v"
                class="chip"
                :style="{
                  border: '1px solid ' + (impSel.includes(im.v) ? '#1c2024' : '#e3e6ea'),
                  background: impSel.includes(im.v) ? '#1c2024' : '#fff',
                  color: impSel.includes(im.v) ? '#fff' : '#6b7280',
                }"
                @click="toggleImp(im.v)"
              >{{ im.t }}</button>
            </div>
          </div>

          <div style="margin-bottom: 14px">
            <div class="lab">ラベル</div>
            <div style="display: flex; gap: 7px; flex-wrap: wrap">
              <button
                v-for="lb in labelChoices"
                :key="lb.v"
                class="chip"
                :style="{
                  border: '1px solid ' + (labelSel.includes(lb.v) ? '#1c2024' : '#e3e6ea'),
                  background: labelSel.includes(lb.v) ? '#1c2024' : '#fff',
                  color: labelSel.includes(lb.v) ? '#fff' : '#6b7280',
                }"
                @click="toggleLabel(lb.v)"
              >{{ lb.t }}</button>
            </div>
          </div>

          <div style="display: flex; gap: 14px; margin-bottom: 14px">
            <div style="flex: 1">
              <div class="lab">出題形式</div>
              <div class="seg2">
                <button :class="{ on: quizType === 'choice' }" @click="quizType = 'choice'">4択</button>
                <button :class="{ on: quizType === 'input' }" @click="quizType = 'input'">入力</button>
              </div>
            </div>
            <div style="flex: 1">
              <div class="lab">出題順</div>
              <div class="seg2">
                <button :class="{ on: !ordered }" @click="ordered = false">ランダム</button>
                <button :class="{ on: ordered }" @click="ordered = true">順番</button>
              </div>
            </div>
          </div>

          <div class="row-between" style="margin-bottom: 14px">
            <span style="font-size: 12px; color: var(--mut); font-weight: 500">問題数</span>
            <div style="display: flex; align-items: center; gap: 8px">
              <button class="all-btn" :class="{ on: wantCount === 0 }" @click="count = 0">すべて</button>
              <input v-model.number="count" type="number" min="1" :max="selCount || undefined" class="num-input" @focus="count === 0 && (count = Math.min(10, selCount) || 1)" />
              <span style="font-size: 11px; color: var(--faint)">問</span>
            </div>
          </div>

          <div class="row-between" style="margin-bottom: 18px">
            <div><div style="font-size: 13px; font-weight: 500">誤答した単語のみ</div><div style="font-size: 11px; color: var(--faint)">復習モード</div></div>
            <button class="toggle" :class="{ on: incorrectOnly }" @click="incorrectOnly = !incorrectOnly"><span></span></button>
          </div>

          <div style="display: flex; gap: 10px">
            <button class="start-btn" @click="startQuiz()">クイズ開始（{{ wantCount === 0 ? selCount : Math.min(wantCount, selCount) }}語）</button>
            <button class="fc-btn" title="フラッシュカード" @click="startFlash">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="5" width="18" height="14" rx="2" /><path d="M3 10h18" /></svg>FC
            </button>
          </div>
          <button class="print-btn" @click="openPrint">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M6 9V2h12v7M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2M6 14h12v8H6z" /></svg>
            小テスト印刷
          </button>
        </div>

        <!-- word list -->
        <div class="card" style="padding: 18px 20px; min-width: 0">
          <div style="display: flex; gap: 10px; margin-bottom: 14px; flex-wrap: wrap">
            <div style="position: relative; flex: 1; min-width: 150px">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#9aa1ab" stroke-width="2" style="position: absolute; left: 11px; top: 50%; transform: translateY(-50%)"><circle cx="11" cy="11" r="7" /><path d="M21 21l-4-4" /></svg>
              <input v-model="wq" placeholder="単語・意味で検索" style="width: 100%; padding: 8px 11px 8px 34px; border: 1px solid #e3e6ea; border-radius: 9px; font-size: 13px; outline: none" />
            </div>
            <select v-model="wSec" class="mini-select">
              <option v-for="o in wSecOptions" :key="o.val" :value="o.val">{{ o.label }}</option>
            </select>
          </div>
          <div style="overflow-x: auto">
            <table class="wtbl">
              <thead><tr><th>単語</th><th>意味</th><th style="width: 42px">品詞</th><th style="width: 54px">重要度</th><th style="width: 46px">習熟</th><th style="width: 60px">状態</th></tr></thead>
              <tbody>
                <tr v-for="r in wordRows" :key="r.id">
                  <td><button class="bare dm" style="font-size: 13.5px; font-weight: 700; color: #1c2024" @click="speak(r.word)">{{ r.word }}</button></td>
                  <td style="color: #4b5563">{{ r.meaning }}</td>
                  <td style="color: #9aa1ab">{{ r.pos }}</td>
                  <td style="color: #e0a93b; font-size: 11px">{{ r.stars }}</td>
                  <td :style="{ fontWeight: 700, color: r.profColor }">{{ r.prof }}</td>
                  <td><span :style="{ fontSize: '10.5px', fontWeight: 600, color: r.statusColor }">{{ r.status }}</span></td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </template>

    <!-- ============ QUIZ ============ -->
    <template v-else-if="phase === 'quiz' && displayed">
      <div style="max-width: 620px; margin: 0 auto">
        <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 16px">
          <button class="sq-btn" title="中断" @click="exitQuiz">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 6l12 12M18 6L6 18" /></svg>
          </button>
          <div class="track" style="flex: 1; height: 7px"><div :style="{ height: '100%', width: progress + '%', background: '#3b50cc', borderRadius: '99px' }"></div></div>
          <span style="font-size: 12.5px; color: var(--mut)">{{ vocab.quizIndex + 1 }}/{{ vocab.quizQuestions.length }}</span>
        </div>

        <div class="card" style="padding: 28px 26px; border-radius: 18px">
          <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 16px; flex-wrap: wrap">
            <span style="font-size: 11px; font-weight: 600; color: #5b6b8c; background: #eef1f6; padding: 2px 8px; border-radius: 99px">{{ displayed.vocab.partOfSpeech }}</span>
            <span style="font-size: 12px; color: #e0a93b">{{ '★'.repeat(displayed.vocab.importance) }}</span>
            <span :style="{ fontSize: '11px', fontWeight: 600, color: curLabel.fg, background: curLabel.bg, padding: '2px 8px', borderRadius: '99px' }">{{ curLabel.t }}</span>
          </div>

          <!-- choice -->
          <template v-if="quizType === 'choice'">
            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 20px">
              <span class="dm" style="font-size: 34px; font-weight: 700">{{ displayed.vocab.word }}</span>
              <button class="sq-btn" title="発音" @click="speak(displayed.vocab.word)">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M11 5L6 9H3v6h3l5 4z" /><path d="M15.5 8.5a5 5 0 0 1 0 7M18.5 5.5a9 9 0 0 1 0 13" /></svg>
              </button>
            </div>
            <button v-if="!choicesShown" class="reveal-btn" @click="choicesShown = true">選択肢を表示</button>
            <div v-else style="display: flex; flex-direction: column; gap: 10px">
              <button
                v-for="(c, i) in displayed.choices"
                :key="i"
                class="choice"
                :style="{
                  border: '1.5px solid ' + (revealed ? (c.isCorrect ? '#9ed4b3' : i === selected ? '#f0b8be' : '#e3e6ea') : '#e3e6ea'),
                  background: revealed ? (c.isCorrect ? '#eaf7ef' : i === selected ? '#fdeef0' : '#fff') : '#fff',
                  color: revealed && !c.isCorrect && i !== selected ? '#6b7280' : '#1c2024',
                }"
                @click="answerChoice(i)"
              >
                <span class="dm" style="font-weight: 700; color: #9aa1ab">{{ 'ABCD'[i] }}</span>
                <span style="flex: 1">{{ c.meaning }}</span>
                <span style="font-weight: 700">{{ revealed ? (c.isCorrect ? '○' : i === selected ? '×' : '') : '' }}</span>
              </button>
            </div>
            <button v-if="choicesShown && !revealed" class="dont-know" @click="dontKnow">わからない</button>
          </template>

          <!-- input -->
          <template v-else>
            <div style="font-size: 21px; font-weight: 700; margin-bottom: 6px">{{ displayed.vocab.meaning }}</div>
            <div style="font-size: 12px; color: var(--faint); margin-bottom: 16px">意味に合う英単語を入力してください</div>
            <div style="display: flex; gap: 10px">
              <input
                v-model="inputVal"
                placeholder="英単語を入力"
                :disabled="revealed"
                class="dm"
                :style="{
                  flex: 1,
                  padding: '12px 14px',
                  border: '1.5px solid ' + (revealed ? (vocab.currentAnswer?.isCorrect ? '#9ed4b3' : '#f0b8be') : '#d8dce1'),
                  borderRadius: '11px',
                  fontSize: '16px',
                  outline: 'none',
                }"
                @keydown.enter="submitInput"
              />
              <button v-if="!revealed" class="answer-btn" @click="submitInput">解答</button>
            </div>
          </template>

          <!-- revealed -->
          <div v-if="revealed" style="margin-top: 18px; padding-top: 18px; border-top: 1px solid #f0f1f3">
            <div :style="{ display: 'inline-flex', alignItems: 'center', gap: '7px', padding: '5px 12px', borderRadius: '99px', background: feedback.bg, color: feedback.color, fontSize: '13px', fontWeight: 700, marginBottom: '12px' }">{{ feedback.text }}</div>
            <div style="display: flex; align-items: baseline; gap: 10px; margin-bottom: 10px; flex-wrap: wrap">
              <span class="dm" style="font-size: 22px; font-weight: 700">{{ displayed.vocab.word }}</span>
              <span style="font-size: 14px; color: #4b5563">{{ displayed.vocab.meaning }}</span>
              <span v-if="displayed.vocab.meaningSupplement" style="font-size: 12.5px; color: #9aa1ab">{{ displayed.vocab.meaningSupplement }}</span>
            </div>
            <div v-if="displayed.vocab.exampleSentence" style="background: #f8f9fb; border-radius: 12px; padding: 13px 15px; margin-bottom: 14px">
              <div style="font-size: 13.5px; line-height: 1.6">
                {{ ex.before }}<span style="font-weight: 700; color: #3b50cc">{{ ex.word }}</span>{{ ex.after }}
                <button class="bare" style="color: #9aa1ab; vertical-align: middle; margin-left: 4px" @click="speak(displayed.vocab.exampleSentence!)">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M11 5L6 9H3v6h3l5 4z" /><path d="M15.5 8.5a5 5 0 0 1 0 7" /></svg>
                </button>
              </div>
              <div v-if="displayed.vocab.exampleTranslation">
                <button v-if="!showTrans" class="mini-link" @click="showTrans = true">和訳を表示</button>
                <div v-else style="font-size: 12px; color: var(--faint); margin-top: 5px">{{ displayed.vocab.exampleTranslation }}</div>
              </div>
              <div v-if="displayed.vocab.exampleExplanation" style="margin-top: 6px">
                <button v-if="!showExpl" class="mini-link" @click="showExpl = true">例文の説明を表示</button>
                <div v-else style="font-size: 12px; color: #4b5563; margin-top: 4px; line-height: 1.55; white-space: pre-wrap">{{ displayed.vocab.exampleExplanation }}</div>
              </div>
            </div>

            <div v-if="displayed.vocab.imageUrl" style="margin-bottom: 14px">
              <AuthImage :src="displayed.vocab.imageUrl" style="max-width: 100%; max-height: 220px; border-radius: 12px; border: 1px solid #e3e6ea" />
            </div>

            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 12px; flex-wrap: wrap">
              <span style="font-size: 12px; color: var(--mut)">習熟度</span>
              <button class="prof-btn" style="color: #2e9d62" @click="setProf('high')">高</button>
              <button class="prof-btn" style="color: #d98a2b" @click="setProf('medium')">中</button>
              <button class="prof-btn" style="color: #cf5563" @click="setProf('low')">低</button>
              <span style="font-size: 11px; color: var(--faint); margin-left: auto">現在: {{ curProf }}</span>
            </div>

            <div style="margin-bottom: 16px">
              <button class="memo-chip" @click="openMemo">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 20h9" /><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z" /></svg>
                {{ displayed.vocab.memo ? displayed.vocab.memo : 'メモを追加' }}
              </button>
            </div>
            <div style="display: flex; gap: 10px">
              <button class="prev-btn" :disabled="vocab.quizIndex === 0" @click="prevQ">前へ</button>
              <button class="next-btn" @click="nextQ">{{ vocab.quizIndex + 1 >= vocab.quizQuestions.length ? '結果を見る' : '次の問題へ' }}</button>
            </div>
          </div>
        </div>
      </div>
    </template>

    <!-- ============ RESULTS ============ -->
    <template v-else-if="phase === 'results'">
      <div style="max-width: 520px; margin: 0 auto">
        <div class="card" style="padding: 30px 26px; text-align: center; margin-bottom: 16px; border-radius: 18px">
          <div style="font-size: 13px; color: var(--faint); margin-bottom: 8px">クイズ結果</div>
          <div class="dm" :style="{ fontSize: '48px', fontWeight: 700, color: resultColor, lineHeight: 1 }">{{ resultPct }}<span style="font-size: 22px">%</span></div>
          <div style="font-size: 14px; color: var(--mut); margin-top: 6px">{{ score.correct }} / {{ score.total }} 問 正解</div>
        </div>
        <div v-if="vocab.incorrectResults.length" class="card" style="padding: 18px 20px; margin-bottom: 16px">
          <div class="row-between" style="margin-bottom: 10px">
            <div style="font-size: 13px; font-weight: 700">誤答した単語（{{ vocab.incorrectResults.length }}）</div>
            <button class="ans-toggle" @click="showResultMeaning = !showResultMeaning">
              答え {{ showResultMeaning ? 'ON' : 'OFF' }}
            </button>
          </div>
          <div style="display: flex; flex-direction: column">
            <div v-for="w in vocab.incorrectResults" :key="w.id" style="display: flex; align-items: baseline; gap: 10px; padding: 8px 0; border-top: 1px solid #f4f5f7">
              <span class="dm" style="font-size: 14px; font-weight: 700; min-width: 120px">{{ w.word }}</span>
              <span v-if="showResultMeaning" style="font-size: 13px; color: var(--mut)">{{ w.meaning }}<span v-if="w.meaningSupplement" style="color: #9aa1ab; margin-left: 6px">{{ w.meaningSupplement }}</span></span>
              <span v-else style="font-size: 13px; color: #cdd2d9">･････</span>
            </div>
          </div>
        </div>
        <div style="display: flex; gap: 10px">
          <button v-if="vocab.incorrectResults.length" class="next-btn" @click="retryIncorrect">誤答だけ再挑戦</button>
          <button class="prev-btn" style="flex: 1" @click="exitQuiz">設定に戻る</button>
        </div>
      </div>
    </template>

    <!-- ============ FLASHCARD ============ -->
    <template v-else-if="phase === 'flash'">
      <FlashcardPlayer
        :words="printWords"
        :ordered="ordered"
        @exit="phase = 'home'"
      />
    </template>

    <!-- 出題セクション選択モーダル -->
    <div v-if="secModalOpen" class="overlay" @click="secModalOpen = false">
      <div class="sec-modal" @click.stop>
        <div class="row-between" style="margin-bottom: 14px">
          <div style="font-size: 15px; font-weight: 700">出題セクション</div>
          <span style="display: flex; gap: 12px">
            <button class="link-btn" @click="allSec">全選択</button>
            <button class="link-btn" style="color: #9aa1ab" @click="clearSec">解除</button>
          </span>
        </div>
        <div class="sec-chips">
          <button
            v-for="s in sections"
            :key="s.id"
            class="chip"
            :style="{
              border: '1px solid ' + (secSel[s.id] ? '#1c2024' : '#e3e6ea'),
              background: secSel[s.id] ? '#1c2024' : '#fff',
              color: secSel[s.id] ? '#fff' : '#6b7280',
            }"
            @click="toggleSec(s.id)"
          >{{ s.name }}</button>
        </div>
        <button class="next-btn" style="margin-top: 16px" @click="secModalOpen = false">決定（{{ selectedSecCount }} / {{ sections.length }}）</button>
      </div>
    </div>

    <!-- メモ編集モーダル -->
    <div v-if="memoOpen" class="overlay" @click="memoOpen = false">
      <div class="memo-modal" @click.stop>
        <div style="font-size: 14px; font-weight: 700; margin-bottom: 12px">メモを編集</div>
        <textarea v-model="memoText" rows="4" class="memo-area" placeholder="覚え方・補足など"></textarea>
        <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 14px">
          <button class="prev-btn" @click="memoOpen = false">キャンセル</button>
          <button class="answer-btn" @click="saveMemo">保存</button>
        </div>
      </div>
    </div>

    <!-- 小テスト印刷 -->
    <TestSheetPrint
      v-if="printOpen"
      :words="printSelection"
      :all-words="vocab.items"
      :resource-name="vocab.resource?.name ?? ''"
      v-model:test-type="printType"
      v-model:test-format="printFormat"
      @close="printOpen = false"
    />
  </div>
</template>

<style scoped>
.row-between {
  display: flex;
  justify-content: space-between;
  align-items: center;
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
.link-btn {
  border: none;
  background: none;
  color: #3b50cc;
  font-size: 11px;
  cursor: pointer;
  font-weight: 600;
}
.chip {
  padding: 6px 11px;
  border-radius: 99px;
  font-size: 12px;
  font-weight: 500;
  cursor: pointer;
}
.lab {
  font-size: 12px;
  color: var(--mut);
  font-weight: 500;
  margin-bottom: 6px;
}
.seg2 {
  display: flex;
  background: #f1f3f5;
  border-radius: 9px;
  padding: 3px;
}
.seg2 button {
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
.seg2 button.on {
  background: #fff;
  color: var(--ink);
}
.mini-select {
  padding: 7px 12px;
  border: 1px solid #e3e6ea;
  border-radius: 8px;
  font-size: 12.5px;
  cursor: pointer;
  outline: none;
  background: #fff;
}
.toggle {
  width: 42px;
  height: 24px;
  border-radius: 99px;
  border: none;
  cursor: pointer;
  background: #d3d7dd;
  position: relative;
}
.toggle.on {
  background: #1c2024;
}
.toggle span {
  position: absolute;
  top: 2px;
  left: 2px;
  width: 20px;
  height: 20px;
  border-radius: 50%;
  background: #fff;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.2);
}
.toggle.on span {
  left: 20px;
}
.start-btn {
  flex: 1;
  padding: 12px;
  border: none;
  border-radius: 11px;
  background: #1c2024;
  color: #fff;
  font-size: 14px;
  font-weight: 700;
  cursor: pointer;
}
.fc-btn {
  padding: 12px 15px;
  border: 1px solid #e3e6ea;
  border-radius: 11px;
  background: #fff;
  color: #1c2024;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  display: flex;
  align-items: center;
  gap: 6px;
}
.wtbl {
  width: 100%;
  border-collapse: collapse;
  font-size: 12.5px;
  min-width: 480px;
}
.wtbl thead tr {
  background: #f8f9fb;
  color: var(--faint);
  font-size: 11px;
  text-align: left;
}
.wtbl th {
  padding: 9px 8px;
  font-weight: 600;
}
.wtbl th:first-child {
  padding-left: 12px;
}
.wtbl td {
  padding: 9px 8px;
  border-top: 1px solid #f0f1f3;
}
.wtbl td:first-child {
  padding-left: 12px;
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
.choice {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 13px 16px;
  border-radius: 12px;
  font-size: 14px;
  font-weight: 500;
  cursor: pointer;
  text-align: left;
}
.dont-know {
  width: 100%;
  margin-top: 12px;
  padding: 9px;
  border: none;
  background: transparent;
  color: #9aa1ab;
  font-size: 12.5px;
  cursor: pointer;
  font-weight: 500;
}
.answer-btn {
  padding: 12px 20px;
  border: none;
  border-radius: 11px;
  background: #1c2024;
  color: #fff;
  font-size: 14px;
  font-weight: 600;
  cursor: pointer;
}
.prof-btn {
  padding: 5px 12px;
  border: 1px solid #e3e6ea;
  border-radius: 8px;
  background: #fff;
  font-size: 12px;
  cursor: pointer;
  font-weight: 600;
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
.prev-btn:disabled {
  opacity: 0.5;
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
.ans-toggle {
  border: 1px solid #e3e6ea;
  background: #fff;
  border-radius: 8px;
  padding: 5px 12px;
  font-size: 12px;
  font-weight: 700;
  cursor: pointer;
  color: #4b5563;
}
.print-btn {
  width: 100%;
  margin-top: 10px;
  padding: 11px;
  border: 1px solid #e3e6ea;
  border-radius: 11px;
  background: #fff;
  color: #1c2024;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 7px;
}
.reveal-btn {
  width: 100%;
  padding: 14px;
  border: 1.5px dashed #c8cdd4;
  border-radius: 12px;
  background: #fff;
  color: #3b50cc;
  font-size: 14px;
  font-weight: 700;
  cursor: pointer;
}
.mini-link {
  border: none;
  background: none;
  color: #3b50cc;
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
  padding: 4px 0 0;
}
.memo-chip {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  max-width: 100%;
  padding: 7px 12px;
  border: 1px solid #e3e6ea;
  border-radius: 99px;
  background: #fff;
  color: #4b5563;
  font-size: 12.5px;
  cursor: pointer;
  text-align: left;
}
.overlay {
  position: fixed;
  inset: 0;
  background: rgba(20, 24, 32, 0.4);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 50;
  padding: 20px;
}
.memo-modal {
  background: #fff;
  border-radius: 16px;
  padding: 22px;
  width: 100%;
  max-width: 420px;
  box-shadow: 0 20px 50px rgba(0, 0, 0, 0.2);
}
.memo-area {
  width: 100%;
  padding: 10px 12px;
  border: 1px solid #e3e6ea;
  border-radius: 10px;
  font-size: 13px;
  font-family: inherit;
  outline: none;
  resize: vertical;
}
.sec-open {
  width: 100%;
  margin-bottom: 16px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 10px 13px;
  border: 1px solid #e3e6ea;
  border-radius: 10px;
  background: #fff;
  cursor: pointer;
  font-size: 13px;
  font-weight: 600;
  color: #1c2024;
}
.sec-modal {
  background: #fff;
  border-radius: 16px;
  padding: 22px;
  width: 100%;
  max-width: 460px;
  max-height: 80vh;
  display: flex;
  flex-direction: column;
  box-shadow: 0 20px 50px rgba(0, 0, 0, 0.2);
}
.sec-chips {
  display: flex;
  flex-wrap: wrap;
  gap: 7px;
  overflow-y: auto;
  padding: 2px;
}
.num-input {
  width: 72px;
  padding: 7px 10px;
  border: 1px solid #e3e6ea;
  border-radius: 8px;
  font-size: 13px;
  text-align: right;
  outline: none;
}
.all-btn {
  padding: 7px 12px;
  border: 1px solid #e3e6ea;
  border-radius: 8px;
  background: #fff;
  color: #6b7280;
  font-size: 12.5px;
  font-weight: 600;
  cursor: pointer;
}
.all-btn.on {
  background: #1c2024;
  border-color: #1c2024;
  color: #fff;
}
.vq-grid {
  grid-template-columns: minmax(0, 380px) minmax(0, 1fr);
}
@media (max-width: 860px) {
  .vq-grid {
    grid-template-columns: minmax(0, 1fr) !important;
  }
}
</style>
