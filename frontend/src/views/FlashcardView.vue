<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useVocabularyStore } from '@/stores/vocabulary'
import { useUiStore } from '@/stores/ui'
import type { VocabularyLabel } from '@/types'
import FlashcardPlayer from '@/components/FlashcardPlayer.vue'

const router = useRouter()
const vocab = useVocabularyStore()
const ui = useUiStore()

const started = ref(false)
const secSel = ref<Record<number, boolean>>({})
const impSel = ref<number[]>([])
const labelSel = ref<VocabularyLabel[]>([])
const count = ref(0) // 0=すべて
const ordered = ref(false)
const wordDuration = ref(5)
const meaningDuration = ref(3)

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

const sections = computed(() => vocab.resource?.sections ?? [])
const resourceId = computed(() => vocab.resource?.id ?? 0)

onMounted(async () => {
  if (!vocab.resource) await vocab.fetchResources()
  if (resourceId.value && !vocab.items.length) await vocab.fetchByResource(resourceId.value)
  const sel: Record<number, boolean> = {}
  sections.value.forEach((s) => (sel[s.id] = true))
  secSel.value = sel
})

const filteredWords = computed(() =>
  vocab.items.filter(
    (w) =>
      secSel.value[w.sectionId] &&
      (!impSel.value.length || impSel.value.includes(w.importance)) &&
      (!labelSel.value.length || labelSel.value.includes(w.label)),
  ),
)
const words = computed(() =>
  count.value > 0 ? filteredWords.value.slice(0, count.value) : filteredWords.value,
)

function start() {
  if (!words.value.length) {
    ui.notify('対象の単語がありません')
    return
  }
  started.value = true
}
function toggleSec(id: number) {
  secSel.value = { ...secSel.value, [id]: !secSel.value[id] }
}
function allSec() {
  const sel: Record<number, boolean> = {}
  sections.value.forEach((s) => (sel[s.id] = true))
  secSel.value = sel
}
function clearSec() {
  secSel.value = {}
}
</script>

<template>
  <div>
    <div v-if="!started" style="max-width: 520px; margin: 0 auto">
      <div class="card" style="padding: 22px">
        <div class="row-between" style="margin-bottom: 16px">
          <div style="font-size: 16px; font-weight: 700">フラッシュカード</div>
          <button class="link-btn" @click="router.push({ name: 'quiz' })">クイズへ</button>
        </div>

        <div class="row-between" style="margin-bottom: 8px">
          <span style="font-size: 12px; color: var(--mut); font-weight: 600">出題セクション</span>
          <span style="display: flex; gap: 10px">
            <button class="link-btn" @click="allSec">全選択</button>
            <button class="link-btn" style="color: #9aa1ab" @click="clearSec">全解除</button>
          </span>
        </div>
        <div style="display: flex; flex-wrap: wrap; gap: 7px; margin-bottom: 16px">
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

        <div style="font-size: 12px; color: var(--mut); font-weight: 600; margin-bottom: 8px">重要度</div>
        <div style="display: flex; gap: 7px; flex-wrap: wrap; margin-bottom: 16px">
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

        <div style="font-size: 12px; color: var(--mut); font-weight: 600; margin-bottom: 8px">ラベル</div>
        <div style="display: flex; gap: 7px; flex-wrap: wrap; margin-bottom: 16px">
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

        <div class="row-between" style="margin-bottom: 18px">
          <span style="font-size: 12px; color: var(--mut); font-weight: 500">単語数</span>
          <select v-model.number="count" class="mini-select">
            <option :value="0">すべて</option>
            <option :value="10">10語</option>
            <option :value="20">20語</option>
            <option :value="30">30語</option>
            <option :value="50">50語</option>
          </select>
        </div>

        <div class="slider-row">
          <span>語の表示時間</span><strong>{{ wordDuration }}秒</strong>
        </div>
        <input v-model.number="wordDuration" type="range" min="2" max="10" style="width: 100%; margin-bottom: 14px" />

        <div class="slider-row">
          <span>意味の表示時間</span><strong>{{ meaningDuration }}秒</strong>
        </div>
        <input v-model.number="meaningDuration" type="range" min="2" max="10" style="width: 100%; margin-bottom: 18px" />

        <div class="row-between" style="margin-bottom: 18px">
          <span style="font-size: 12px; color: var(--mut); font-weight: 500">出題順</span>
          <div class="seg2">
            <button :class="{ on: !ordered }" @click="ordered = false">ランダム</button>
            <button :class="{ on: ordered }" @click="ordered = true">順番</button>
          </div>
        </div>

        <button class="start-btn" @click="start">開始（{{ words.length }}語）</button>
      </div>
    </div>

    <FlashcardPlayer
      v-else
      :words="words"
      :ordered="ordered"
      :word-duration="wordDuration"
      :meaning-duration="meaningDuration"
      @exit="started = false"
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
.chip {
  padding: 6px 11px;
  border-radius: 99px;
  font-size: 12px;
  font-weight: 500;
  cursor: pointer;
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
.slider-row {
  display: flex;
  justify-content: space-between;
  font-size: 12.5px;
  color: var(--mut);
  margin-bottom: 6px;
}
.seg2 {
  display: flex;
  background: #f1f3f5;
  border-radius: 9px;
  padding: 3px;
}
.seg2 button {
  padding: 7px 14px;
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
.start-btn {
  width: 100%;
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
