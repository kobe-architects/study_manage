<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { shuffle, speak } from '@/lib/design'
import type { Vocabulary } from '@/types'

const props = defineProps<{
  words: Vocabulary[]
  ordered: boolean
  wordDuration?: number
  meaningDuration?: number
}>()
const emit = defineEmits<{ exit: [] }>()

const list = ref<Vocabulary[]>([])
const idx = ref(0)
const face = ref<'word' | 'meaning'>('word')
const playing = ref(false)
const loops = ref(0)
let timer: ReturnType<typeof setTimeout> | undefined

const wordDur = computed(() => (props.wordDuration ?? 5) * 1000)
const meaningDur = computed(() => (props.meaningDuration ?? 3) * 1000)

const current = computed(() => list.value[idx.value] ?? null)
const progress = computed(() => (list.value.length ? Math.round(((idx.value + 1) / list.value.length) * 100) : 0))

onMounted(() => {
  list.value = props.ordered ? props.words.slice() : shuffle(props.words)
  document.addEventListener('visibilitychange', onVisibility)
  // 自動再生で開始（語を読み上げ→語の表示時間後に自動でめくる）
  playing.value = true
  if (current.value) speak(current.value.word)
  schedule(wordDur.value)
})
onUnmounted(() => {
  clearTimeout(timer)
  document.removeEventListener('visibilitychange', onVisibility)
})

function onVisibility() {
  if (document.hidden && playing.value) pause()
}

function step() {
  if (face.value === 'word') {
    face.value = 'meaning'
    schedule(meaningDur.value)
  } else {
    next()
    schedule(wordDur.value)
  }
}
function schedule(ms: number) {
  clearTimeout(timer)
  if (playing.value) timer = setTimeout(step, ms)
}
function next() {
  let n = idx.value + 1
  if (n >= list.value.length) {
    n = 0
    loops.value++
  }
  idx.value = n
  face.value = 'word'
  if (current.value) speak(current.value.word)
}
function prev() {
  idx.value = Math.max(0, idx.value - 1)
  face.value = 'word'
}
function toggle() {
  playing.value = !playing.value
  if (playing.value) schedule(face.value === 'word' ? wordDur.value : meaningDur.value)
  else clearTimeout(timer)
}
function pause() {
  playing.value = false
  clearTimeout(timer)
}
function exit() {
  pause()
  emit('exit')
}
</script>

<template>
  <div style="max-width: 560px; margin: 0 auto">
    <div class="row-between" style="margin-bottom: 14px">
      <span style="font-size: 12.5px; color: var(--mut)">{{ idx + 1 }} / {{ list.length }} ・ {{ loops + 1 }}周目</span>
      <button class="exit-btn" @click="exit">終了</button>
    </div>
    <div class="track" style="height: 6px; margin-bottom: 16px"><div :style="{ height: '100%', width: progress + '%', background: '#3b50cc', borderRadius: '99px' }"></div></div>

    <div class="card flashcard" @click="toggle">
      <div v-if="!playing" class="pause-badge">一時停止中（タップで再開）</div>
      <div v-if="face === 'word' && current">
        <div class="dm" style="font-size: 42px; font-weight: 700; margin-bottom: 8px">{{ current.word }}</div>
        <div style="font-size: 12px; color: var(--faint)">{{ current.partOfSpeech }}　画面タップで一時停止/再開</div>
      </div>
      <div v-else-if="current" style="font-size: 27px; font-weight: 700; color: #3b50cc">{{ current.meaning }}</div>
    </div>

    <div style="display: flex; align-items: center; justify-content: center; gap: 14px; margin-top: 18px">
      <button class="circle" @click="prev">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 6l-6 6 6 6" /></svg>
      </button>
      <button class="circle play" @click="toggle">{{ playing ? '停止' : '再生' }}</button>
      <button class="circle" @click="next">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 6l6 6-6 6" /></svg>
      </button>
    </div>
  </div>
</template>

<style scoped>
.row-between {
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.exit-btn {
  border: 1px solid #e3e6ea;
  background: #fff;
  border-radius: 9px;
  padding: 6px 12px;
  font-size: 12px;
  cursor: pointer;
  color: #6b7280;
  font-weight: 600;
}
.track {
  background: #eef0f3;
  border-radius: 99px;
  overflow: hidden;
}
.flashcard {
  position: relative;
  border-radius: 20px;
  padding: 56px 30px;
  text-align: center;
  cursor: pointer;
  min-height: 220px;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
}
.pause-badge {
  position: absolute;
  top: 12px;
  left: 50%;
  transform: translateX(-50%);
  font-size: 11px;
  font-weight: 600;
  color: #9aa1ab;
  background: #f1f3f5;
  padding: 3px 10px;
  border-radius: 99px;
}
.circle {
  border: 1px solid #e3e6ea;
  background: #fff;
  width: 44px;
  height: 44px;
  border-radius: 50%;
  cursor: pointer;
  color: #6b7280;
  display: flex;
  align-items: center;
  justify-content: center;
}
.circle.play {
  border: none;
  background: #1c2024;
  color: #fff;
  width: 56px;
  height: 56px;
  font-size: 13px;
  font-weight: 700;
}
</style>
