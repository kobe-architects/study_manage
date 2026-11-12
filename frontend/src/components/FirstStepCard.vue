<script setup lang="ts">
/**
 * 英文法ファーストステップ: 1 つの大問の小問をまとめて出題するカード（全画面出題 QuizFullscreen の中で使う）。
 * 問題は大問の画像（見出し〜語群）。小問ごとに
 *   記号選択（choices あり）… ア〜エ のボタンから選ぶ
 *   記入（answers あり）  … 入力欄に答える（空所が複数なら (1)(2)…）
 *   記述（どちらも無い）  … 解答を見てから ○／× を自分で付ける
 * 「解答」（親が submit() を呼ぶ）で記号選択・記入を採点し、解答編の答えの画像を表示する。記述はそのあと小問ごとに ○／×。
 * 採点後の ○／× はタップで付け替えられる（自己判定）。「あいまい」は正解だった小問すべてに付く。
 * 記録（attempt）は小問ごと（親の onAnswer）。
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import AuthImage from '@/components/AuthImage.vue'
import type { GrammarAnswerResult } from '@/components/GrammarQuestionCard.vue'
import { grammarApi } from '@/api/grammar'
import { CIRCLED, gradeFill } from '@/lib/grammar'
import { haptic } from '@/lib/native'
import type { GrammarQuestion } from '@/types'

const props = defineProps<{
  /** 大問の小問（連番順） */
  questions: GrammarQuestion[]
  /** 小問ごとの結果（問題 id → 結果） */
  results: Record<number, GrammarAnswerResult | undefined>
  /** 「解答」を押した（答えを表示している） */
  revealed: boolean
}>()
const emit = defineEmits<{
  answer: [q: GrammarQuestion, payload: { isCorrect: boolean; answer: string | null }]
  /** 採点後の ○／× の付け替え */
  override: [q: GrammarQuestion, isCorrect: boolean]
  /** 「あいまい」の付け外し（正解の小問すべて） */
  unsure: [unsure: boolean]
  canSubmit: [ok: boolean]
  reveal: []
}>()

type Kind = 'choice' | 'typed' | 'self'
function kindOf(q: GrammarQuestion): Kind {
  if (q.choiceCount && q.answerIndex !== null) return 'choice'
  if (q.answers?.length) return 'typed'
  return 'self'
}
const first = computed(() => props.questions[0]!)
/** 「p.5 STEP 1・1A〜C (1)」→ 大問「p.5 STEP 1・1A〜C」と小問「(1)」 */
function splitSource(q: GrammarQuestion): { group: string; sub: string } {
  const s = q.source ?? ''
  const m = s.match(/^(.*)\s(\(\d+\))$/)
  return m ? { group: m[1]!, sub: m[2]! } : { group: s, sub: '' }
}
const groupLabel = computed(() => splitSource(first.value).group)
const subs = computed(() =>
  props.questions.map((q) => ({
    q,
    kind: kindOf(q),
    label: splitSource(q).sub,
    options: q.choices ?? Array.from({ length: q.choiceCount ?? 0 }, (_, i) => CIRCLED[i] ?? String(i + 1)),
    blanks: q.answers?.length ?? 1,
    answerLabel: kindOf(q) === 'choice' ? (q.choices?.[q.answerIndex ?? 0] ?? '') : kindOf(q) === 'typed' ? (q.answers ?? []).map((a) => a.join(' / ')).join('，') : (q.answerText ?? ''),
  })),
)

// ---- 入力状態 ----
const picks = ref<Record<number, number | null>>({})
const inputs = ref<Record<number, string[]>>({})
const inputEls = ref<Record<string, HTMLInputElement>>({})
function reset() {
  const p: Record<number, number | null> = {}
  const t: Record<number, string[]> = {}
  for (const s of subs.value) {
    p[s.q.id] = null
    t[s.q.id] = Array(s.blanks).fill('')
    // 解答済みに戻ってきたときは選んだ答えを復元
    const a = props.results[s.q.id]?.answer
    if (a && a !== 'わからない') {
      if (s.kind === 'choice') p[s.q.id] = s.options.indexOf(a)
      if (s.kind === 'typed') {
        const parts = a.split(' / ')
        if (parts.length === s.blanks) t[s.q.id] = parts
      }
    }
  }
  picks.value = p
  inputs.value = t
}
// 描画より先に入力欄の状態を用意する（テンプレートが inputs[id] を参照するため immediate）
watch(() => first.value.id, reset, { immediate: true })

function pick(q: GrammarQuestion, i: number) {
  if (props.revealed) return
  picks.value = { ...picks.value, [q.id]: i }
  haptic()
}
const filled = computed(() =>
  subs.value.every((s) => (s.kind === 'choice' ? picks.value[s.q.id] !== null && picks.value[s.q.id] !== undefined : s.kind === 'typed' ? (inputs.value[s.q.id] ?? []).every((v) => v.trim() !== '') : true)),
)
const canSubmit = computed(() => !props.revealed && filled.value)
watch(canSubmit, (v) => emit('canSubmit', v), { immediate: true })

/** 「解答」: 記号選択・記入を採点して答えを表示する */
function submit() {
  if (!canSubmit.value) return
  for (const s of subs.value) {
    if (props.results[s.q.id]) continue
    if (s.kind === 'choice') {
      const i = picks.value[s.q.id] ?? -1
      emit('answer', s.q, { isCorrect: i === s.q.answerIndex, answer: s.options[i] ?? null })
    } else if (s.kind === 'typed') {
      const v = (inputs.value[s.q.id] ?? []).map((x) => x.trim())
      emit('answer', s.q, { isCorrect: gradeFill(s.q, v), answer: v.join(' / ') })
    }
  }
  emit('reveal')
  haptic()
}
/** 「わからない」: 未解答の小問をすべて不正解にして答えを表示する */
function giveUp() {
  if (props.revealed) return
  for (const s of subs.value) {
    if (!props.results[s.q.id]) emit('answer', s.q, { isCorrect: false, answer: 'わからない' })
  }
  emit('reveal')
}
/** 記述の自己判定、または採点後の付け替え */
function judge(q: GrammarQuestion, ok: boolean) {
  const r = props.results[q.id]
  if (!r) emit('answer', q, { isCorrect: ok, answer: ok ? '○（自己判定）' : '×（自己判定）' })
  else if (r.isCorrect !== ok) emit('override', q, ok)
  haptic()
}
defineExpose({ submit, giveUp })

const anyCorrect = computed(() => subs.value.some((s) => props.results[s.q.id]?.isCorrect))
const unsureOn = computed(() => subs.value.some((s) => props.results[s.q.id]?.isCorrect && props.results[s.q.id]?.unsure))
/** 入力欄の Enter: 次の空欄へ、全部埋まっていれば解答 */
function onEnter(key: string) {
  const keys = Object.keys(inputEls.value)
  const i = keys.indexOf(key)
  for (let k = 1; k < keys.length; k++) {
    const nk = keys[(i + k) % keys.length]!
    const el = inputEls.value[nk]
    if (el && el.value.trim() === '') {
      el.focus()
      return
    }
  }
  if (canSubmit.value) submit()
}
function onKey(e: KeyboardEvent) {
  const t = e.target as HTMLElement | null
  if (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA')) return
  if (e.key === 'Enter' && canSubmit.value) {
    e.preventDefault()
    submit()
  }
}
onMounted(() => window.addEventListener('keydown', onKey))
onBeforeUnmount(() => window.removeEventListener('keydown', onKey))
</script>

<template>
  <div class="fs card">
    <div class="fs-meta">
      <span class="dm fs-no">No.{{ first.no }}<template v-if="questions.length > 1">–{{ questions[questions.length - 1]!.no }}</template></span>
      <span class="fs-ch">{{ first.chapter }}</span>
      <span class="fs-src">{{ groupLabel }}</span>
      <span v-if="first.importance" class="fs-sec">{{ first.importance }}</span>
    </div>

    <!-- スマホでは画像を縮めず横スクロールで読む（縮めると字が読めない） -->
    <div v-if="first.hasProblemImage" class="fs-imgwrap">
      <AuthImage :src="grammarApi.imageUrl(first.id, 'problem')" class="fs-img wide" alt="問題" />
    </div>
    <div class="fs-scrollhint">横にスクロールして読めます</div>

    <!-- 小問ごとの解答欄 -->
    <div class="fs-subs">
      <div v-for="s in subs" :key="s.q.id" class="fs-sub" :class="{ done: !!results[s.q.id] }">
        <span class="fs-lab dm">{{ s.label || '答え' }}</span>
        <div class="fs-body">
          <!-- 記号選択 -->
          <div v-if="s.kind === 'choice'" class="fs-opts">
            <button
              v-for="(o, i) in s.options"
              :key="i"
              class="fs-opt"
              :class="revealed ? { ok: i === s.q.answerIndex, ng: picks[s.q.id] === i && i !== s.q.answerIndex, dim: i !== s.q.answerIndex && picks[s.q.id] !== i } : { sel: picks[s.q.id] === i }"
              :disabled="revealed"
              @click="pick(s.q, i)"
            >
              {{ o }}
            </button>
          </div>
          <!-- 記入 -->
          <div v-else-if="s.kind === 'typed'" class="fs-inputs">
            <template v-for="b in s.blanks" :key="b">
              <span v-if="s.blanks > 1" class="fs-bn">({{ b }})</span>
              <template v-if="revealed">
                <span class="fs-typed dm" :class="results[s.q.id]?.isCorrect ? 'ok' : 'ng'">{{ (inputs[s.q.id] ?? [])[b - 1] || '—' }}</span>
              </template>
              <input
                v-else
                :ref="(el) => { if (el) inputEls[`${s.q.id}-${b}`] = el as HTMLInputElement }"
                v-model="inputs[s.q.id]![b - 1]"
                class="fs-input dm"
                placeholder="答えを入力"
                autocapitalize="off"
                autocorrect="off"
                autocomplete="off"
                spellcheck="false"
                enterkeyhint="next"
                @keydown.enter.prevent="onEnter(`${s.q.id}-${b}`)"
              />
            </template>
          </div>
          <!-- 記述: 解答を見てから ○／× -->
          <div v-else class="fs-self">
            <span v-if="!revealed" class="fs-hint">解答を見てから ○／× を付けます</span>
          </div>
          <!-- 採点後: 正解と ○／× -->
          <div v-if="revealed" class="fs-res">
            <!-- 記述の答えは OCR の文字でなく下の解答編の画像で見る -->
            <span v-if="s.kind !== 'self' && s.answerLabel" class="fs-ans"><span class="fs-anslab">正解</span><span class="dm fs-anstext">{{ s.answerLabel }}</span></span>
            <span v-else class="fs-ans"><span class="fs-anslab">正解</span><span class="fs-anstext muted">下の解答（解答編）の {{ s.label || '答え' }} を見て ○／×</span></span>
            <span class="fs-judge">
              <button class="jb ok" :class="{ on: results[s.q.id]?.isCorrect === true }" @click="judge(s.q, true)">○</button>
              <button class="jb ng" :class="{ on: results[s.q.id]?.isCorrect === false }" @click="judge(s.q, false)">×</button>
            </span>
          </div>
        </div>
      </div>
    </div>

    <!-- 採点後: 解答編の答え（画像）と「あいまい」 -->
    <div v-if="revealed" class="fs-reveal">
      <div class="fs-anshead">
        <span class="fs-anslab">解答（解答編）</span>
        <button v-if="anyCorrect" class="fs-unsure" :class="{ on: unsureOn }" :aria-pressed="unsureOn" @click="emit('unsure', !unsureOn)">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l9 18H3z" /></svg>
          あいまい
        </button>
      </div>
      <div v-if="first.hasAnswerImage" class="fs-imgwrap">
        <AuthImage :src="grammarApi.imageUrl(first.id, 'answer')" class="fs-img wide" alt="解答" />
      </div>
      <div v-else class="fs-hint">この大問の解答画像はありません</div>
      <div class="fs-hint" style="margin-top: 6px">○／× はタップで付け替えられます。解説は下の「解説」</div>
    </div>
  </div>
</template>

<style scoped>
.fs {
  padding: 18px 20px 20px;
  border-radius: 18px;
}
.fs-meta {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  margin-bottom: 10px;
  font-size: 12px;
  color: var(--faint);
}
.fs-no {
  font-weight: 700;
  color: var(--ink);
  font-size: 13px;
}
.fs-sec {
  font-size: 11px;
  font-weight: 600;
  padding: 2px 8px;
  border-radius: 99px;
  background: #fff4e5;
  color: #b7681a;
}
.fs-img {
  display: block;
  width: 100%;
  height: auto;
  border-radius: 8px;
  border: 1px solid #eceef1;
}
.fs-imgwrap {
  overflow-x: auto;
  -webkit-overflow-scrolling: touch;
  border-radius: 8px;
}
.fs-scrollhint {
  display: none;
  margin-top: 4px;
  font-size: 11px;
  color: var(--faint);
  text-align: right;
}
@media (max-width: 600px) {
  .fs-img.wide {
    min-width: 680px;
  }
  .fs-scrollhint {
    display: block;
  }
}
.fs-subs {
  margin-top: 12px;
  display: flex;
  flex-direction: column;
}
.fs-sub {
  display: flex;
  align-items: flex-start;
  gap: 10px;
  padding: 9px 0;
  border-top: 1px solid #f0f1f3;
}
.fs-lab {
  flex-shrink: 0;
  min-width: 30px;
  padding-top: 8px;
  font-size: 13px;
  font-weight: 700;
  color: #5b6b8c;
}
.fs-body {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.fs-opts {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
}
.fs-opt {
  width: 44px;
  height: 44px;
  border: 1.5px solid #e3e6ea;
  border-radius: 50%;
  background: #fff;
  font-size: 17px;
  font-weight: 600;
  cursor: pointer;
  color: var(--ink);
}
.fs-opt.sel {
  border-color: #1c2024;
  background: #1c2024;
  color: #fff;
}
.fs-opt.ok {
  border-color: #9ed4b3;
  background: #eaf7ef;
  color: #1f7a45;
}
.fs-opt.ng {
  border-color: #f0b8be;
  background: #fdeef0;
  color: #c0444f;
}
.fs-opt.dim {
  color: #c3c7cf;
}
.fs-opt:disabled {
  cursor: default;
}
.fs-inputs {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}
.fs-bn {
  font-size: 12px;
  color: var(--mut);
}
.fs-input {
  width: min(100%, 220px);
  padding: 7px 10px;
  border: none;
  border-bottom: 2px solid #3b50cc;
  border-radius: 6px 6px 0 0;
  background: #f1f3fb;
  font-size: 16px;
  outline: none;
}
.fs-input:focus {
  background: #e8ecfb;
}
.fs-typed {
  font-size: 16px;
  font-weight: 700;
  padding: 2px 8px;
  border-bottom: 2px solid;
}
.fs-typed.ok {
  color: #1f7a45;
  border-color: #9ed4b3;
}
.fs-typed.ng {
  color: #c0444f;
  border-color: #f0b8be;
  text-decoration: line-through;
}
.fs-hint {
  font-size: 11.5px;
  color: var(--faint);
}
.fs-self {
  min-height: 20px;
  display: flex;
  align-items: center;
}
.fs-res {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  flex-wrap: wrap;
}
.fs-ans {
  display: inline-flex;
  align-items: baseline;
  gap: 8px;
  min-width: 0;
  font-size: 15px;
  line-height: 1.6;
}
.fs-anslab {
  flex-shrink: 0;
  font-size: 11px;
  font-weight: 700;
  color: #5b6b8c;
  background: #eef1f6;
  padding: 1px 8px;
  border-radius: 99px;
  align-self: center;
}
.fs-anstext {
  font-weight: 700;
  color: var(--primary);
  overflow-wrap: anywhere;
}
.fs-anstext.muted {
  font-weight: 500;
  color: var(--faint);
  font-size: 13px;
}
.fs-judge {
  display: inline-flex;
  gap: 6px;
  flex-shrink: 0;
}
.jb {
  width: 40px;
  height: 36px;
  border: 1.5px solid #e3e6ea;
  border-radius: 10px;
  background: #fff;
  font-size: 16px;
  font-weight: 700;
  color: #9aa1ab;
  cursor: pointer;
}
.jb.ok.on {
  border-color: #9ed4b3;
  background: #eaf7ef;
  color: #1f7a45;
}
.jb.ng.on {
  border-color: #f0b8be;
  background: #fdeef0;
  color: #c0444f;
}
.fs-reveal {
  margin-top: 14px;
  padding-top: 12px;
  border-top: 1px solid #f0f1f3;
}
.fs-anshead {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 8px;
}
.fs-unsure {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  min-height: 32px;
  padding: 4px 12px;
  border: 1.5px solid #c9d6f2;
  border-radius: 99px;
  background: #fff;
  color: #2f5fc4;
  font-size: 12.5px;
  font-weight: 700;
  cursor: pointer;
}
.fs-unsure.on {
  background: #2f5fc4;
  border-color: #2f5fc4;
  color: #fff;
}
@media (pointer: coarse) {
  .fs-opt {
    width: 48px;
    height: 48px;
  }
  .jb {
    width: 46px;
    height: 42px;
  }
}
@media (max-width: 600px) {
  .fs {
    padding: 14px 14px 16px;
  }
}
</style>
