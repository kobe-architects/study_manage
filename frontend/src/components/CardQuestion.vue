<script setup lang="ts">
/**
 * 学習カード 1 枚を形式ごとに表示して解答させるカード（全画面出題 QuizFullscreen の中で使う）。
 * 原則 4 択（lib/cardChoices.ts で組み立てる。小問が複数あるカードはまとめて答えて「解答」）:
 *   和訳（translate）    … 英文を見て、正しい和訳を 4 つから選ぶ
 *   空所補充（fill）     … 和訳と、太字の語を空所にした英文。空所ごとに入る語句を 4 つから選ぶ
 *   意味→表現（ja2en）  … 囲みの行ごとに、意味を見て英語の表現を 4 つから選ぶ（囲み 1 つが 1 枚のカード）
 *   表現→意味（en2ja）  … 囲みの行ごとに、表現を見て意味を 4 つから選ぶ
 *   問題（image）        … 例題の画像を見て解き、小問ごとに答えを 4 つから選ぶ。「詳細を表示」で解説の画像
 * 全小問が正解のときだけ正解。4 択にできないカード（和訳の無い例文・表だけの囲み・答えが読めなかった問題）は
 * 従来どおり「答えを見る」→ ○／× の自己判定（○／× は画面下部の固定領域 #qf-fixed に Teleport）。
 */
import { computed, onMounted, ref, watch } from 'vue'
import AuthImage from '@/components/AuthImage.vue'
import { cardsApi } from '@/api/cards'
import { blankText, buildSubQuestions, normalizeText, type SubQuestion } from '@/lib/cardChoices'
import { speak } from '@/lib/design'
import { haptic } from '@/lib/native'
import type { CardItem, CardMode } from '@/types'

export interface CardAnswerResult {
  isCorrect: boolean
  answer: string | null
  overridden: boolean
  unsure: boolean
}

const props = defineProps<{ item: CardItem; mode: CardMode; result: CardAnswerResult | null; pool: CardItem[] }>()
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
const gaveUp = ref(false)
const showDetail = ref(false)

/** 4 択の小問（組み立てられないときは null → 自己判定） */
const subs = computed<SubQuestion[] | null>(() => buildSubQuestions(it.value, props.mode, props.pool))
const selfJudge = computed(() => subs.value === null)
const picked = ref<Record<string, string>>({})

const isMath = computed(() => it.value.kind === 'problem')
const isBox = computed(() => it.value.kind === 'phrase' && !!subs.value)
const isFill = computed(() => it.value.kind === 'example' && props.mode === 'fill' && !!subs.value)
const isTranslate = computed(() => it.value.kind === 'example' && props.mode === 'translate' && !!subs.value)

/** 空所補充: front を「文字列 or 空所」の並びにする */
const words = computed(() => it.value.front.split(/\s+/).filter(Boolean))
const blanks = computed<[number, number][]>(() => (it.value.extra?.blanks ?? []).filter((b) => b[0] <= b[1] && b[0] < words.value.length))
const pieces = computed(() => {
  const out: { text?: string; blank?: number }[] = []
  let i = 0
  let bi = 0
  while (i < words.value.length) {
    const b = blanks.value[bi]
    if (b && b[0] === i) {
      out.push({ blank: bi })
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
  return b ? blankText(it.value, b) : ''
}

function reset() {
  peeked.value = false
  gaveUp.value = false
  showDetail.value = false
  picked.value = {}
  emit('canSubmit', false)
}
watch(() => [it.value.id, props.mode], reset, { immediate: true })
onMounted(reset)

const allPicked = computed(() => !!subs.value && subs.value.every((s) => !!picked.value[s.key]))
watch(allPicked, (v) => emit('canSubmit', v))

function pick(s: SubQuestion, opt: string) {
  if (answered.value) return
  picked.value = { ...picked.value, [s.key]: opt }
  haptic()
}
function isRight(s: SubQuestion, opt: string): boolean {
  return normalizeText(opt) === normalizeText(s.answer)
}
function subCorrect(s: SubQuestion): boolean {
  const p = picked.value[s.key]
  return !!p && isRight(s, p)
}

/** 「解答」（4 択）／「答えを見る」（自己判定）。親の主ボタンから呼ぶ */
function submit() {
  if (answered.value) return
  if (subs.value) {
    if (!allPicked.value) return
    const ok = subs.value.every((s) => subCorrect(s))
    const ans = subs.value.map((s) => (s.label ? `${s.label} ${picked.value[s.key]}` : picked.value[s.key])).join(' / ')
    emit('answer', { isCorrect: ok, answer: ans })
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
  gaveUp.value = true
  emit('answer', { isCorrect: false, answer: 'わからない' })
}
function judge(ok: boolean) {
  if (answered.value || !peeked.value) return
  emit('answer', { isCorrect: ok, answer: null })
}
defineExpose({ submit, giveUp, reveal, peeked })

const kindLabel = computed(() => ({ phrase: '表現', example: '例文', problem: '問題' })[it.value.kind])
const showAnswer = computed(() => peeked.value || answered.value)
/** 自己判定の形式で表に出す文字 */
const frontText = computed(() => (props.mode === 'ja2en' ? (it.value.back ?? it.value.front) : it.value.front))
const backText = computed(() => (props.mode === 'ja2en' ? (it.value.back ? it.value.front : null) : it.value.back))
const isEnglishFront = computed(() => props.mode !== 'ja2en' || !it.value.back)
/** 囲みの見出し（セクション名から番号を除く） */
const boxTitle = computed(() => (it.value.section ?? '').replace(/^\d+\.\d+\s*/, ''))
const optionClass = (s: SubQuestion, opt: string) => ({
  on: picked.value[s.key] === opt && !answered.value,
  ok: answered.value && isRight(s, opt),
  ng: answered.value && picked.value[s.key] === opt && !isRight(s, opt),
  en: props.mode === 'ja2en' || isFill.value,
})
/** 選択肢が長い（和訳など）ときは 1 列に */
function longOptions(s: SubQuestion): boolean {
  return s.options.some((o) => o.length > 18)
}
</script>

<template>
  <div class="cq card">
    <div class="cq-top">
      <span class="cq-kind" :class="it.kind">{{ kindLabel }}</span>
      <span class="cq-no dm">No.{{ it.no }}</span>
      <span class="cq-ch">{{ it.chapter }}<template v-if="it.section"> ／ {{ it.section }}</template></span>
      <span v-if="it.bookPage" class="cq-pg">p.{{ it.bookPage }}</span>
    </div>

    <!-- ============ 問題（画像）: 4 択 or 自己判定 ============ -->
    <template v-if="isMath || mode === 'image'">
      <div v-if="it.extra?.label" class="cq-label">{{ it.extra.label }}</div>
      <AuthImage v-if="it.hasProblemImage" :src="cardsApi.imageUrl(it.id, 'problem', it.iv)" class="cq-img" alt="問題" />
      <p v-else class="cq-sent">{{ it.front }}</p>
      <template v-if="subs">
        <div v-for="s in subs" :key="s.key" class="cq-sub">
          <div class="cq-sub-head"><span v-if="s.label" class="cq-sub-lab dm">{{ s.label }}</span><span class="cq-sub-q">答えを選んでください</span></div>
          <div class="cq-opts" :class="{ one: longOptions(s) }">
            <button v-for="o in s.options" :key="o" class="cq-opt dm" :class="optionClass(s, o)" :disabled="answered" @click="pick(s, o)">{{ o }}</button>
          </div>
        </div>
        <p v-if="it.extra?.partial" class="cq-hint">※ 読み取れた小問だけ 4 択です。残りの小問は「詳細を表示」の解説で確認してください。</p>
        <div v-if="answered" class="cq-detail">
          <button class="rv-judge" @click="showDetail = !showDetail">{{ showDetail ? '詳細を隠す' : '詳細を表示' }}</button>
          <div v-if="showDetail" class="cq-ansbox">
            <div class="cq-anslab">解説</div>
            <AuthImage v-if="it.hasAnswerImage" :src="cardsApi.imageUrl(it.id, 'answer', it.iv)" class="cq-img" alt="解説" />
            <p v-else class="cq-sent">（解説の画像はありません）</p>
          </div>
        </div>
      </template>
      <div v-else-if="showAnswer" class="cq-ansbox">
        <div class="cq-anslab">解答・解説</div>
        <AuthImage v-if="it.hasAnswerImage" :src="cardsApi.imageUrl(it.id, 'answer', it.iv)" class="cq-img" alt="解答" />
        <p v-else class="cq-sent">{{ it.back ?? '解答の画像はありません' }}</p>
      </div>
      <p v-else class="cq-hint">答えを考えてから「答えを見る」で解説を表示し、○／× を付けてください（この問題は答えを読み取れなかったので 4 択ではありません）</p>
    </template>

    <!-- ============ 囲み（表現）: 行ごとに 4 択 ============ -->
    <template v-else-if="isBox && subs">
      <div v-if="boxTitle" class="cq-boxtitle">{{ boxTitle }}</div>
      <div v-for="s in subs" :key="s.key" class="cq-sub">
        <div class="cq-sub-head">
          <span v-if="s.label" class="cq-sub-lab dm">{{ s.label }}</span>
          <span class="cq-sub-q" :class="{ dm: mode === 'en2ja' }" @click="mode === 'en2ja' && speak(s.prompt ?? '')">{{ s.prompt }}</span>
        </div>
        <div class="cq-opts" :class="{ one: longOptions(s) }">
          <button v-for="o in s.options" :key="o" class="cq-opt" :class="optionClass(s, o)" :disabled="answered" @click="pick(s, o)">{{ o }}</button>
        </div>
      </div>
      <div v-if="answered" class="cq-ansbox">
        <div class="cq-anslab">囲みの内容</div>
        <table class="cq-rows">
          <tr v-for="(r, i) in it.extra?.rows ?? []" :key="i">
            <td class="dm" @click="speak(r.en)">{{ r.en }}</td>
            <td>{{ r.ja ?? '' }}</td>
          </tr>
        </table>
        <AuthImage v-if="it.hasAnswerImage" :src="cardsApi.imageUrl(it.id, 'answer', it.iv)" class="cq-img sm" alt="教材" />
      </div>
    </template>

    <!-- ============ 例文・空所補充: 空所ごとに 4 択 ============ -->
    <template v-else-if="isFill && subs">
      <p v-if="it.back" class="cq-ja">{{ it.back }}</p>
      <p class="cq-sent dm">
        <template v-for="(p, i) in pieces" :key="i">
          <template v-if="p.text !== undefined">{{ p.text }}{{ ' ' }}</template>
          <template v-else>
            <span v-if="answered" class="blank filled" :class="{ ng: !subCorrect(subs[p.blank!]!) }">{{ blankAnswer(p.blank!) }}</span>
            <span v-else class="blank hole">{{ blanks.length > 1 ? `(${p.blank! + 1})` : '' }}{{ picked[subs[p.blank!]!.key] ?? '＿＿＿' }}</span>{{ ' ' }}
          </template>
        </template>
        <button class="bare cq-spk" aria-label="読み上げ" title="読み上げ" @click="speak(it.front)">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M11 5L6 9H3v6h3l5 4z" /><path d="M15.5 8.5a5 5 0 0 1 0 7M18.5 5.5a9 9 0 0 1 0 13" /></svg>
        </button>
      </p>
      <div v-for="s in subs" :key="s.key" class="cq-sub">
        <div v-if="s.label" class="cq-sub-head"><span class="cq-sub-lab dm">{{ s.label }}</span><span class="cq-sub-q">に入る語句</span></div>
        <div class="cq-opts" :class="{ one: longOptions(s) }">
          <button v-for="o in s.options" :key="o" class="cq-opt dm" :class="optionClass(s, o)" :disabled="answered" @click="pick(s, o)">{{ o }}</button>
        </div>
      </div>
      <AuthImage v-if="answered && it.hasAnswerImage" :src="cardsApi.imageUrl(it.id, 'answer', it.iv)" class="cq-img sm" alt="教材" />
    </template>

    <!-- ============ 例文・和訳: 4 択 ============ -->
    <template v-else-if="isTranslate && subs">
      <p class="cq-sent dm" title="タップで読み上げ" @click="speak(it.front)">
        {{ it.front }}
        <button class="bare cq-spk" aria-label="読み上げ" @click.stop="speak(it.front)">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M11 5L6 9H3v6h3l5 4z" /><path d="M15.5 8.5a5 5 0 0 1 0 7M18.5 5.5a9 9 0 0 1 0 13" /></svg>
        </button>
      </p>
      <div v-for="s in subs" :key="s.key" class="cq-sub">
        <div class="cq-sub-head"><span class="cq-sub-q">正しい和訳を選んでください</span></div>
        <div class="cq-opts one">
          <button v-for="o in s.options" :key="o" class="cq-opt" :class="optionClass(s, o)" :disabled="answered" @click="pick(s, o)">{{ o }}</button>
        </div>
      </div>
      <AuthImage v-if="answered && it.hasAnswerImage" :src="cardsApi.imageUrl(it.id, 'answer', it.iv)" class="cq-img sm" alt="教材" />
    </template>

    <!-- ============ 自己判定（4 択にできないカード） ============ -->
    <template v-else>
      <p class="cq-sent" :class="{ dm: isEnglishFront, big: !isEnglishFront }" :title="isEnglishFront ? 'タップで読み上げ' : ''" @click="isEnglishFront && speak(frontText)">
        {{ frontText }}
        <button v-if="isEnglishFront" class="bare cq-spk" aria-label="読み上げ" @click.stop="speak(frontText)">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M11 5L6 9H3v6h3l5 4z" /><path d="M15.5 8.5a5 5 0 0 1 0 7M18.5 5.5a9 9 0 0 1 0 13" /></svg>
        </button>
      </p>
      <p v-if="!showAnswer" class="cq-hint">{{ it.kind === 'example' ? (mode === 'fill' ? '空所にする語の無い例文です。和訳を考えてから「答えを見る」' : '和訳を考えてから「答えを見る」') : mode === 'ja2en' ? '英語の表現を思い出してから「答えを見る」' : '意味を思い出してから「答えを見る」' }}（この項目は 4 択にできないので自己判定です）</p>
      <div v-if="showAnswer" class="cq-ansbox">
        <div class="cq-anslab">答え</div>
        <p v-if="backText" class="cq-ans" :class="{ dm: mode === 'ja2en' }" @click="mode === 'ja2en' && speak(backText)">{{ backText }}</p>
        <p v-else-if="!it.hasAnswerImage" class="cq-ans faint">（文字の答えはありません。教材で確認してください）</p>
        <AuthImage v-if="it.hasAnswerImage" :src="cardsApi.imageUrl(it.id, 'answer', it.iv)" class="cq-img sm" alt="教材" />
      </div>
    </template>

    <!-- ============ 解答後 ============ -->
    <div v-if="answered && result" class="cq-reveal">
      <div class="rv-top">
        <span class="rv-badge" :class="result.isCorrect ? (result.unsure ? 'un' : 'ok') : 'ng'">{{ result.isCorrect ? (result.unsure ? '正解（あいまい）' : '正解！') : gaveUp ? '不正解（わからない）' : '不正解' }}</span>
        <button v-if="result.isCorrect" class="rv-unsure" :class="{ on: result.unsure }" :aria-pressed="result.unsure" @click="emit('unsure', !result.unsure)">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l9 18H3z" /></svg>
          あいまい
        </button>
        <button v-if="subs && !gaveUp" class="rv-judge" :title="isMath ? '読み取った答えが違っていたときなどに' : ''" @click="emit('override', !result.isCorrect)">{{ result.isCorrect ? '不正解にする' : '正解にする' }}</button>
      </div>
      <div v-if="isMath && subs" class="cq-ja2">
        正解: <template v-for="(s, i) in subs" :key="s.key"><span v-if="i" class="sep">　</span><span v-if="s.label" class="dm">{{ s.label }} </span><span class="dm" style="font-weight: 700">{{ s.answer }}</span></template>
      </div>
      <div v-if="isFill && it.back" class="cq-ja2">{{ it.back }}</div>
    </div>

    <!-- ============ 下部固定: ○／×（自己判定のときだけ） ============ -->
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
.cq-label {
  display: inline-block;
  margin-bottom: 8px;
  padding: 2px 10px;
  border-radius: 99px;
  background: #fdeef0;
  color: #c0444f;
  font-size: 12px;
  font-weight: 700;
}
.cq-boxtitle {
  margin: 0 0 10px;
  font-size: 14px;
  font-weight: 700;
  color: #4b5563;
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
.blank {
  display: inline-block;
  padding: 0 6px;
  margin: 0 2px;
  border-radius: 6px;
  font-weight: 700;
}
.blank.hole {
  min-width: 60px;
  border-bottom: 2px solid #3b50cc;
  border-radius: 6px 6px 0 0;
  background: #f4f6fb;
  color: #3b50cc;
  text-align: center;
}
.blank.filled {
  background: #eaf7ef;
  color: #1f7a45;
}
.blank.filled.ng {
  background: #fdeef0;
  color: #c0444f;
}
/* ---- 小問と選択肢 ---- */
.cq-sub {
  margin-top: 16px;
}
.cq-sub-head {
  display: flex;
  align-items: baseline;
  gap: 8px;
  margin-bottom: 8px;
  font-size: 15px;
  line-height: 1.6;
  color: #1c2024;
}
.cq-sub-lab {
  font-weight: 700;
  color: #3b50cc;
  flex-shrink: 0;
}
.cq-sub-q {
  font-weight: 600;
}
.cq-sub-q.dm {
  font-size: 17px;
}
.cq-opts {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 8px;
}
.cq-opts.one {
  grid-template-columns: minmax(0, 1fr);
}
@media (max-width: 480px) {
  .cq-opts {
    grid-template-columns: minmax(0, 1fr);
  }
}
.cq-opt {
  min-height: 46px;
  padding: 9px 12px;
  border: 1.5px solid #e3e6ea;
  border-radius: 12px;
  background: #fff;
  color: #1c2024;
  font-size: 15px;
  line-height: 1.5;
  text-align: left;
  cursor: pointer;
  word-break: break-word;
  font-family: inherit;
}
.cq-opt.en {
  font-size: 16px;
}
.cq-opt.on {
  border-color: #3b50cc;
  background: #eef1fb;
  color: #2b3fb0;
  font-weight: 700;
}
.cq-opt.ok {
  border-color: #9ed4b3;
  background: #eaf7ef;
  color: #1f7a45;
  font-weight: 700;
}
.cq-opt.ng {
  border-color: #f0b8be;
  background: #fdeef0;
  color: #c0444f;
}
.cq-opt:disabled {
  cursor: default;
}
.cq-detail {
  margin-top: 14px;
}
.cq-rows {
  width: 100%;
  border-collapse: collapse;
  font-size: 14px;
}
.cq-rows td {
  padding: 5px 6px;
  border-top: 1px solid #f0f1f3;
  vertical-align: top;
  line-height: 1.5;
}
.cq-rows td:first-child {
  width: 45%;
  font-weight: 600;
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
  font-family: inherit;
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
