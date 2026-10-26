<script setup lang="ts">
/**
 * 英文法・語法の「解説」モーダル（解答後に下部の「解説」で開く）。
 * 完成した英文（読み上げ可）・正解の詳細・注記・あなたの答え・和訳・出典・備考を表示し、
 * 整序・空所補充は自動採点が合わないときの自己判定（正解にする／不正解にする）もここでできる。
 * 「詳細解説」を押すと、教材の解説ページの該当部分の画像をさらに上の横広のモーダルで表示する（OCR の文字は出さない）。
 * どちらのモーダルも「閉じる」（または右上の ×）でだけ閉じる（背景のタップやスワイプでは閉じない）。
 */
import { computed, ref, watch } from 'vue'
import AuthImage from '@/components/AuthImage.vue'
import type { GrammarAnswerResult } from '@/components/GrammarQuestionCard.vue'
import { grammarApi } from '@/api/grammar'
import { speak } from '@/lib/design'
import { CIRCLED, answerSegments, completedSentence, splitCorrection } from '@/lib/grammar'
import type { GrammarQuestion } from '@/types'

const props = defineProps<{ question: GrammarQuestion; result: GrammarAnswerResult | null }>()
const emit = defineEmits<{ close: []; override: [isCorrect: boolean] }>()

const q = computed(() => props.question)
const sentence = computed(() => completedSentence(q.value))
const correction = computed(() => splitCorrection(q.value.answerText))
const orderAnswer = computed(() => answerSegments(q.value.answerText ?? ''))
const fillAnswerLabel = computed(() => (q.value.answers ?? []).map((alts) => alts.join(' / ')).join('，'))
const canOverride = computed(() => q.value.format === 'order' || q.value.format === 'fill')
const showMyAnswer = computed(
  () => !!props.result && !props.result.isCorrect && !!props.result.answer && props.result.answer !== 'わからない' && (q.value.format === 'order' || q.value.format === 'fill'),
)

// ---- 詳細解説（教材の解説ページの画像） ----
const detailOpen = ref(false)
watch(() => q.value.id, () => {
  detailOpen.value = false
})
</script>

<template>
  <div class="overlay ui-overlay ui-sheet">
    <div class="ex-modal ui-panel">
      <div class="ex-head">
        <span class="ex-title">解説</span>
        <span class="ex-no dm">No.{{ q.no }}</span>
        <span class="ex-ch">{{ q.chapter }} / Theme {{ q.theme }}</span>
        <button class="ex-x" aria-label="閉じる" @click="emit('close')">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18" /></svg>
        </button>
      </div>

      <div class="ex-body">
        <!-- 画像出題: 解答欄の画像 -->
        <div v-if="q.format === 'image' && q.hasAnswerImage" class="ex-imgbox">
          <div class="ex-imglab">解答欄（No.{{ q.no }} の行）</div>
          <AuthImage :src="grammarApi.imageUrl(q.id, 'answer')" class="ex-img" alt="解答" />
        </div>
        <!-- 完成文（タップで読み上げ） -->
        <div v-if="q.format !== 'image'" class="ex-sent dm" title="タップで読み上げ" @click="speak(sentence)">
          {{ sentence }}
          <button class="bare ex-spk" aria-label="読み上げ" @click.stop="speak(sentence)">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M11 5L6 9H3v6h3l5 4z" /><path d="M15.5 8.5a5 5 0 0 1 0 7M18.5 5.5a9 9 0 0 1 0 13" /></svg>
          </button>
        </div>
        <div v-if="q.translation" class="ex-trans">{{ q.translation }}</div>
        <div v-else-if="q.instruction && q.format === 'order'" class="ex-trans">{{ q.instruction.replace(/〔.*?〕/g, '').trim() }}</div>

        <!-- 正解 -->
        <div v-if="q.format !== 'image' || q.answerText || (q.choiceCount && q.answerIndex !== null)" class="ex-row">
          <span class="ex-lab">正解</span>
          <template v-if="q.format === 'choice'"><span class="dm ex-strong">{{ CIRCLED[q.answerIndex ?? 0] }} {{ q.answerText }}</span></template>
          <template v-else-if="q.format === 'error'">
            <span class="dm ex-strong">{{ CIRCLED[q.answerIndex ?? 0] }} {{ correction ? correction.from : q.answerText }}</span>
            <span v-if="correction" class="dm ex-fix">→ {{ correction.to }}</span>
          </template>
          <template v-else-if="q.format === 'order'">
            <span class="dm"><template v-for="(s, i) in orderAnswer" :key="i"><span :class="{ 'ex-strong': s.hl }">{{ s.text }}</span></template></span>
          </template>
          <template v-else-if="q.format === 'image'"><span class="dm ex-strong">{{ q.choiceCount && q.answerIndex !== null ? CIRCLED[q.answerIndex] : q.answerText }}</span></template>
          <template v-else><span class="dm ex-strong">{{ fillAnswerLabel }}</span></template>
        </div>
        <div v-if="q.answerNote" class="ex-note">〔{{ q.answerNote }}〕</div>
        <div v-if="q.format === 'order' && q.unneeded.length" class="ex-sub">不要な語句: <span class="dm">{{ q.unneeded.join(' / ') }}</span></div>
        <div v-if="q.format === 'order' && q.missing.length" class="ex-sub">不足している語: <span class="dm">{{ q.missing.join(' / ') }}</span></div>
        <div v-if="q.format === 'fill' && (q.answers ?? []).some((a) => a.length > 1)" class="ex-sub">[ ] 内は別解。どちらでも正解です</div>

        <!-- あなたの答え・判定 -->
        <div v-if="result" class="ex-row" style="margin-top: 12px">
          <span class="ex-lab">判定</span>
          <span class="ex-badge" :class="result.isCorrect ? 'ok' : 'ng'">{{ result.isCorrect ? '正解' : '不正解' }}</span>
          <button v-if="canOverride" class="ex-judge" @click="emit('override', !result.isCorrect)">{{ result.isCorrect ? '不正解にする' : '正解にする' }}</button>
        </div>
        <div v-if="showMyAnswer && result" class="ex-mine"><span class="ex-lab">あなたの答え</span><span class="dm">{{ result.answer }}</span></div>

        <div class="ex-foot">
          <span v-if="q.source">出典: {{ q.source }}</span>
          <span v-if="q.importance">重要度: {{ q.importance }}</span>
          <span v-if="q.note" class="ex-remark">{{ q.note }}</span>
        </div>
      </div>

      <div class="ex-btns">
        <button v-if="q.hasExplanationImage" class="ex-detail" @click="detailOpen = true">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 5.5h13a1.5 1.5 0 0 1 1.5 1.5v12.5H5.5A1.5 1.5 0 0 1 4 18z" /><path d="M8 9.5h7M8 13h5" /></svg>
          詳細解説
        </button>
        <button class="ex-close" @click="emit('close')">閉じる</button>
      </div>
    </div>

    <!-- 詳細解説（教材の解説ページ）。解説モーダルのさらに上に重ねる -->
    <Transition name="ui-modal">
      <div v-if="detailOpen" class="overlay overlay-top ui-overlay ui-sheet">
        <div class="ex-modal wide ui-panel">
          <div class="ex-head">
            <span class="ex-title">詳細解説</span>
            <span class="ex-no dm">No.{{ q.no }}</span>
            <span class="ex-ch">{{ q.chapter }} / Theme {{ q.theme }}</span>
            <button class="ex-x" aria-label="閉じる" @click="detailOpen = false">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18" /></svg>
            </button>
          </div>
          <div class="ex-body">
            <!-- 教材の解説ページの該当部分（画像） -->
            <div v-if="q.hasExplanationImage" class="ex-imgbox big">
              <AuthImage :src="grammarApi.imageUrl(q.id, 'explanation')" class="ex-img" alt="解説" />
            </div>
            <div v-else class="dt-msg">この問題の解説画像はありません</div>
          </div>
          <div class="ex-btns">
            <button class="ex-close" @click="detailOpen = false">閉じる</button>
          </div>
        </div>
      </div>
    </Transition>
  </div>
</template>

<style scoped>
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
.overlay-top {
  z-index: 60;
  background: rgba(20, 24, 32, 0.45);
}
.ex-modal {
  background: #fff;
  border-radius: 16px;
  padding: 18px 22px 20px;
  width: 100%;
  max-width: 560px;
  max-height: 86vh;
  display: flex;
  flex-direction: column;
  box-shadow: 0 20px 50px rgba(0, 0, 0, 0.2);
}
/* 詳細解説: 画像を大きく見せるため横に広げる（画面幅いっぱい近くまで） */
.ex-modal.wide {
  max-width: min(1240px, 96vw);
  max-height: 94vh;
  padding: 14px 18px 16px;
}
.overlay-top {
  padding: 12px;
}
.ex-imgbox.big {
  margin-bottom: 0;
}
.ex-imgbox.big .ex-img {
  border-radius: 6px;
}
.ex-head {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 12px;
  flex-wrap: wrap;
}
.ex-title {
  font-size: 15px;
  font-weight: 700;
}
.ex-no {
  font-size: 12.5px;
  font-weight: 700;
  color: #9aa1ab;
}
.ex-ch {
  font-size: 11.5px;
  color: var(--faint);
}
.ex-x {
  margin-left: auto;
  width: 32px;
  height: 32px;
  border: none;
  border-radius: 50%;
  background: #f2f3f5;
  color: #6b7280;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  flex-shrink: 0;
}
.ex-body {
  flex: 1;
  min-height: 0;
  overflow-y: auto;
  overscroll-behavior: contain;
}
.ex-sent {
  font-size: 17px;
  line-height: 1.8;
  color: var(--ink);
  cursor: pointer;
  border-radius: 8px;
  -webkit-tap-highlight-color: rgba(59, 80, 204, 0.15);
}
.ex-sent:active {
  background: rgba(59, 80, 204, 0.06);
}
.ex-spk {
  color: #9aa1ab;
  vertical-align: middle;
  margin-left: 4px;
}
.ex-trans {
  margin-top: 6px;
  font-size: 14px;
  color: #4b5563;
  line-height: 1.7;
}
.ex-row {
  display: flex;
  align-items: baseline;
  gap: 10px;
  flex-wrap: wrap;
  margin-top: 14px;
  font-size: 16px;
  line-height: 1.7;
}
.ex-lab {
  flex-shrink: 0;
  font-size: 11px;
  font-weight: 700;
  color: #5b6b8c;
  background: #eef1f6;
  padding: 1px 8px;
  border-radius: 99px;
  align-self: center;
}
.ex-strong {
  font-weight: 700;
  color: var(--primary);
}
.ex-fix {
  font-weight: 700;
  color: #1f7a45;
}
.ex-note {
  margin-top: 4px;
  font-size: 12.5px;
  color: #b7681a;
}
.ex-sub {
  margin-top: 4px;
  font-size: 12.5px;
  color: var(--mut);
}
.ex-badge {
  display: inline-flex;
  padding: 3px 11px;
  border-radius: 99px;
  font-size: 12.5px;
  font-weight: 700;
  align-self: center;
}
.ex-badge.ok {
  background: #eaf7ef;
  color: #1f7a45;
}
.ex-badge.ng {
  background: #fdeef0;
  color: #c0444f;
}
.ex-judge {
  border: 1px solid #e3e6ea;
  background: #fff;
  border-radius: 8px;
  padding: 5px 10px;
  font-size: 12px;
  font-weight: 600;
  color: #4b5563;
  cursor: pointer;
  align-self: center;
}
.ex-mine {
  display: flex;
  align-items: baseline;
  gap: 10px;
  margin-top: 8px;
  font-size: 14px;
  color: #c0444f;
}
.ex-foot {
  display: flex;
  gap: 14px;
  flex-wrap: wrap;
  margin-top: 14px;
  font-size: 12px;
  color: var(--faint);
}
.ex-remark {
  color: #b7681a;
}
.ex-btns {
  display: flex;
  gap: 10px;
  margin-top: 16px;
}
.ex-detail {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  flex: 1;
  padding: 12px;
  border: 1.5px solid #3b50cc;
  border-radius: 11px;
  background: #fff;
  color: #3b50cc;
  font-size: 14px;
  font-weight: 700;
  cursor: pointer;
}
.ex-close {
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
.ex-imgbox {
  margin-bottom: 12px;
}
.ex-imglab {
  font-size: 11.5px;
  color: var(--mut);
  margin-bottom: 6px;
}
.ex-img {
  display: block;
  width: 100%;
  height: auto;
  border-radius: 8px;
  border: 1px solid #eceef1;
}
.dt-msg {
  padding: 24px 0;
  text-align: center;
  color: var(--faint);
  font-size: 13px;
}
</style>
