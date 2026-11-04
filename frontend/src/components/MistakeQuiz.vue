<script setup lang="ts">
/**
 * 間違いノートの問題解答モード（全画面）。単語クイズのように問題の画像だけを大きく表示し、頭の中で解いてから
 * 「解答を表示」→ 解答の画像（登録がなければページ全体＝問題＋解説・手書き）を見て、
 * もう一度 ／ 難しい ／ 正解 ／ 簡単 の 4 段階で評価する（左ほど定着していない）。
 * 評価はサーバーに保存され、忘却曲線（間隔反復）に合わせて次に出題される日が決まる。ボタンの下にその間隔を表示する。
 * 「もう一度」にした問題は同じ回の最後にもう一度出る。
 * キー: Space / Enter = 解答を表示、1〜4 = 評価、← → = 問題と解答の切替、Esc = 閉じる。
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { mistakeApi } from '@/api/mistake'
import { contentBox, imageObjectUrl, loadImageEl } from '@/lib/mistake'
import { drawNoteContent } from '@/lib/mistakeRender'
import { blockDoubleTapZoom, blockUiTextSelection, haptic, isTouch } from '@/lib/native'
import { useUiStore } from '@/stores/ui'
import type { ImageCrop, MistakeGrade, MistakeLayout, MistakeNoteDetail, MistakeNoteSummary, MistakeReviewState } from '@/types'

const props = defineProps<{ notes: MistakeNoteSummary[]; title: string }>()
const emit = defineEmits<{ close: []; graded: [noteId: number, review: MistakeReviewState] }>()
const ui = useUiStore()

const GRADES: { key: MistakeGrade; label: string; color: string; bg: string; bd: string; hint: string }[] = [
  { key: 'again', label: 'もう一度', color: '#e0533d', bg: '#fcebe8', bd: '#f3bdb4', hint: '解けなかった。この回の最後にもう一度出ます' },
  { key: 'hard', label: '難しい', color: '#d98a2b', bg: '#fbf1e5', bd: '#f0d0a8', hint: '解けたが時間がかかった・自信がない' },
  { key: 'good', label: '正解', color: '#2e9d62', bg: '#e8f5ee', bd: '#b6e0c7', hint: '普通に解けた' },
  { key: 'easy', label: '簡単', color: '#3b50cc', bg: '#eaedf9', bd: '#bcc5ee', hint: 'すぐに解けた。次はしばらく後に出ます' },
]

// ---- 出題の列（「もう一度」は末尾に戻す） ----
const queue = ref<MistakeNoteSummary[]>(props.notes.slice())
const idx = ref(0)
const current = computed(() => queue.value[idx.value] ?? null)
const finished = computed(() => idx.value >= queue.value.length)
/** 評価の集計（問題ごとの最後の評価） */
const lastGrade = new Map<number, MistakeGrade>()
const counts = ref<Record<MistakeGrade, number>>({ again: 0, hard: 0, good: 0, easy: 0 })
const revealed = ref(false)
/** 解答を表示した後、問題と解答のどちらを見せているか */
const face = ref<'problem' | 'answer'>('problem')
const busy = ref(false)
const root = ref<HTMLElement | null>(null)

// ---- 画像（問題・解答）の用意。問題は layout のトリミングを反映。解答は解答の画像、無ければページ全体を描く ----
const detailCache = new Map<number, Promise<MistakeNoteDetail>>()
const problemCache = new Map<number, Promise<string>>()
const answerCache = new Map<number, Promise<{ url: string; isPage: boolean }>>()
const problemUrl = ref<string | null>(null)
const answer = ref<{ url: string; isPage: boolean } | null>(null)
const loadingImg = ref(false)

function detailOf(id: number): Promise<MistakeNoteDetail> {
  let p = detailCache.get(id)
  if (!p) {
    p = mistakeApi.show(id)
    p.catch(() => detailCache.delete(id))
    detailCache.set(id, p)
  }
  return p
}
/** 画像をトリミングして JPEG の data URL に（トリミングが無ければ元の object URL） */
async function croppedUrl(imageId: number, crop: ImageCrop | undefined): Promise<string> {
  if (!crop) return imageObjectUrl(imageId)
  const img = await loadImageEl(imageId)
  const sx = crop.x * img.naturalWidth
  const sy = crop.y * img.naturalHeight
  const sw = Math.max(1, crop.w * img.naturalWidth)
  const sh = Math.max(1, crop.h * img.naturalHeight)
  const c = document.createElement('canvas')
  c.width = Math.round(sw)
  c.height = Math.round(sh)
  const x = c.getContext('2d')!
  x.fillStyle = '#fff'
  x.fillRect(0, 0, c.width, c.height)
  x.drawImage(img, sx, sy, sw, sh, 0, 0, c.width, c.height)
  return c.toDataURL('image/jpeg', 0.9)
}
/** ページ全体（問題＋説明・手書き）を描いて data URL に */
async function pageUrl(layout: MistakeLayout): Promise<string> {
  const box = contentBox(layout) ?? { x: 0, y: 0, w: 1050, h: 600 }
  const scale = Math.min(1.5, 1600 / Math.max(box.w, box.h))
  const c = document.createElement('canvas')
  c.width = Math.max(1, Math.round(box.w * scale))
  c.height = Math.max(1, Math.round(box.h * scale))
  const x = c.getContext('2d')!
  x.fillStyle = '#fff'
  x.fillRect(0, 0, c.width, c.height)
  await drawNoteContent(x, layout, 0, 0, scale, c.width, c.height)
  return c.toDataURL('image/jpeg', 0.88)
}
function problemOf(n: MistakeNoteSummary): Promise<string> {
  let p = problemCache.get(n.id)
  if (!p) {
    p = (async () => {
      const d = await detailOf(n.id)
      const item = d.layout.items.find((i) => i.role === 'problem' && i.imageId)
      const imageId = item?.imageId ?? d.images.find((i) => i.role === 'problem')?.id ?? n.problemImageId
      if (!imageId) throw new Error('問題の写真がありません')
      return croppedUrl(imageId, item?.crop)
    })()
    p.catch(() => problemCache.delete(n.id))
    problemCache.set(n.id, p)
  }
  return p
}
function answerOf(n: MistakeNoteSummary): Promise<{ url: string; isPage: boolean }> {
  let p = answerCache.get(n.id)
  if (!p) {
    p = (async () => {
      const d = await detailOf(n.id)
      const img = d.images.find((i) => i.role === 'answer')
      if (img) {
        const item = d.layout.items.find((i) => i.imageId === img.id)
        return { url: await croppedUrl(img.id, item?.crop), isPage: false }
      }
      return { url: await pageUrl(d.layout), isPage: true }
    })()
    p.catch(() => answerCache.delete(n.id))
    answerCache.set(n.id, p)
  }
  return p
}
let loadSeq = 0
watch(
  current,
  async (n) => {
    revealed.value = false
    face.value = 'problem'
    problemUrl.value = null
    answer.value = null
    if (!n) return
    const seq = ++loadSeq
    loadingImg.value = true
    try {
      const u = await problemOf(n)
      if (seq !== loadSeq) return
      problemUrl.value = u
    } catch {
      if (seq === loadSeq) ui.notify('問題の画像を読み込めませんでした')
    } finally {
      if (seq === loadSeq) loadingImg.value = false
    }
    // 解答と次の問題は先に用意しておく
    answerOf(n).catch(() => {})
    const next = queue.value[idx.value + 1]
    if (next) problemOf(next).catch(() => {})
  },
  { immediate: true },
)

async function reveal() {
  if (revealed.value || !current.value) return
  revealed.value = true
  face.value = 'answer'
  haptic()
  try {
    answer.value = await answerOf(current.value)
  } catch {
    ui.notify('解答の画像を読み込めませんでした')
  }
}
async function grade(g: MistakeGrade) {
  const n = current.value
  if (!n || !revealed.value || busy.value) return
  busy.value = true
  try {
    const review = await mistakeApi.review(n.id, g)
    emit('graded', n.id, review)
    n.review = review
    const prev = lastGrade.get(n.id)
    if (prev) counts.value[prev]--
    lastGrade.set(n.id, g)
    counts.value[g]++
    if (g === 'again') queue.value = [...queue.value, n]
    haptic(g === 'again' ? 20 : 8)
    idx.value++
  } catch {
    ui.notify('評価の保存に失敗しました')
  } finally {
    busy.value = false
  }
}
/** 終わった後: もう一度（全部 or 難しかったもの） */
function restart(which: 'all' | 'hard') {
  const list = which === 'all' ? props.notes.slice() : props.notes.filter((n) => ['again', 'hard'].includes(lastGrade.get(n.id) ?? ''))
  if (!list.length) return
  lastGrade.clear()
  counts.value = { again: 0, hard: 0, good: 0, easy: 0 }
  queue.value = list
  idx.value = 0
}
const hardCount = computed(() => props.notes.filter((n) => ['again', 'hard'].includes(lastGrade.get(n.id) ?? '')).length)
const doneCount = computed(() => lastGrade.size)

const label = computed(() => {
  const n = current.value
  if (!n) return ''
  return [n.subjectName, n.field, [n.studyItemName, n.title].filter(Boolean).join('　')].filter(Boolean).join(' ／ ')
})
const stateChip = computed(() => {
  const r = current.value?.review
  if (!r) return null
  if (r.isNew) return { text: '未学習', cls: 'new' }
  if (r.isDue) return { text: '復習', cls: 'due' }
  return { text: `${r.reps} 回目`, cls: 'ok' }
})

// ---- 操作 ----
function onKey(e: KeyboardEvent) {
  if (e.key === 'Escape') {
    emit('close')
    return
  }
  if (finished.value) return
  if (e.key === ' ' || e.key === 'Enter') {
    e.preventDefault()
    if (!revealed.value) reveal()
    return
  }
  if (revealed.value) {
    const i = ['1', '2', '3', '4'].indexOf(e.key)
    if (i >= 0) {
      e.preventDefault()
      grade(GRADES[i]!.key)
      return
    }
    if (e.key === 'ArrowLeft') face.value = 'problem'
    else if (e.key === 'ArrowRight') face.value = 'answer'
  }
}
const unblock: (() => void)[] = []
onMounted(() => {
  window.addEventListener('keydown', onKey)
  if (root.value) {
    unblock.push(blockUiTextSelection(root.value))
    if (isTouch) unblock.push(blockDoubleTapZoom(root.value))
  }
})
onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKey)
  unblock.forEach((f) => f())
})
</script>

<template>
  <div ref="root" class="quiz ui-fullscreen">
    <div class="bar">
      <button class="x" aria-label="閉じる" @click="emit('close')">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18" /></svg>
      </button>
      <div class="bar-title">
        <div class="bar-main">{{ title }}</div>
        <div v-if="label && !finished" class="bar-sub">{{ label }}</div>
      </div>
      <span v-if="stateChip && !finished" class="chip" :class="stateChip.cls">{{ stateChip.text }}</span>
      <div class="bar-pos dm">{{ Math.min(queue.length, idx + 1) }} / {{ queue.length }}</div>
    </div>

    <!-- 終了 -->
    <div v-if="finished" class="done">
      <div class="done-card">
        <div class="done-title">おつかれさまでした</div>
        <div class="done-sub">{{ doneCount }} 問を解きました</div>
        <div class="done-grid">
          <div v-for="g in GRADES" :key="g.key" class="done-cell" :style="{ '--c': g.color, background: g.bg, borderColor: g.bd }">
            <div class="done-n dm">{{ counts[g.key] }}</div>
            <div class="done-l">{{ g.label }}</div>
          </div>
        </div>
        <div class="done-note">次に出る日は忘却曲線に合わせて決まります。「今日の復習」で期限が来た問題をまとめて解けます。</div>
        <div class="done-actions">
          <button v-if="hardCount" class="g-btn ghost" @click="restart('hard')">難しかった {{ hardCount }} 問をもう一度</button>
          <button class="g-btn ghost" @click="restart('all')">すべてもう一度</button>
          <button class="g-btn solid" @click="emit('close')">閉じる</button>
        </div>
      </div>
    </div>

    <template v-else>
      <!-- 画像 -->
      <div class="stage" @click="revealed && (face = face === 'problem' ? 'answer' : 'problem')">
        <div v-if="revealed" class="face-seg" v-seg @click.stop>
          <button class="fs-b" :class="{ on: face === 'problem' }" @click="face = 'problem'">問題</button>
          <button class="fs-b" :class="{ on: face === 'answer' }" @click="face = 'answer'">{{ answer?.isPage ? '解答（ページ全体）' : '解答' }}</button>
        </div>
        <Transition name="face" mode="out-in">
          <img v-if="face === 'problem' && problemUrl" key="p" :src="problemUrl" alt="問題" draggable="false" />
          <img v-else-if="face === 'answer' && answer" key="a" :src="answer.url" alt="解答" draggable="false" />
          <div v-else key="l" class="loading">{{ loadingImg || (face === 'answer' && !answer) ? '読み込み中…' : '画像がありません' }}</div>
        </Transition>
      </div>

      <!-- 操作 -->
      <div class="foot">
        <button v-if="!revealed" class="reveal" :disabled="!problemUrl && loadingImg" @click="reveal">
          解答を表示<span class="kbd">Space</span>
        </button>
        <div v-else class="grades">
          <button v-for="(g, i) in GRADES" :key="g.key" class="grade" :style="{ '--c': g.color }" :disabled="busy" :title="g.hint" @click="grade(g.key)">
            <span class="g-label">{{ g.label }}</span>
            <span class="g-iv">{{ current?.review.preview[g.key] }}</span>
            <span class="kbd g-kbd">{{ i + 1 }}</span>
          </button>
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped>
.quiz {
  position: fixed;
  inset: 0;
  z-index: 200;
  display: flex;
  flex-direction: column;
  background: #2b2f36;
  color: #fff;
  -webkit-user-select: none;
  user-select: none;
  -webkit-touch-callout: none;
  touch-action: manipulation;
}
.bar {
  display: flex;
  align-items: center;
  gap: 12px;
  min-height: calc(52px + env(safe-area-inset-top));
  padding: env(safe-area-inset-top) 14px 0 10px;
  background: #1c2024;
  flex-shrink: 0;
}
.bar-title {
  flex: 1;
  min-width: 0;
}
.bar-main {
  font-size: 14px;
  font-weight: 700;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.bar-sub {
  font-size: 11.5px;
  color: #b7bcc5;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.bar-pos {
  font-size: 13px;
  color: #b7bcc5;
  white-space: nowrap;
}
.chip {
  font-size: 10.5px;
  font-weight: 700;
  padding: 2px 8px;
  border-radius: 999px;
  white-space: nowrap;
}
.chip.new {
  background: #5b6b8c;
}
.chip.due {
  background: #e0533d;
}
.chip.ok {
  background: rgba(255, 255, 255, 0.18);
}
.x {
  width: 38px;
  height: 38px;
  border: none;
  border-radius: 50%;
  background: rgba(255, 255, 255, 0.1);
  color: #fff;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  flex-shrink: 0;
}
.stage {
  position: relative;
  flex: 1;
  min-height: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 14px;
}
.stage img {
  max-width: 100%;
  max-height: 100%;
  object-fit: contain;
  background: #fff;
  border-radius: 6px;
  box-shadow: 0 10px 40px rgba(0, 0, 0, 0.45);
  -webkit-touch-callout: none;
  pointer-events: none;
}
.loading {
  color: #9aa1ab;
  font-size: 13px;
}
.face-enter-active,
.face-leave-active {
  transition: opacity 0.16s ease, transform 0.16s ease;
}
.face-enter-from,
.face-leave-to {
  opacity: 0;
  transform: scale(0.985);
}
.face-seg {
  position: absolute;
  top: 10px;
  left: 50%;
  transform: translateX(-50%);
  z-index: 2;
  display: inline-flex;
  padding: 3px;
  border-radius: 10px;
  background: rgba(28, 32, 36, 0.85);
  box-shadow: 0 2px 10px rgba(0, 0, 0, 0.35);
}
.fs-b {
  padding: 6px 14px;
  border: none;
  border-radius: 8px;
  background: transparent;
  font-size: 12.5px;
  font-weight: 700;
  color: #b7bcc5;
  cursor: pointer;
  white-space: nowrap;
}
.fs-b.on {
  background: #fff;
  color: #1c2024;
}
.foot {
  flex-shrink: 0;
  padding: 10px 14px calc(14px + env(safe-area-inset-bottom));
  background: #1c2024;
}
.reveal {
  width: 100%;
  max-width: 560px;
  margin: 0 auto;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 10px;
  padding: 16px;
  border: none;
  border-radius: 14px;
  background: #fff;
  color: #1c2024;
  font-size: 16px;
  font-weight: 800;
  cursor: pointer;
}
.reveal:disabled {
  opacity: 0.5;
}
.kbd {
  font-size: 10px;
  font-weight: 700;
  color: #9aa1ab;
  border: 1px solid #c3c8d0;
  border-radius: 5px;
  padding: 1px 5px;
  font-family: ui-monospace, monospace;
}
.grades {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 8px;
  max-width: 760px;
  margin: 0 auto;
}
.grade {
  position: relative;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 2px;
  padding: 12px 6px 10px;
  border: 2px solid var(--c);
  border-radius: 14px;
  background: var(--c);
  color: #fff;
  cursor: pointer;
  transition: transform 0.12s ease, filter 0.12s ease;
}
.grade:hover {
  filter: brightness(1.08);
}
.grade:active {
  transform: scale(0.97);
}
.grade:disabled {
  opacity: 0.5;
}
.g-label {
  font-size: 15px;
  font-weight: 800;
}
.g-iv {
  font-size: 11.5px;
  font-weight: 600;
  opacity: 0.9;
}
.g-kbd {
  position: absolute;
  top: 6px;
  right: 7px;
  color: rgba(255, 255, 255, 0.8);
  border-color: rgba(255, 255, 255, 0.45);
}
@media (hover: none) {
  .kbd {
    display: none;
  }
}
@media (max-width: 520px) {
  .grades {
    gap: 6px;
  }
  .g-label {
    font-size: 13px;
  }
  .grade {
    padding: 10px 4px 8px;
  }
}

/* 終了 */
.done {
  flex: 1;
  min-height: 0;
  overflow-y: auto;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 20px;
}
.done-card {
  width: min(100%, 520px);
  background: #fff;
  color: #1c2024;
  border-radius: 20px;
  padding: 28px 24px 22px;
  text-align: center;
  display: flex;
  flex-direction: column;
  gap: 14px;
}
.done-title {
  font-size: 20px;
  font-weight: 800;
}
.done-sub {
  font-size: 13px;
  color: var(--mut);
  margin-top: -8px;
}
.done-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 8px;
}
.done-cell {
  padding: 10px 4px;
  border-radius: 12px;
  border: 1px solid;
}
.done-n {
  font-size: 24px;
  font-weight: 800;
  color: var(--c);
}
.done-l {
  font-size: 11.5px;
  font-weight: 700;
  color: var(--mut);
}
.done-note {
  font-size: 12px;
  color: var(--faint);
  line-height: 1.6;
}
.done-actions {
  display: flex;
  flex-wrap: wrap;
  justify-content: center;
  gap: 8px;
}
.g-btn {
  padding: 11px 18px;
  border-radius: 12px;
  font-size: 13.5px;
  font-weight: 700;
  cursor: pointer;
}
.g-btn.ghost {
  border: 1px solid #e3e6ea;
  background: #fff;
  color: var(--mut);
}
.g-btn.solid {
  border: none;
  background: #1c2024;
  color: #fff;
}
</style>
