<script setup lang="ts">
/**
 * 英単語クイズの「解説」モーダル（解答後に下部の「解説」で開く）。
 * 単語・意味・例文（和訳の表示切替・読み上げ）・例文の説明・参考・例文中の登録単語・画像・習熟度・メモ。
 */
import { ref } from 'vue'
import AuthImage from '@/components/AuthImage.vue'
import { speak } from '@/lib/design'
import { examplesOf, splitByWord } from '@/lib/vocabExamples'
import type { Vocabulary } from '@/types'

export interface VocabExampleMatch {
  id: number
  word: string
  section: string
  meaning: string
  supplement: string | null
}

const props = defineProps<{ vocab: Vocabulary; matches: VocabExampleMatch[] }>()
const emit = defineEmits<{ close: []; prof: [p: 'high' | 'medium' | 'low']; memo: [] }>()

const transShown = ref<Record<number, boolean>>({})
const showExpl = ref(false)
const profMap = { high: { t: '高', c: '#2e9d62' }, medium: { t: '中', c: '#d98a2b' }, low: { t: '低', c: '#cf5563' } }

function exParts(sentence: string) {
  return splitByWord(sentence, props.vocab.word)
}
</script>

<template>
  <div class="overlay ui-overlay ui-sheet ui-swipe" @click="emit('close')">
    <div class="ex-modal ui-panel" @click.stop>
      <div class="ex-head">
        <span class="ex-title">解説</span>
        <button class="ex-x" aria-label="閉じる" @click="emit('close')">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18" /></svg>
        </button>
      </div>

      <div class="ex-body">
        <div style="display: flex; align-items: baseline; gap: 10px; flex-wrap: wrap">
          <button class="bare dm word-tap" style="font-size: 24px; font-weight: 700" title="タップで発音" @click="speak(vocab.word)">{{ vocab.word }}</button>
          <span style="font-size: 15px; color: #4b5563">{{ vocab.meaning }}</span>
          <span v-if="vocab.meaningSupplement" style="font-size: 12.5px; color: #9aa1ab">{{ vocab.meaningSupplement }}</span>
        </div>
        <div style="display: flex; align-items: center; gap: 8px; margin-top: 6px; flex-wrap: wrap">
          <span v-if="vocab.partOfSpeech" class="tag">{{ vocab.partOfSpeech }}</span>
          <span v-if="vocab.importance" style="font-size: 12px; color: #e0a93b">{{ '★'.repeat(vocab.importance) }}</span>
        </div>

        <div v-if="examplesOf(vocab).length || vocab.referenceNote" class="ex-box">
          <!-- 例文（複数可）。①②は意味の番号に対応 -->
          <div v-for="(e, ei) in examplesOf(vocab)" :key="ei" :style="{ marginTop: ei ? '8px' : '0' }">
            <div class="ex-tap" style="font-size: 14px; line-height: 1.6" title="タップで例文を読み上げ" @click="speak(e.sentence)">
              <span v-if="e.label" class="ex-label">{{ e.label }}</span>{{ exParts(e.sentence).before }}<span style="font-weight: 700; color: #3b50cc">{{ exParts(e.sentence).word }}</span>{{ exParts(e.sentence).after }}
              <button class="bare" style="color: #9aa1ab; vertical-align: middle; margin-left: 4px" @click.stop="speak(e.sentence)">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M11 5L6 9H3v6h3l5 4z" /><path d="M15.5 8.5a5 5 0 0 1 0 7" /></svg>
              </button>
            </div>
            <div v-if="e.translation" style="margin-top: 2px">
              <button v-if="!transShown[ei]" class="mini-link" @click="transShown[ei] = true">和訳を表示</button>
              <div v-else style="font-size: 12.5px; color: var(--faint); margin-top: 3px"><span v-if="e.label" class="ex-label" style="color: var(--faint)">{{ e.label }}</span>{{ e.translation }}</div>
            </div>
          </div>
          <div v-if="vocab.exampleExplanation" style="margin-top: 6px">
            <button v-if="!showExpl" class="mini-link" @click="showExpl = true">例文の説明を表示</button>
            <div v-else style="font-size: 12.5px; color: #4b5563; margin-top: 4px; line-height: 1.55; white-space: pre-wrap">{{ vocab.exampleExplanation }}</div>
          </div>
          <div v-if="vocab.referenceNote" style="margin-top: 8px; font-size: 12.5px; color: #4b5563; line-height: 1.55; white-space: pre-wrap"><span class="ref-tag">参考</span>{{ vocab.referenceNote }}</div>
          <div v-if="matches.length" style="margin-top: 10px; border-top: 1px dashed #e3e6ea; padding-top: 10px">
            <div style="font-size: 11px; color: var(--faint); margin-bottom: 6px">例文中の登録単語</div>
            <div v-for="m in matches" :key="m.id" style="display: flex; align-items: baseline; gap: 8px; padding: 3px 0; flex-wrap: wrap">
              <span v-if="m.section" class="tag">{{ m.section }}</span>
              <button class="bare dm word-tap" style="font-size: 13px; font-weight: 700" title="タップで発音" @click="speak(m.word)">{{ m.word }}</button>
              <span style="font-size: 12.5px; color: #4b5563">{{ m.meaning }}</span>
              <span v-if="m.supplement" style="font-size: 11.5px; color: #9aa1ab">{{ m.supplement }}</span>
            </div>
          </div>
        </div>

        <div v-if="vocab.imageUrl" style="margin-top: 12px">
          <AuthImage :src="vocab.imageUrl" style="max-width: 100%; max-height: 220px; border-radius: 12px; border: 1px solid #e3e6ea" />
        </div>

        <div style="display: flex; align-items: center; gap: 8px; margin-top: 14px; flex-wrap: wrap">
          <span style="font-size: 12px; color: var(--mut)">習熟度</span>
          <button class="prof-btn" :class="{ on: vocab.proficiency === 'high' }" style="color: #2e9d62" @click="emit('prof', 'high')">高</button>
          <button class="prof-btn" :class="{ on: vocab.proficiency === 'medium' }" style="color: #d98a2b" @click="emit('prof', 'medium')">中</button>
          <button class="prof-btn" :class="{ on: vocab.proficiency === 'low' }" style="color: #cf5563" @click="emit('prof', 'low')">低</button>
          <span style="font-size: 11px; color: var(--faint); margin-left: auto">現在: {{ vocab.proficiency ? profMap[vocab.proficiency].t : '未設定' }}</span>
        </div>
        <div style="margin-top: 12px">
          <button class="memo-chip" @click="emit('memo')">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 20h9" /><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z" /></svg>
            {{ vocab.memo ? vocab.memo : 'メモを追加' }}
          </button>
        </div>
      </div>

      <button class="ex-close" @click="emit('close')">閉じる</button>
    </div>
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
.ex-head {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 12px;
}
.ex-title {
  font-size: 15px;
  font-weight: 700;
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
.ex-box {
  background: #f8f9fb;
  border-radius: 12px;
  padding: 13px 15px;
  margin-top: 12px;
}
.word-tap {
  color: #1c2024;
  text-align: left;
  border-radius: 6px;
  text-decoration: underline;
  text-decoration-color: #d8dce1;
  text-underline-offset: 4px;
  -webkit-tap-highlight-color: rgba(59, 80, 204, 0.15);
}
.word-tap:active {
  color: #3b50cc;
}
.ex-tap {
  cursor: pointer;
  border-radius: 6px;
  -webkit-tap-highlight-color: rgba(59, 80, 204, 0.15);
}
.ex-tap:active {
  background: rgba(59, 80, 204, 0.06);
}
.ex-label {
  display: inline-block;
  font-weight: 700;
  color: #3b50cc;
  margin-right: 4px;
}
.tag {
  font-size: 10.5px;
  font-weight: 600;
  color: #5b6b8c;
  background: #eef1f6;
  padding: 1px 7px;
  border-radius: 99px;
}
.ref-tag {
  display: inline-block;
  font-size: 10.5px;
  font-weight: 700;
  color: #5b6b8c;
  background: #eef1f6;
  padding: 1px 7px;
  border-radius: 99px;
  margin-right: 6px;
  vertical-align: middle;
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
.prof-btn {
  padding: 6px 13px;
  border: 1px solid #e3e6ea;
  border-radius: 8px;
  background: #fff;
  font-size: 12.5px;
  cursor: pointer;
  font-weight: 600;
}
.prof-btn.on {
  border-color: currentColor;
  background: #f8f9fb;
}
.memo-chip {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  max-width: 100%;
  padding: 8px 13px;
  border: 1px solid #e3e6ea;
  border-radius: 99px;
  background: #fff;
  color: #4b5563;
  font-size: 12.5px;
  cursor: pointer;
  text-align: left;
}
.ex-close {
  margin-top: 16px;
  padding: 12px;
  border: none;
  border-radius: 11px;
  background: #1c2024;
  color: #fff;
  font-size: 14px;
  font-weight: 700;
  cursor: pointer;
}
</style>
