<script setup lang="ts">
/**
 * 学習カード 1 枚を形式ごとに表示して解答させるカード（全画面出題 QuizFullscreen の中で使う）。
 *   和訳（translate）    … 英文を見て和訳を考える → 「答えを見る」で和訳（と教材の画像）→ ○／× を自分で付ける
 *   空所補充（fill）     … 和訳と、太字の語を空所にした英文。空所に入力して「解答」（照合は小文字・句読点を除いて比較）。
 *                           合わないときは「正解にする」で自己判定できる。空所が無いカードは英作文として自己判定
 *   意味→表現（ja2en）  … 意味を見て英語の表現を思い出す → 「答えを見る」で表現（と囲みの画像）→ ○／×
 *   表現→意味（en2ja）  … 英語の表現を見て意味を思い出す → 「答えを見る」で意味（と囲みの画像）→ ○／×
 *   問題（image）        … 問題の画像を見て解く → 「答えを見る」で解答の画像 → ○／×
 * ○／× のボタンは画面下部の固定領域（#qf-fixed に Teleport）に出す。
 */
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import AuthImage from '@/components/AuthImage.vue'
import { cardsApi } from '@/api/cards'
import { speak } from '@/lib/design'
import { haptic } from '@/lib/native'
import type { CardItem, CardMode } from '@/types'

export interface CardAnswerResult {
  isCorrect: boolean
  answer: string | null
  overridden: boolean
  unsure: boolean
}

const props = defineProps<{ item: CardItem; mode: CardMode; result: CardAnswerResult | null }>()
const emit = defineEmits<{
  answer: [payload: { isCorrect: boolean; answer: string | null }]
  canSubmit: [ok: boolean]
  unsure: [unsure: boolean]
  override: [isCorrect: boolean]
  /** 「答えを見る」を押した（親のボタン表示の切替用） */
  revealed: []
}>()

const it = computed(() => props.item)
const answered = computed(() => !!props.result)
const peeked = ref(false) // 答えを見た（自己判定の形式）
const inputs = ref<string[]>([])
const blankEls = ref<HTMLInputElement[]>([])

/** front を語に分ける（空所補充の単位） */
const words = computed(() => it.value.front.split(/\s+/).filter(Boolean))
const blanks = computed<[number, number][]>(() => (it.value.extra?.blanks ?? []).filter((b) => b[0] <= b[1] && b[0] < words.value.length))
/** 空所補充として出せるか（太字の語がある） */
const canFill = computed(() => props.mode === 'fill' && blanks.value.length > 0)
/** 自己判定の形式（答えを見てから ○／×） */
const selfJudge = computed(() => !canFill.value)

/** 文を「文字列 or 空所」の並びにする */
const pieces = computed(() => {
  const out: { text?: string; blank?: number; from?: number; to?: number }[] = []
  let i = 0
  let bi = 0
  while (i < words.value.length) {
    const b = blanks.value[bi]
    if (b && b[0] === i) {
      out.push({ blank: bi, from: b[0], to: Math.min(b[1], words.value.length - 1) })
      i = Math.min(b[1], words.value.length - 1) + 1
      bi++
    } else {
      const start = i
      while (i < words.value.length && !(blanks.value[bi] && blanks.value[bi]![0] === i)) i++
      out.push({ text: words.value.slice(start, i).join(' ') })
    }
  }
  return out
})
function blankAnswer(bi: number): string {
  const b = blanks.value[bi]
  if (!b) return ''
  return words.value.slice(b[0], Math.min(b[1], words.value.length - 1) + 1).join(' ')
}
function norm(s: string): string {
  return s
    .toLowerCase()
    .replace(/[’‘]/g, "'")
    .replace(/[“”]/g, '"')
    .replace(/[.,!?;:"]/g, ' ')
    .replace(/\s+/g, ' ')
    .trim()
}

function reset() {
  peeked.value = false
  inputs.value = blanks.value.map(() => '')
  blankEls.value = []
  emit('canSubmit', false)
  if (canFill.value) nextTick(() => blankEls.value[0]?.focus())
}
watch(() => [it.value.id, props.mode], reset, { immediate: true })
onMounted(reset)
watch(inputs, () => emit('canSubmit', canFill.value && inputs.value.every((v) => v.trim() !== '')), { deep: true })

/** 「答えを見る」（自己判定）／「解答」（空所補充）。親の主ボタンから呼ぶ */
function submit() {
  if (answered.value) return
  if (canFill.value) {
    const ok = blanks.value.every((_, i) => norm(inputs.value[i] ?? '') === norm(blankAnswer(i)))
    emit('answer', { isCorrect: ok, answer: inputs.value.join(' / ') })
    return
  }
  reveal()
}
function reveal() {
  if (peeked.value || answered.value) return
  peeked.value = true
  haptic()
  emit('revealed')
}
/** わからない: 答えを見せて不正解にする */
function giveUp() {
  if (answered.value) return
  peeked.value = true
  emit('answer', { isCorrect: false, answer: 'わからない' })
}
function judge(ok: boolean) {
  if (answered.value || !peeked.value) return
  emit('answer', { isCorrect: ok, answer: null })
}
function onEnter(i: number) {
  const next = blankEls.value[i + 1]
  if (next) next.focus()
  else if (inputs.value.every((v) => v.trim() !== '')) submit()
}
defineExpose({ submit, giveUp, reveal, peeked })

const kindLabel = computed(() => ({ phrase: '表現', example: '例文', problem: '問題' })[it.value.kind])
const showAnswer = computed(() => peeked.value || answered.value)
/** 表に出す文字（形式ごと） */
const frontText = computed(() => {
  if (props.mode === 'ja2en') return it.value.back ?? it.value.front
  return it.value.front
})
const backText = computed(() => {
  if (props.mode === 'ja2en') return it.value.back ? it.value.front : null
  return it.value.back
})
const isEnglishFront = computed(() => props.mode !== 'ja2en' || !it.value.back)
</script>

<template>
  <div class="cq card">
    <div class="cq-top">
      <span class="cq-kind" :class="it.kind">{{ kindLabel }}</span>
      <span class="cq-no dm">No.{{ it.no }}</span>
      <span class="cq-ch">{{ it.chapter }}<template v-if="it.section"> ／ {{ it.section }}</template></span>
      <span v-if="it.bookPage" class="cq-pg">p.{{ it.bookPage }}</span>
    </div>

    <!-- ============ 問題（画像） ============ -->
    <template v-if="mode === 'image' || it.kind === 'problem'">
      <AuthImage v-if="it.hasProblemImage" :src="cardsApi.imageUrl(it.id, 'problem')" class="cq-img" alt="問題" />
      <p v-else class="cq-sent">{{ it.front }}</p>
      <div v-if="showAnswer" class="cq-ansbox">
        <div class="cq-anslab">解答</div>
        <AuthImage v-if="it.hasAnswerImage" :src="cardsApi.imageUrl(it.id, 'answer')" class="cq-img" alt="解答" />
        <p v-else class="cq-sent">{{ it.back ?? '解答の画像はありません' }}</p>
      </div>
    </template>

    <!-- ============ 空所補充 ============ -->
    <template v-else-if="canFill">
      <p v-if="it.back" class="cq-ja">{{ it.back }}</p>
      <p class="cq-sent dm">
        <template v-for="(p, i) in pieces" :key="i">
          <template v-if="p.text !== undefined">{{ p.text }}{{ ' ' }}</template>
          <template v-else>
            <span v-if="answered" class="blank filled" :class="{ ng: norm(inputs[p.blank!] ?? '') !== norm(blankAnswer(p.blank!)) }">{{ blankAnswer(p.blank!) }}</span>
            <input
              v-else
              :ref="(el) => { if (el) blankEls[p.blank!] = el as HTMLInputElement }"
              v-model="inputs[p.blank!]"
              class="cq-blank dm"
              :style="{ width: Math.max(70, blankAnswer(p.blank!).length * 11 + 24) + 'px' }"
              :placeholder="blanks.length > 1 ? `(${p.blank! + 1})` : ''"
              autocapitalize="off" autocorrect="off" autocomplete="off" spellcheck="false"
              :enterkeyhint="p.blank === blanks.length - 1 ? 'done' : 'next'"
              @keydown.enter.prevent="onEnter(p.blank!)"
            />{{ ' ' }}
          </template>
        </template>
        <button class="bare cq-spk" aria-label="読み上げ" title="読み上げ" @click="speak(it.front)">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M11 5L6 9H3v6h3l5 4z" /><path d="M15.5 8.5a5 5 0 0 1 0 7M18.5 5.5a9 9 0 0 1 0 13" /></svg>
        </button>
      </p>
      <div v-if="answered && result && !result.isCorrect && result.answer && result.answer !== 'わからない'" class="cq-mine">あなたの答え: <span class="dm">{{ result.answer }}</span></div>
    </template>

    <!-- ============ 和訳・意味→表現・表現→意味（自己判定） ============ -->
    <template v-else>
      <p class="cq-sent" :class="{ dm: isEnglishFront, big: !isEnglishFront }" :title="isEnglishFront ? 'タップで読み上げ' : ''" @click="isEnglishFront && speak(frontText)">
        {{ frontText }}
        <button v-if="isEnglishFront" class="bare cq-spk" aria-label="読み上げ" @click.stop="speak(frontText)">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M11 5L6 9H3v6h3l5 4z" /><path d="M15.5 8.5a5 5 0 0 1 0 7M18.5 5.5a9 9 0 0 1 0 13" /></svg>
        </button>
      </p>
      <p v-if="!showAnswer" class="cq-hint">{{ mode === 'translate' ? '和訳を考えてから「答えを見る」' : mode === 'ja2en' ? '英語の表現を思い出してから「答えを見る」' : '意味を思い出してから「答えを見る」' }}</p>
      <div v-if="showAnswer" class="cq-ansbox">
        <div class="cq-anslab">答え</div>
        <p v-if="backText" class="cq-ans" :class="{ dm: mode === 'ja2en' }" @click="mode === 'ja2en' && speak(backText)">{{ backText }}</p>
        <p v-else-if="!it.hasAnswerImage" class="cq-ans faint">（文字の答えはありません。教材で確認してください）</p>
        <AuthImage v-if="it.hasAnswerImage" :src="cardsApi.imageUrl(it.id, 'answer')" class="cq-img sm" alt="教材" />
      </div>
    </template>

    <!-- ============ 解答後 ============ -->
    <div v-if="answered && result" class="cq-reveal">
      <div class="rv-top">
        <span class="rv-badge" :class="result.isCorrect ? (result.unsure ? 'un' : 'ok') : 'ng'">{{ result.isCorrect ? (result.unsure ? '正解（あいまい）' : '正解！') : '不正解' }}</span>
        <button v-if="result.isCorrect" class="rv-unsure" :class="{ on: result.unsure }" :aria-pressed="result.unsure" @click="emit('unsure', !result.unsure)">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l9 18H3z" /></svg>
          あいまい
        </button>
        <button v-if="canFill" class="rv-judge" @click="emit('override', !result.isCorrect)">{{ result.isCorrect ? '不正解にする' : '正解にする' }}</button>
      </div>
      <div v-if="canFill && it.back" class="cq-ja2">{{ it.back }}</div>
      <AuthImage v-if="canFill && it.hasAnswerImage" :src="cardsApi.imageUrl(it.id, 'answer')" class="cq-img sm" alt="教材" />
    </div>

    <!-- ============ 下部固定: ○／× ============ -->
    <Teleport v-if="selfJudge" defer to="#qf-fixed">
      <div class="cq-judge">
        <template v-if="peeked && !answered">
          <button class="judge ok" @click="judge(true)">○ 正解だった</button>
          <button class="judge ng" @click="judge(false)">× 間違えた</button>
        </template>
        <span v-else-if="!answered" class="cq-judge-hint">答えを考えてから、下の「答えを見る」で答えを表示して ○／× を付けてください</span>
      </div>
    </Teleport>
  </div>
</template>

<style scoped>
.cq {
  padding: 22px 22px 24px;
  border-radius: 18px;
}
.cq-top {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  margin-bottom: 14px;
  font-size: 11.5px;
  color: var(--faint);
}
.cq-kind {
  padding: 2px 8px;
  border-radius: 99px;
  font-size: 11px;
  font-weight: 700;
  background: #eef1f6;
  color: #5b6b8c;
}
.cq-kind.example {
  background: #eef7f0;
  color: #3a8a5c;
}
.cq-kind.problem {
  background: #fff4e5;
  color: #b7681a;
}
.cq-no {
  font-weight: 700;
  color: #9aa1ab;
}
.cq-ch {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  max-width: 100%;
}
.cq-pg {
  margin-left: auto;
}
.cq-sent {
  margin: 0;
  font-size: 19px;
  line-height: 1.75;
  color: #1c2024;
  word-break: break-word;
}
.cq-sent.big {
  font-size: 20px;
  font-weight: 600;
}
.cq-ja {
  margin: 0 0 10px;
  font-size: 15px;
  line-height: 1.7;
  color: #4b5563;
}
.cq-ja2 {
  margin-top: 8px;
  font-size: 14px;
  color: #4b5563;
  line-height: 1.6;
}
.cq-hint {
  margin: 12px 0 0;
  font-size: 12px;
  color: var(--faint);
}
.cq-spk {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 30px;
  height: 30px;
  margin-left: 4px;
  border-radius: 8px;
  color: #6b7280;
  vertical-align: middle;
}
.cq-spk:active {
  background: #f1f2f4;
}
.cq-blank {
  display: inline-block;
  min-height: 36px;
  padding: 4px 8px;
  margin: 2px 2px;
  border: none;
  border-bottom: 2px solid #3b50cc;
  border-radius: 6px 6px 0 0;
  background: #f4f6fb;
  font-size: 17px;
  text-align: center;
  outline: none;
  vertical-align: baseline;
}
.cq-blank:focus {
  background: #e9edfb;
}
.blank {
  display: inline-block;
  padding: 0 6px;
  margin: 0 2px;
  border-radius: 6px;
  font-weight: 700;
}
.blank.filled {
  background: #eaf7ef;
  color: #1f7a45;
}
.blank.filled.ng {
  background: #fdeef0;
  color: #c0444f;
}
.cq-mine {
  margin-top: 10px;
  font-size: 13px;
  color: #6b7280;
}
.cq-ansbox {
  margin-top: 16px;
  padding-top: 14px;
  border-top: 1px dashed #e3e6ea;
}
.cq-anslab {
  font-size: 11px;
  font-weight: 700;
  color: #9aa1ab;
  margin-bottom: 6px;
}
.cq-ans {
  margin: 0 0 10px;
  font-size: 17px;
  line-height: 1.7;
  color: #1c2024;
  font-weight: 600;
}
.cq-ans.faint {
  color: var(--faint);
  font-weight: 400;
  font-size: 13px;
}
.cq-img {
  display: block;
  width: 100%;
  height: auto;
  border-radius: 8px;
  border: 1px solid #eceef1;
}
.cq-img.sm {
  margin-top: 6px;
}
.cq-reveal {
  margin-top: 16px;
  padding-top: 14px;
  border-top: 1px solid #f0f1f3;
}
.rv-top {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
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
.rv-badge.un {
  background: #e8effb;
  color: #2f5fc4;
}
.rv-unsure,
.rv-judge {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  min-height: 34px;
  padding: 5px 12px;
  border: 1.5px solid #c9d6f2;
  border-radius: 99px;
  background: #fff;
  color: #2f5fc4;
  font-size: 12px;
  font-weight: 700;
  cursor: pointer;
}
.rv-unsure.on {
  background: #2f5fc4;
  border-color: #2f5fc4;
  color: #fff;
}
.rv-judge {
  border-color: #e3e6ea;
  color: #4b5563;
}
.cq-judge {
  display: flex;
  gap: 10px;
  align-items: center;
  flex-wrap: wrap;
}
.cq-judge-hint {
  font-size: 11.5px;
  color: var(--faint);
}
.judge {
  flex: 1;
  min-height: 50px;
  border: 1.5px solid #e3e6ea;
  border-radius: 12px;
  background: #fff;
  font-size: 15px;
  font-weight: 700;
  cursor: pointer;
}
.judge.ok {
  border-color: #9ed4b3;
  color: #1f7a45;
  background: #f3faf6;
}
.judge.ng {
  border-color: #f0b8be;
  color: #c0444f;
  background: #fef6f7;
}
</style>
