<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { iso } from '@/lib/design'
import { useVocabularyStore } from '@/stores/vocabulary'
import { useUiStore } from '@/stores/ui'
import type { PrintTestFormat, PrintTestType, Vocabulary } from '@/types'
import TestSheetPrint from '@/components/TestSheetPrint.vue'

const router = useRouter()
const vocab = useVocabularyStore()
const ui = useUiStore()

const resourceId = computed(() => vocab.resource?.id ?? 0)
const range = ref<'3' | '7' | '30' | 'custom'>('7')
const quizType = ref<'choice' | 'input'>('choice')
const customFrom = ref('')
const customTo = ref('')
const words = ref<Vocabulary[]>([])
const loaded = ref(false)
const loading = ref(false)

const printOpen = ref(false)
const printType = ref<PrintTestType>('meaning')
const printFormat = ref<PrintTestFormat>('free')

onMounted(async () => {
  if (!vocab.resource) await vocab.fetchResources()
  const today = new Date()
  customTo.value = iso(today)
  const from = new Date()
  from.setDate(from.getDate() - 7)
  customFrom.value = iso(from)
})

function sinceDate(): string {
  if (range.value === 'custom') return customFrom.value
  const d = new Date()
  d.setDate(d.getDate() - Number(range.value))
  return iso(d)
}
function untilDate(): string | undefined {
  return range.value === 'custom' ? customTo.value : undefined
}

async function extract() {
  if (!resourceId.value) return
  loading.value = true
  try {
    const res = await vocab.fetchIncorrect(resourceId.value, sinceDate(), untilDate())
    words.value = res.words
    loaded.value = true
  } finally {
    loading.value = false
  }
}

async function startReviewQuiz() {
  if (!words.value.length) {
    ui.notify('対象の単語がありません')
    return
  }
  await vocab.startQuiz(resourceId.value, {
    sectionIds: [],
    quizType: quizType.value,
    count: 0,
    vocabularyIds: words.value.map((w) => w.id),
  })
  router.push({ name: 'quiz' })
}

function openPrint() {
  if (!words.value.length) {
    ui.notify('対象の単語がありません')
    return
  }
  printOpen.value = true
}
</script>

<template>
  <div style="max-width: 760px; margin: 0 auto">
    <div class="card" style="padding: 22px; margin-bottom: 16px">
      <div class="row-between" style="margin-bottom: 4px">
        <div style="font-size: 16px; font-weight: 700">間違えた単語の復習</div>
        <button class="link-btn" @click="router.push({ name: 'quiz' })">クイズへ</button>
      </div>
      <div style="font-size: 12px; color: var(--faint); margin-bottom: 16px">指定期間に誤答した単語を抽出して復習します。</div>

      <div style="font-size: 12px; color: var(--mut); font-weight: 600; margin-bottom: 8px">対象期間</div>
      <div style="display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 14px">
        <button v-for="r in (['3', '7', '30'] as const)" :key="r" class="period" :class="{ on: range === r }" @click="range = r">
          {{ r === '3' ? '過去3日' : r === '7' ? '過去7日' : '過去1ヵ月' }}
        </button>
        <button class="period" :class="{ on: range === 'custom' }" @click="range = 'custom'">任意期間</button>
      </div>

      <div v-if="range === 'custom'" style="display: flex; gap: 10px; align-items: center; margin-bottom: 14px; flex-wrap: wrap">
        <input v-model="customFrom" type="date" class="d-input" />
        <span style="color: var(--faint)">〜</span>
        <input v-model="customTo" type="date" class="d-input" />
      </div>

      <div style="font-size: 12px; color: var(--mut); font-weight: 600; margin-bottom: 8px">クイズタイプ</div>
      <div class="seg2" style="max-width: 240px; margin-bottom: 16px">
        <button :class="{ on: quizType === 'choice' }" @click="quizType = 'choice'">4択</button>
        <button :class="{ on: quizType === 'input' }" @click="quizType = 'input'">入力</button>
      </div>

      <div style="display: flex; gap: 10px; flex-wrap: wrap">
        <button class="btn-dark" :disabled="loading" @click="extract">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align: -2px; margin-right: 5px"><circle cx="11" cy="11" r="7" /><path d="M21 21l-4-4" /></svg>検索
        </button>
      </div>
    </div>

    <div v-if="loaded" class="card" style="padding: 18px 20px">
      <div v-if="words.length" style="display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 14px">
        <button class="btn-dark" @click="startReviewQuiz">クイズ開始（{{ words.length }}語）</button>
        <button class="btn-out" @click="openPrint">小テスト印刷</button>
      </div>
      <div style="font-size: 13px; font-weight: 700; margin-bottom: 10px">誤答した単語（{{ words.length }}）</div>
      <div v-if="!words.length" style="padding: 24px; text-align: center; color: var(--faint); font-size: 13px">
        この期間に誤答した単語はありません
      </div>
      <div v-else style="display: flex; flex-direction: column">
        <div v-for="w in words" :key="w.id" style="display: flex; align-items: baseline; gap: 12px; padding: 9px 0; border-top: 1px solid #f4f5f7">
          <span class="dm" style="font-size: 14px; font-weight: 700; min-width: 130px">{{ w.word }}</span>
          <span style="font-size: 13px; color: #4b5563; flex: 1">{{ w.meaning }}</span>
          <span style="font-size: 11px; color: var(--faint)">{{ w.partOfSpeech }}</span>
        </div>
      </div>
    </div>

    <TestSheetPrint
      v-if="printOpen"
      :words="words"
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
.link-btn {
  border: none;
  background: none;
  color: #3b50cc;
  font-size: 12px;
  cursor: pointer;
  font-weight: 600;
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
.period {
  padding: 8px 14px;
  border: 1px solid #e3e6ea;
  border-radius: 9px;
  background: #fff;
  font-size: 12.5px;
  cursor: pointer;
  font-weight: 600;
  color: var(--mut);
}
.period.on {
  background: #1c2024;
  color: #fff;
  border-color: #1c2024;
}
.d-input {
  padding: 8px 11px;
  border: 1px solid #e3e6ea;
  border-radius: 9px;
  font-size: 13px;
  outline: none;
}
.apply {
  padding: 8px 14px;
  border: none;
  border-radius: 9px;
  background: #3b50cc;
  color: #fff;
  font-size: 12.5px;
  cursor: pointer;
  font-weight: 600;
}
.btn-dark {
  padding: 10px 16px;
  border: none;
  border-radius: 10px;
  background: #1c2024;
  color: #fff;
  font-size: 13px;
  font-weight: 700;
  cursor: pointer;
}
.btn-out {
  padding: 10px 16px;
  border: 1px solid #e3e6ea;
  border-radius: 10px;
  background: #fff;
  color: #1c2024;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
}
</style>
