<script setup lang="ts">
/**
 * 英文法・語法の 1 問を形式ごとに表示して解答させるカード。
 *   選択（choice）   … 選択肢を縦に並べたボタン。PC は 1〜5 キーでも選べる
 *   誤り指摘（error） … 文中の ①〜⑤ の下線部をそのままタップ。スマホ用に番号ボタンも並べる
 *   語句整序（order） … 語句のチップを順にタップして文中の空所に並べる（タップで戻す・1つ戻す・クリア。不足語は入力して追加）
 *   空所補充（fill）  … 空所がそのまま入力欄になる（空所と正答の数が合わないときは下に入力欄）。Enter で次の欄・解答
 * 解答後は正誤・正解・和訳・出典を表示し、整序・空所補充は自己判定で正誤を変えられる。
 */
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { speak } from '@/lib/design'
import {
  CIRCLED,
  FORMAT_INFO,
  answerSegments,
  blankCount,
  choiceFillParts,
  completedSentence,
  errorTokens,
  fillAnswerParts,
  fillInline,
  fillInputCount,
  gradeFill,
  gradeOrder,
  orderSlot,
  splitBlanks,
  splitCorrection,
  splitLeadingNote,
} from '@/lib/grammar'
import { haptic } from '@/lib/native'
import type { GrammarQuestion } from '@/types'

export interface GrammarAnswerResult {
  isCorrect: boolean
  /** 生徒の答え（表示用） */
  answer: string | null
  /** 自己判定で変更した */
  overridden: boolean
}

const props = defineProps<{
  question: GrammarQuestion
  result: GrammarAnswerResult | null
  hasPrev: boolean
  isLast: boolean
}>()
const emit = defineEmits<{
  answer: [payload: { isCorrect: boolean; answer: string | null }]
  override: [isCorrect: boolean]
  next: []
  prev: []
}>()

const q = computed(() => props.question)
const revealed = computed(() => !!props.result)
const fmt = computed(() => FORMAT_INFO[q.value.format])
/** 問題文の先頭の〔指示〕は指示欄に出し、英文だけを大きく表示する */
const lead = computed(() => splitLeadingNote(q.value.question))
const sentenceText = computed(() => lead.value.text)

// ---- 形式ごとの入力状態 ----
const selected = ref<number | null>(null) // choice / error（-1 = わからない）
const chosen = ref<{ text: string; from: number | null }[]>([]) // order（from = チップ番号、null = 入力した語）
const extraWord = ref('')
const inputs = ref<string[]>([]) // fill
const blankEls = ref<HTMLInputElement[]>([])

function reset() {
  selected.value = null
  chosen.value = []
  extraWord.value = ''
  inputs.value = Array(fillInputCount(q.value)).fill('')
  // 回答済みの問題に戻ってきたときは選んだ番号を復元する
  const a = props.result?.answer ?? ''
  if ((q.value.format === 'choice' || q.value.format === 'error') && a) {
    const i = CIRCLED.indexOf(a[0] ?? '')
    selected.value = a === 'わからない' ? -1 : i >= 0 ? i : null
  }
  if (q.value.format === 'fill' && a && a !== 'わからない') {
    const parts = a.split(' / ')
    if (parts.length === inputs.value.length) inputs.value = parts
  }
  if (!revealed.value && q.value.format === 'fill') focusBlank(0)
}
watch(() => q.value.id, reset)
onMounted(reset)

function focusBlank(i: number) {
  // PC だけ自動でフォーカス（スマホはキーボードが勝手に出ないようにする）
  if (window.matchMedia('(pointer: coarse)').matches) return
  nextTick(() => blankEls.value[i]?.focus())
}

// ---- 選択・誤り指摘 ----
const choices = computed(() => q.value.choices ?? [])
function pick(i: number) {
  if (revealed.value) return
  selected.value = i
  haptic()
  const ok = i === q.value.answerIndex
  emit('answer', { isCorrect: ok, answer: i < 0 ? 'わからない' : `${CIRCLED[i]} ${choices.value[i] ?? ''}`.trim() })
}
function choiceClass(i: number) {
  if (!revealed.value) return { sel: selected.value === i }
  return { ok: i === q.value.answerIndex, ng: selected.value === i && i !== q.value.answerIndex, dim: i !== q.value.answerIndex && i !== selected.value }
}

// 選択: 文中の空所（正解後は答えを埋める）
const sentencePieces = computed(() => splitBlanks(sentenceText.value))
const choiceParts = computed(() => (revealed.value ? choiceFillParts(q.value) : null))

// 誤り指摘: 文のトークン
const errTokens = computed(() => errorTokens(q.value))
const correction = computed(() => splitCorrection(q.value.answerText))

// ---- 語句整序 ----
const slot = computed(() => orderSlot(sentenceText.value))
const usedChip = computed(() => new Set(chosen.value.map((c) => c.from).filter((x): x is number => x !== null)))
function addChip(i: number) {
  if (revealed.value || usedChip.value.has(i)) return
  chosen.value = [...chosen.value, { text: choices.value[i] ?? '', from: i }]
  haptic()
}
function removeChosen(i: number) {
  if (revealed.value) return
  chosen.value = chosen.value.filter((_, j) => j !== i)
}
function undoChip() {
  if (revealed.value || !chosen.value.length) return
  chosen.value = chosen.value.slice(0, -1)
}
function clearChips() {
  if (revealed.value) return
  chosen.value = []
}
function addExtraWord() {
  const w = extraWord.value.trim()
  if (!w || revealed.value) return
  chosen.value = [...chosen.value, { text: w, from: null }]
  extraWord.value = ''
}
const chosenText = computed(() => chosen.value.map((c) => c.text).join(' '))
function submitOrder() {
  if (revealed.value || !chosen.value.length) return
  emit('answer', { isCorrect: gradeOrder(q.value, chosen.value.map((c) => c.text)), answer: chosenText.value })
}
const orderAnswer = computed(() => answerSegments(q.value.answerText ?? ''))

// ---- 空所補充 ----
const inline = computed(() => fillInline(q.value))
const fillCount = computed(() => fillInputCount(q.value))
const fillParts = computed(() => (revealed.value ? fillAnswerParts(q.value) : null))
const filled = computed(() => inputs.value.every((v) => v.trim() !== ''))
function onBlankEnter(i: number) {
  if (revealed.value) return
  // 空の欄があればそこへ移動、全部埋まっていれば解答
  const empty = inputs.value.findIndex((v, j) => j !== i && v.trim() === '')
  if (empty >= 0) {
    blankEls.value[empty]?.focus()
    return
  }
  submitFill()
}
function submitFill() {
  if (revealed.value || !filled.value) return
  emit('answer', { isCorrect: gradeFill(q.value, inputs.value), answer: inputs.value.map((v) => v.trim()).join(' / ') })
  blankEls.value.forEach((el) => el?.blur())
}
/** 正解の表示（[ ] の別解も） */
const fillAnswerLabel = computed(() => (q.value.answers ?? []).map((alts) => alts.join(' / ')).join('，'))

// ---- 共通 ----
function giveUp() {
  if (revealed.value) return
  if (q.value.format === 'choice' || q.value.format === 'error') selected.value = -1
  emit('answer', { isCorrect: false, answer: 'わからない' })
}
const canOverride = computed(() => q.value.format === 'order' || q.value.format === 'fill')
function toggleJudge() {
  if (!props.result) return
  emit('override', !props.result.isCorrect)
}
const showMyAnswer = computed(
  () => !!props.result && !props.result.isCorrect && props.result.answer && props.result.answer !== 'わからない' && (q.value.format === 'order' || q.value.format === 'fill'),
)
function speakSentence() {
  speak(completedSentence(q.value))
}

// ---- キーボード（PC）: 1〜5 で選択、Enter で解答・次へ、←→ で前後、Backspace で整序を 1 つ戻す ----
function onKey(e: KeyboardEvent) {
  const t = e.target as HTMLElement | null
  const inField = !!t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA')
  if (e.key === 'ArrowLeft' && !inField) {
    if (props.hasPrev) emit('prev')
    return
  }
  if (revealed.value) {
    if ((e.key === 'Enter' || e.key === 'ArrowRight') && !inField) {
      e.preventDefault()
      emit('next')
    }
    return
  }
  if (inField) return
  if (/^[1-9]$/.test(e.key) && (q.value.format === 'choice' || q.value.format === 'error')) {
    const i = Number(e.key) - 1
    if (i < choices.value.length) pick(i)
  } else if (e.key === 'Enter' && q.value.format === 'order') {
    submitOrder()
  } else if (e.key === 'Backspace' && q.value.format === 'order') {
    e.preventDefault()
    undoChip()
  }
}
onMounted(() => window.addEventListener('keydown', onKey))
onBeforeUnmount(() => window.removeEventListener('keydown', onKey))
</script>

<template>
  <div class="gq card">
    <!-- 見出し: 番号・章・Theme・形式・重要度 -->
    <div class="gq-meta">
      <span class="dm gq-no">No.{{ q.no }}</span>
      <span class="gq-ch">{{ q.chapter }}</span>
      <span class="gq-th">Theme {{ q.theme }}</span>
      <span class="gq-fmt" :style="{ background: fmt.bg, color: fmt.fg }">{{ q.formatLabel }}</span>
      <span v-if="q.importance" class="gq-imp">{{ q.importance }}</span>
    </div>

    <!-- 日本文・指示 -->
    <div v-if="q.instruction || lead.note" class="gq-inst">
      <div v-if="lead.note">〔{{ lead.note }}〕</div>
      <div v-if="q.instruction">{{ q.instruction }}</div>
    </div>

    <!-- ============ 選択 ============ -->
    <template v-if="q.format === 'choice'">
      <p class="gq-sent dm">
        <template v-for="(piece, i) in sentencePieces" :key="i">{{ piece }}<span v-if="i < sentencePieces.length - 1" class="blank" :class="{ filled: !!choiceParts }">{{ choiceParts ? choiceParts[i] : '' }}</span></template>
      </p>
      <div class="gq-choices">
        <button v-for="(c, i) in choices" :key="i" class="gq-choice" :class="choiceClass(i)" :disabled="revealed" @click="pick(i)">
          <span class="n dm">{{ CIRCLED[i] }}</span>
          <span class="t dm">{{ c }}</span>
          <span class="mk">{{ revealed ? (i === q.answerIndex ? '○' : selected === i ? '×' : '') : '' }}</span>
        </button>
      </div>
    </template>

    <!-- ============ 誤り指摘 ============ -->
    <template v-else-if="q.format === 'error'">
      <div v-if="!q.instruction" class="gq-inst">誤りを含む下線部を選べ</div>
      <p class="gq-sent dm">
        <template v-for="(t, i) in errTokens" :key="i">
          <template v-if="t.kind === 'text'">{{ t.text }}</template>
          <template v-else>
            <button class="seg" :class="choiceClass(t.idx)" :disabled="revealed" @click="pick(t.idx)"><span class="seg-n">{{ CIRCLED[t.idx] }}</span><span class="seg-t">{{ t.text }}</span></button>{{ t.rest }}
          </template>
        </template>
      </p>
      <div class="gq-nums">
        <button v-for="(c, i) in choices" :key="i" class="num" :class="choiceClass(i)" :disabled="revealed" :title="c" @click="pick(i)">{{ CIRCLED[i] }}</button>
      </div>
    </template>

    <!-- ============ 語句整序 ============ -->
    <template v-else-if="q.format === 'order'">
      <p class="gq-sent dm">
        <template v-if="slot">{{ slot.before }}<span class="slot" :class="{ empty: !chosen.length }">
          <template v-if="chosen.length"><button v-for="(c, i) in chosen" :key="i" class="slot-chip" :class="{ extra: c.from === null }" :disabled="revealed" @click="removeChosen(i)">{{ c.text }}</button></template>
          <span v-else class="slot-ph">語句をタップして並べる</span>
        </span>{{ slot.after }}</template>
        <template v-else>{{ sentenceText }}</template>
      </p>
      <div v-if="!slot" class="slot-row" :class="{ empty: !chosen.length }">
        <template v-if="chosen.length"><button v-for="(c, i) in chosen" :key="i" class="slot-chip" :class="{ extra: c.from === null }" :disabled="revealed" @click="removeChosen(i)">{{ c.text }}</button></template>
        <span v-else class="slot-ph">語句をタップして並べる</span>
      </div>
      <div class="gq-chips">
        <button v-for="(c, i) in choices" :key="i" class="chip dm" :class="{ used: usedChip.has(i) }" :disabled="revealed || usedChip.has(i)" @click="addChip(i)">{{ c }}</button>
      </div>
      <div v-if="q.missing.length && !revealed" class="gq-extra">
        <input v-model="extraWord" class="dm" placeholder="不足している語を入力" autocapitalize="off" autocorrect="off" autocomplete="off" spellcheck="false" enterkeyhint="done" @keydown.enter.prevent="addExtraWord" />
        <button class="btn-ghost" :disabled="!extraWord.trim()" @click="addExtraWord">追加</button>
      </div>
      <div v-if="!revealed" class="gq-actions">
        <button class="btn-ghost" :disabled="!chosen.length" @click="undoChip">1つ戻す</button>
        <button class="btn-ghost" :disabled="!chosen.length" @click="clearChips">クリア</button>
        <button class="btn-main" :disabled="!chosen.length" @click="submitOrder">解答</button>
      </div>
    </template>

    <!-- ============ 空所補充 ============ -->
    <template v-else>
      <p class="gq-sent dm">
        <template v-if="inline">
          <template v-for="(piece, i) in sentencePieces" :key="i">{{ piece }}<template v-if="i < sentencePieces.length - 1">
            <span v-if="revealed" class="blank filled" :class="{ ng: !result?.isCorrect }">{{ fillParts ? fillParts[i] : '' }}</span>
            <input v-else :ref="(el) => { if (el) blankEls[i] = el as HTMLInputElement }" v-model="inputs[i]" class="gq-blank dm" :placeholder="fillCount > 1 ? `(${i + 1})` : ''" autocapitalize="off" autocorrect="off" autocomplete="off" spellcheck="false" :enterkeyhint="i === fillCount - 1 ? 'done' : 'next'" @keydown.enter.prevent="onBlankEnter(i)" />
          </template></template>
        </template>
        <template v-else>{{ sentenceText }}</template>
      </p>
      <div v-if="!inline && !revealed" class="gq-fill-row">
        <template v-for="i in fillCount" :key="i">
          <span v-if="fillCount > 1" class="fill-n">({{ i }})</span>
          <input :ref="(el) => { if (el) blankEls[i - 1] = el as HTMLInputElement }" v-model="inputs[i - 1]" class="gq-blank dm wide" :placeholder="blankCount(q.question) > 1 && fillCount === 1 ? '共通の 1 語' : '答えを入力'" autocapitalize="off" autocorrect="off" autocomplete="off" spellcheck="false" :enterkeyhint="i === fillCount ? 'done' : 'next'" @keydown.enter.prevent="onBlankEnter(i - 1)" />
        </template>
      </div>
      <div v-if="!revealed" class="gq-actions">
        <button class="btn-main" :disabled="!filled" @click="submitFill">解答</button>
      </div>
    </template>

    <button v-if="!revealed" class="dont-know" @click="giveUp">わからない</button>

    <!-- ============ 解答後 ============ -->
    <div v-if="revealed && result" class="gq-reveal">
      <div class="rv-head">
        <span class="rv-badge" :class="result.isCorrect ? 'ok' : 'ng'">{{ result.isCorrect ? '正解！' : '不正解' }}</span>
        <button v-if="canOverride" class="rv-judge" @click="toggleJudge">{{ result.isCorrect ? '不正解にする' : '正解にする' }}</button>
        <button class="rv-speak" title="読み上げ" @click="speakSentence">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M11 5L6 9H3v6h3l5 4z" /><path d="M15.5 8.5a5 5 0 0 1 0 7M18.5 5.5a9 9 0 0 1 0 13" /></svg>
        </button>
      </div>

      <!-- 正解 -->
      <div class="rv-ans">
        <span class="rv-lab">正解</span>
        <template v-if="q.format === 'choice'"><span class="dm rv-strong">{{ CIRCLED[q.answerIndex ?? 0] }} {{ q.answerText }}</span></template>
        <template v-else-if="q.format === 'error'">
          <span class="dm rv-strong">{{ CIRCLED[q.answerIndex ?? 0] }} {{ correction ? correction.from : q.answerText }}</span>
          <span v-if="correction" class="dm rv-fix">→ {{ correction.to }}</span>
        </template>
        <template v-else-if="q.format === 'order'">
          <span class="dm rv-sentence"><template v-for="(s, i) in orderAnswer" :key="i"><span :class="{ 'rv-strong': s.hl }">{{ s.text }}</span></template></span>
        </template>
        <template v-else><span class="dm rv-strong">{{ fillAnswerLabel }}</span></template>
      </div>
      <div v-if="q.answerNote" class="rv-note">〔{{ q.answerNote }}〕</div>
      <div v-if="showMyAnswer" class="rv-mine"><span class="rv-lab">あなたの答え</span><span class="dm">{{ result.answer }}</span></div>
      <div v-if="q.translation" class="rv-trans">{{ q.translation }}</div>
      <div class="rv-foot">
        <span v-if="q.source">出典: {{ q.source }}</span>
        <span v-if="q.note" class="rv-remark">{{ q.note }}</span>
      </div>
    </div>

    <!-- 前へ・次へ（スマホでは画面の下に張り付く） -->
    <div v-if="revealed" class="gq-nav">
      <button class="btn-ghost" :disabled="!hasPrev" @click="emit('prev')">前へ</button>
      <button class="btn-main" @click="emit('next')">{{ isLast ? '結果を見る' : '次の問題へ' }}</button>
    </div>
  </div>
</template>

<style scoped>
.gq {
  padding: 22px 24px 0;
  border-radius: 18px;
  /* overflow: hidden にすると下の「次へ」の sticky が効かなくなるので付けない */
}
.gq-meta {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  margin-bottom: 12px;
  font-size: 12px;
  color: var(--faint);
}
.gq-no {
  font-weight: 700;
  color: var(--ink);
  font-size: 13px;
}
.gq-fmt,
.gq-imp {
  font-size: 11px;
  font-weight: 600;
  padding: 2px 8px;
  border-radius: 99px;
}
.gq-imp {
  background: #fff4e5;
  color: #b7681a;
}
.gq-inst {
  font-size: 14px;
  color: #4b5563;
  background: #f8f9fb;
  border-radius: 10px;
  padding: 9px 13px;
  margin-bottom: 12px;
  line-height: 1.6;
}
.gq-sent {
  margin: 0 0 16px;
  font-size: 18px;
  line-height: 1.9;
  color: var(--ink);
  word-break: normal;
  overflow-wrap: anywhere;
}
/* 空所 */
.blank {
  display: inline-block;
  min-width: 64px;
  margin: 0 3px;
  padding: 0 6px;
  border-bottom: 2px solid #9aa1ab;
  line-height: 1.4;
  vertical-align: baseline;
  text-align: center;
}
.blank.filled {
  color: var(--primary);
  font-weight: 700;
  border-bottom-color: var(--primary);
}
.blank.filled.ng {
  color: #2e9d62;
  border-bottom-color: #2e9d62;
}
.gq-blank {
  width: clamp(96px, 8.5em, 190px);
  margin: 0 3px;
  padding: 4px 8px;
  border: none;
  border-bottom: 2px solid #3b50cc;
  border-radius: 6px 6px 0 0;
  background: #f1f3fb;
  font-size: 17px;
  text-align: center;
  outline: none;
  vertical-align: baseline;
}
.gq-blank.wide {
  width: min(100%, 260px);
  margin: 0;
}
.gq-blank:focus {
  background: #e8ecfb;
}
.gq-fill-row {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
  margin-bottom: 14px;
}
.fill-n {
  font-size: 13px;
  color: var(--mut);
}

/* 選択肢 */
.gq-choices {
  display: flex;
  flex-direction: column;
  gap: 9px;
}
.gq-choice {
  display: flex;
  align-items: center;
  gap: 12px;
  width: 100%;
  min-height: 48px;
  padding: 11px 15px;
  border: 1.5px solid #e3e6ea;
  border-radius: 12px;
  background: #fff;
  font-size: 15.5px;
  text-align: left;
  color: var(--ink);
  cursor: pointer;
}
.gq-choice .n {
  font-weight: 700;
  color: #9aa1ab;
  flex-shrink: 0;
}
.gq-choice .t {
  flex: 1;
  line-height: 1.5;
}
.gq-choice .mk {
  font-weight: 700;
  min-width: 16px;
  text-align: right;
}
.gq-choice:disabled {
  cursor: default;
}
.gq-choice.sel {
  border-color: #1c2024;
}
.gq-choice.ok,
.seg.ok,
.num.ok {
  border-color: #9ed4b3;
  background: #eaf7ef;
  color: #1f7a45;
}
.gq-choice.ok .n,
.gq-choice.ok .mk {
  color: #1f7a45;
}
.gq-choice.ng,
.seg.ng,
.num.ng {
  border-color: #f0b8be;
  background: #fdeef0;
  color: #c0444f;
}
.gq-choice.ng .mk {
  color: #c0444f;
}
.gq-choice.dim,
.num.dim {
  color: #9aa1ab;
}

/* 誤り指摘: 文中の下線部 */
.seg {
  display: inline;
  margin: 0 1px;
  padding: 3px 4px 1px;
  border: none;
  border-bottom: 2px solid #9aa1ab;
  border-radius: 6px 6px 0 0;
  background: transparent;
  font: inherit;
  color: inherit;
  cursor: pointer;
  line-height: inherit;
  -webkit-tap-highlight-color: rgba(59, 80, 204, 0.15);
}
.seg .seg-n {
  font-size: 0.8em;
  color: #3b50cc;
  margin-right: 2px;
  vertical-align: super;
}
.seg.sel {
  background: #e8ecfb;
  border-bottom-color: #3b50cc;
}
.seg.ok {
  border-bottom-color: #2e9d62;
}
.seg.ng {
  border-bottom-color: #cf5563;
  text-decoration: line-through;
  text-decoration-color: #cf5563;
}
.seg:disabled {
  cursor: default;
}
.gq-nums {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
}
.num {
  width: 46px;
  height: 46px;
  border: 1.5px solid #e3e6ea;
  border-radius: 50%;
  background: #fff;
  font-size: 19px;
  cursor: pointer;
  color: var(--ink);
}
.num.sel {
  border-color: #1c2024;
  background: #1c2024;
  color: #fff;
}
.num:disabled {
  cursor: default;
}

/* 語句整序 */
.slot {
  display: inline;
  padding: 2px 6px;
  border-bottom: 2px solid #3b50cc;
  border-radius: 6px 6px 0 0;
  background: #f1f3fb;
  box-decoration-break: clone;
  -webkit-box-decoration-break: clone;
  line-height: 2.2;
}
.slot.empty {
  padding: 2px 10px;
}
.slot-row {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  min-height: 44px;
  align-items: center;
  padding: 6px 10px;
  margin-bottom: 14px;
  border-radius: 10px;
  background: #f1f3fb;
  border-bottom: 2px solid #3b50cc;
}
.slot-ph {
  font-size: 13px;
  color: #8a93b4;
}
.slot-chip {
  display: inline-block;
  margin: 0 3px 2px 0;
  padding: 2px 9px;
  border: none;
  border-radius: 8px;
  background: #3b50cc;
  color: #fff;
  font: inherit;
  font-size: 0.92em;
  line-height: 1.6;
  cursor: pointer;
}
.slot-chip.extra {
  background: #d98a2b;
}
.slot-chip:disabled {
  cursor: default;
}
.gq-chips {
  display: flex;
  flex-wrap: wrap;
  gap: 9px;
  margin-bottom: 14px;
}
.chip {
  min-height: 42px;
  padding: 8px 15px;
  border: 1.5px solid #d8dce1;
  border-radius: 99px;
  background: #fff;
  font-size: 15.5px;
  color: var(--ink);
  cursor: pointer;
}
.chip.used {
  opacity: 0.28;
  cursor: default;
}
.gq-extra {
  display: flex;
  gap: 8px;
  margin-bottom: 12px;
}
.gq-extra input {
  flex: 1;
  min-width: 0;
  padding: 9px 12px;
  border: 1.5px solid #e3e6ea;
  border-radius: 10px;
  font-size: 16px;
  outline: none;
}
.gq-extra input:focus {
  border-color: #3b50cc;
}

/* ボタン */
.gq-actions {
  display: flex;
  gap: 10px;
  justify-content: flex-end;
  margin-bottom: 4px;
}
.btn-ghost {
  padding: 11px 16px;
  border: 1px solid #e3e6ea;
  border-radius: 11px;
  background: #fff;
  color: #6b7280;
  font-size: 13.5px;
  font-weight: 600;
  cursor: pointer;
}
.btn-ghost:disabled {
  opacity: 0.45;
  cursor: default;
}
.btn-main {
  padding: 11px 22px;
  border: none;
  border-radius: 11px;
  background: #1c2024;
  color: #fff;
  font-size: 14px;
  font-weight: 700;
  cursor: pointer;
}
.btn-main:disabled {
  opacity: 0.35;
  cursor: default;
}
.dont-know {
  display: block;
  width: 100%;
  margin: 6px 0 0;
  padding: 10px;
  border: none;
  background: transparent;
  color: #9aa1ab;
  font-size: 12.5px;
  font-weight: 500;
  cursor: pointer;
}

/* 解答後 */
.gq-reveal {
  margin-top: 16px;
  padding-top: 16px;
  border-top: 1px solid #f0f1f3;
}
.rv-head {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 12px;
}
.rv-badge {
  display: inline-flex;
  align-items: center;
  padding: 5px 13px;
  border-radius: 99px;
  font-size: 13px;
  font-weight: 700;
}
.rv-badge.ok {
  background: #eaf7ef;
  color: #1f7a45;
}
.rv-badge.ng {
  background: #fdeef0;
  color: #c0444f;
}
.rv-judge {
  border: 1px solid #e3e6ea;
  background: #fff;
  border-radius: 8px;
  padding: 5px 10px;
  font-size: 11.5px;
  font-weight: 600;
  color: #4b5563;
  cursor: pointer;
}
.rv-speak {
  margin-left: auto;
  width: 34px;
  height: 34px;
  border: 1px solid #e3e6ea;
  border-radius: 9px;
  background: #fff;
  color: #6b7280;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
}
.rv-ans {
  display: flex;
  align-items: baseline;
  gap: 10px;
  flex-wrap: wrap;
  font-size: 16px;
  line-height: 1.7;
}
.rv-lab {
  flex-shrink: 0;
  font-size: 11px;
  font-weight: 700;
  color: #5b6b8c;
  background: #eef1f6;
  padding: 1px 8px;
  border-radius: 99px;
  align-self: center;
}
.rv-strong {
  font-weight: 700;
  color: var(--primary);
}
.rv-fix {
  font-weight: 700;
  color: #1f7a45;
}
.rv-sentence {
  color: var(--ink);
}
.rv-note {
  margin-top: 4px;
  font-size: 12.5px;
  color: #b7681a;
}
.rv-mine {
  display: flex;
  align-items: baseline;
  gap: 10px;
  margin-top: 8px;
  font-size: 14px;
  color: #c0444f;
}
.rv-trans {
  margin-top: 10px;
  font-size: 14px;
  color: #4b5563;
  line-height: 1.7;
}
.rv-foot {
  display: flex;
  gap: 14px;
  flex-wrap: wrap;
  margin-top: 8px;
  font-size: 12px;
  color: var(--faint);
}
.rv-remark {
  color: #b7681a;
}

/* 前へ・次へ */
.gq-nav {
  position: sticky;
  bottom: 0;
  display: flex;
  gap: 10px;
  margin: 14px -24px 0;
  padding: 12px 24px calc(14px + env(safe-area-inset-bottom));
  border-radius: 0 0 18px 18px;
  background: linear-gradient(to bottom, rgba(255, 255, 255, 0), #fff 30%);
}
.gq-nav .btn-main {
  flex: 1;
  padding: 13px;
}

/* 指で操作する端末は押しやすい大きさに */
@media (pointer: coarse) {
  .gq-choice {
    min-height: 54px;
    font-size: 16px;
  }
  .chip {
    min-height: 46px;
    font-size: 16px;
  }
  .num {
    width: 50px;
    height: 50px;
  }
  .seg {
    padding: 5px 6px 3px;
  }
  .slot-chip {
    padding: 4px 11px;
  }
  .btn-ghost,
  .btn-main {
    min-height: 46px;
  }
}
@media (max-width: 600px) {
  .gq {
    padding: 16px 16px 0;
  }
  .gq-sent {
    font-size: 17px;
  }
  .gq-nav {
    margin: 14px -16px 0;
    padding-left: 16px;
    padding-right: 16px;
  }
  .gq-actions {
    justify-content: stretch;
  }
  .gq-actions .btn-main {
    flex: 1;
  }
}
</style>
