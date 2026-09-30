<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import client from '@/api/client'
import HelpTip from '@/components/HelpTip.vue'
import { quizApi, rateColor } from '@/api/quiz'
import { useUiStore } from '@/stores/ui'
import type { QuizDetail, QuizPageDetail } from '@/types'
import { saveFile } from '@/lib/native'

/**
 * 採点・添削結果の閲覧（生徒・講師共用）。全画面で回答画像（添削があれば合成画像）を表示し、
 * ページ切替・拡大縮小（ピンチ／ホイール／ダブルタップ）・画像の保存・PDF の表示／ダウンロードができる。
 */
const props = defineProps<{ quizId: number }>()
const emit = defineEmits<{ close: [] }>()

const ui = useUiStore()
const quiz = ref<QuizDetail | null>(null)
const loading = ref(true)
const downloading = ref(false)
const idx = ref(0)

const pages = computed(() => quiz.value?.pages ?? [])
const page = computed<QuizPageDetail | null>(() => pages.value[idx.value] ?? null)

// ---- 画像（認証付き URL を blob で取得してキャッシュ） ----
const urls = reactive(new Map<number, string>())
const imgLoading = ref(false)

function imagePath(p: QuizPageDetail): string | null {
  if (!quiz.value) return null
  if (p.hasAnnotated) return quizApi.annotatedImageUrl(quiz.value.id, p.id, p.annotatedVersion)
  if (p.hasAnswer) return quizApi.answerImageUrl(quiz.value.id, p.id, p.answerVersion)
  return null
}

async function ensureImage(p: QuizPageDetail | null) {
  if (!p || urls.has(p.id)) return
  const path = imagePath(p)
  if (!path) return
  try {
    const res = await client.get(path, { responseType: 'blob' })
    urls.set(p.id, URL.createObjectURL(res.data))
  } catch {
    // 取得失敗時は「画像を読み込めませんでした」を表示
  }
}

const currentUrl = computed(() => (page.value ? urls.get(page.value.id) ?? null : null))

watch(page, async (p) => {
  resetView()
  if (!p) return
  imgLoading.value = !urls.has(p.id)
  await ensureImage(p)
  imgLoading.value = false
  // 前後のページを先読み
  ensureImage(pages.value[idx.value + 1] ?? null)
  ensureImage(pages.value[idx.value - 1] ?? null)
})

onMounted(async () => {
  lockScroll()
  window.addEventListener('keydown', onKey)
  try {
    quiz.value = await quizApi.show(props.quizId)
  } catch {
    ui.notify('結果の取得に失敗しました')
  } finally {
    loading.value = false
  }
})

onBeforeUnmount(() => {
  unlockScroll()
  window.removeEventListener('keydown', onKey)
  urls.forEach((u) => URL.revokeObjectURL(u))
})

// ---- 全画面中は背面のスクロール・引っぱって更新を止める ----
let prevOverflow = ''
let prevOverscroll = ''
function lockScroll() {
  prevOverflow = document.body.style.overflow
  prevOverscroll = document.documentElement.style.overscrollBehavior
  document.body.style.overflow = 'hidden'
  document.documentElement.style.overscrollBehavior = 'none'
}
function unlockScroll() {
  document.body.style.overflow = prevOverflow
  document.documentElement.style.overscrollBehavior = prevOverscroll
}

// ---- ページ移動 ----
function go(i: number) {
  if (i < 0 || i >= pages.value.length) return
  idx.value = i
}
const hasPrev = computed(() => idx.value > 0)
const hasNext = computed(() => idx.value < pages.value.length - 1)

function onKey(e: KeyboardEvent) {
  if (e.key === 'Escape') emit('close')
  else if (e.key === 'ArrowLeft') go(idx.value - 1)
  else if (e.key === 'ArrowRight') go(idx.value + 1)
  else if (e.key === '+' || e.key === '=') zoomBy(1.25)
  else if (e.key === '-') zoomBy(1 / 1.25)
  else if (e.key === '0') resetView()
}

// ---- 拡大縮小・移動（transform: translate(tx,ty) scale(s)、原点は画像中心＝ステージ中心） ----
const MIN_S = 1
const MAX_S = 6
const view = reactive({ s: 1, tx: 0, ty: 0 })
const stage = ref<HTMLElement | null>(null)
const imgEl = ref<HTMLImageElement | null>(null)
const zoomed = computed(() => view.s > 1.01)

function resetView() {
  view.s = 1
  view.tx = 0
  view.ty = 0
}

/** ステージ中心からの相対座標 */
function rel(clientX: number, clientY: number): { x: number; y: number } {
  const r = stage.value!.getBoundingClientRect()
  return { x: clientX - r.left - r.width / 2, y: clientY - r.top - r.height / 2 }
}

/** 画像がステージ外に飛んでいかないよう移動量を制限する */
function clamp() {
  const st = stage.value
  const im = imgEl.value
  if (!st || !im) return
  const maxX = Math.max(0, (im.offsetWidth * view.s - st.clientWidth) / 2)
  const maxY = Math.max(0, (im.offsetHeight * view.s - st.clientHeight) / 2)
  view.tx = Math.min(maxX, Math.max(-maxX, view.tx))
  view.ty = Math.min(maxY, Math.max(-maxY, view.ty))
}

/** 点 p（ステージ中心基準）を固定して倍率を変える */
function zoomAt(ns: number, p = { x: 0, y: 0 }) {
  ns = Math.min(MAX_S, Math.max(MIN_S, ns))
  const k = ns / view.s
  view.tx = p.x - (p.x - view.tx) * k
  view.ty = p.y - (p.y - view.ty) * k
  view.s = ns
  if (ns <= 1.001) resetView()
  else clamp()
}
function zoomBy(f: number) {
  zoomAt(view.s * f)
}

function onWheel(e: WheelEvent) {
  e.preventDefault()
  zoomAt(view.s * Math.exp(-e.deltaY * 0.0015), rel(e.clientX, e.clientY))
}

const pointers = new Map<number, { x: number; y: number }>()
let gesture: { kind: 'pan' | 'swipe' | 'pinch'; sx: number; sy: number; tx: number; ty: number; s: number; dist: number; mid: { x: number; y: number } } | null = null
let lastTap = 0

function startGesture() {
  const pts = [...pointers.values()]
  if (pts.length >= 2) {
    const [a, b] = pts as [{ x: number; y: number }, { x: number; y: number }]
    gesture = {
      kind: 'pinch',
      sx: 0,
      sy: 0,
      tx: view.tx,
      ty: view.ty,
      s: view.s,
      dist: Math.hypot(a.x - b.x, a.y - b.y) || 1,
      mid: rel((a.x + b.x) / 2, (a.y + b.y) / 2),
    }
  } else if (pts.length === 1) {
    const a = pts[0]!
    gesture = { kind: zoomed.value ? 'pan' : 'swipe', sx: a.x, sy: a.y, tx: view.tx, ty: view.ty, s: view.s, dist: 0, mid: { x: 0, y: 0 } }
  } else {
    gesture = null
  }
}

function onDown(e: PointerEvent) {
  if (e.pointerType === 'mouse' && e.button !== 0) return
  stage.value?.setPointerCapture(e.pointerId)
  pointers.set(e.pointerId, { x: e.clientX, y: e.clientY })
  startGesture()
}

function onMove(e: PointerEvent) {
  if (!pointers.has(e.pointerId) || !gesture) return
  pointers.set(e.pointerId, { x: e.clientX, y: e.clientY })
  if (gesture.kind === 'pinch' && pointers.size >= 2) {
    const [a, b] = [...pointers.values()] as [{ x: number; y: number }, { x: number; y: number }]
    const dist = Math.hypot(a.x - b.x, a.y - b.y) || 1
    const mid = rel((a.x + b.x) / 2, (a.y + b.y) / 2)
    const ns = Math.min(MAX_S, Math.max(MIN_S, (gesture.s * dist) / gesture.dist))
    const k = ns / gesture.s
    // 開始時の中点を指の中点に追従させながら拡大
    view.tx = mid.x - (gesture.mid.x - gesture.tx) * k
    view.ty = mid.y - (gesture.mid.y - gesture.ty) * k
    view.s = ns
    clamp()
  } else if (gesture.kind === 'pan') {
    view.tx = gesture.tx + (e.clientX - gesture.sx)
    view.ty = gesture.ty + (e.clientY - gesture.sy)
    clamp()
  }
}

function onUp(e: PointerEvent) {
  if (!pointers.has(e.pointerId)) return
  const g = gesture
  pointers.delete(e.pointerId)
  if (g && g.kind === 'swipe' && pointers.size === 0) {
    const dx = e.clientX - g.sx
    const dy = e.clientY - g.sy
    if (Math.abs(dx) > 60 && Math.abs(dx) > Math.abs(dy) * 1.5) {
      go(idx.value + (dx < 0 ? 1 : -1))
    } else if (Math.abs(dx) < 8 && Math.abs(dy) < 8) {
      // ダブルタップ（ダブルクリック）で 1倍 ⇔ 2.5倍
      const now = Date.now()
      if (now - lastTap < 320) {
        if (zoomed.value) resetView()
        else zoomAt(2.5, rel(e.clientX, e.clientY))
        lastTap = 0
      } else {
        lastTap = now
      }
    }
  }
  if (view.s <= 1.001) resetView()
  startGesture()
}

// ---- 保存・PDF ----
function fileBase(): string {
  return (quiz.value?.title ?? '小テスト').replace(/[\\/:*?"<>|]/g, '_')
}

/** 表示中の画像を保存（iPhone・iPad は共有シートから「画像を保存」で写真に保存できる） */
async function saveImage() {
  const p = page.value
  const url = currentUrl.value
  if (!p || !url) return
  const blob = await (await fetch(url)).blob()
  await saveFile(blob, `${fileBase()}_p${p.pageNo}${p.hasAnnotated ? '_添削' : ''}.jpg`, { preferShare: true })
}

async function download(kind: 'result' | 'quiz') {
  if (!quiz.value) return
  downloading.value = true
  try {
    // 問題 PDF は別タブでプレビュー、添削済み PDF はダウンロード
    if (kind === 'result') await quizApi.downloadResultPdf(quiz.value.id, quiz.value.title)
    else await quizApi.previewQuizPdf(quiz.value.id, false, quiz.value.title)
  } catch {
    ui.notify(kind === 'result' ? 'ダウンロードに失敗しました' : 'PDF の表示に失敗しました')
  } finally {
    downloading.value = false
  }
}

const commentOpen = ref(true)
</script>

<template>
  <div class="rv ui-fullscreen">
    <!-- 上部: タイトル・得点・閉じる -->
    <div class="rv-top">
      <div class="rv-title">
        <div class="t1">{{ quiz?.title ?? '採点・添削結果' }}</div>
        <div v-if="quiz" class="t2">
          {{ quiz.bookTitle ?? '英単語テスト' }}・{{ quiz.pageCount }}ページ<template v-if="quiz.gradedAt">・{{ quiz.selfGraded ? '自己採点' : '採点・添削' }} {{ quiz.gradedAt.slice(0, 10).replace(/-/g, '/') }}</template>
        </div>
      </div>
      <div v-if="quiz" class="rv-score">
        <template v-if="quiz.score !== null">
          <b :style="{ color: rateColor(quiz.rate) === '#3b50cc' ? '#8fa0ff' : rateColor(quiz.rate) }">{{ quiz.score }}</b><span class="max"> / {{ quiz.maxScore }}点</span>
          <span v-if="quiz.rate !== null" class="rate-chip" :style="{ background: rateColor(quiz.rate) }">{{ quiz.rate }}%</span>
        </template>
        <span v-else class="max">未採点</span>
        <span v-if="quiz.selfGraded" class="self-chip">自己採点</span>
      </div>
      <HelpTip align="right" text="ピンチ・ホイール・ダブルタップで拡大／縮小&#10;拡大中はドラッグで移動&#10;左右スワイプ・← → キーでページ切替&#10;Esc で閉じる" />
      <button class="rv-x" aria-label="閉じる" @click="emit('close')">×</button>
    </div>

    <!-- ページ一覧 -->
    <div v-if="pages.length > 1" class="rv-strip">
      <button v-for="(p, i) in pages" :key="p.id" class="rv-chip" :class="{ on: i === idx }" @click="go(i)">
        <span class="n">{{ p.pageNo }}</span>
        <span class="l">{{ p.label }}</span>
      </button>
    </div>

    <!-- 画像 -->
    <div
      ref="stage"
      class="rv-stage"
      @wheel="onWheel"
      @pointerdown="onDown"
      @pointermove="onMove"
      @pointerup="onUp"
      @pointercancel="onUp"
    >
      <div v-if="loading || imgLoading" class="rv-msg">読み込み中…</div>
      <div v-else-if="page && !currentUrl" class="rv-msg">{{ page.hasAnswer ? '画像を読み込めませんでした' : '未提出' }}</div>
      <img
        v-if="currentUrl"
        ref="imgEl"
        :src="currentUrl"
        class="rv-img"
        :class="{ grab: zoomed }"
        alt=""
        draggable="false"
        :style="{ transform: `translate(${view.tx}px, ${view.ty}px) scale(${view.s})` }"
      />
      <button v-if="hasPrev" class="rv-nav prev" aria-label="前のページ" @pointerdown.stop @click="go(idx - 1)">‹</button>
      <button v-if="hasNext" class="rv-nav next" aria-label="次のページ" @pointerdown.stop @click="go(idx + 1)">›</button>
      <div class="rv-zoom" @pointerdown.stop>
        <button aria-label="縮小" @click="zoomBy(1 / 1.25)">−</button>
        <span>{{ Math.round(view.s * 100) }}%</span>
        <button aria-label="拡大" @click="zoomBy(1.25)">＋</button>
        <button class="fit" @click="resetView">全体</button>
      </div>
    </div>

    <!-- 下部: ページ情報・コメント・保存 -->
    <div v-if="quiz" class="rv-bottom">
      <div v-if="page" class="rv-info">
        <div class="pg">
          <b>{{ page.pageNo }}. {{ page.label }}</b>
          <span class="sub">
            <template v-if="page.kind === 'vocab'">英単語テスト・{{ page.vocabSpec?.resourceName }}</template>
            <template v-else>{{ page.chapter }}<span v-if="page.difficulty" style="color: #f0b25a; margin-left: 6px">{{ page.difficulty }}</span>
              <span v-if="page.answerMinutes !== null || page.guideMinutes" style="margin-left: 8px; color: #b7bcc6">
                <template v-if="page.answerMinutes !== null">回答 {{ page.answerMinutes }}分</template>
                <template v-if="page.guideMinutes"><template v-if="page.answerMinutes !== null"> / </template>目安 {{ page.guideMinutes }}分</template>
              </span></template>
          </span>
          <span class="cnt">{{ idx + 1 }} / {{ pages.length }}</span>
        </div>
        <div v-if="page.comment" class="rv-comment" :class="{ closed: !commentOpen }" @click="commentOpen = !commentOpen">
          <span class="lbl">先生のコメント</span>{{ page.comment }}
        </div>
      </div>
      <div class="rv-acts">
        <button class="btn" :disabled="!currentUrl" @click="saveImage">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 4v11" /><path d="M7 10l5 5 5-5" /><path d="M5 19h14" /></svg>
          画像を保存
        </button>
        <button class="btn" :disabled="downloading" @click="download('quiz')">問題PDF</button>
        <button class="btn primary" :disabled="downloading" @click="download('result')">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 4v11" /><path d="M7 10l5 5 5-5" /><path d="M5 19h14" /></svg>
          {{ quiz.selfGraded ? '回答PDF' : '添削済みPDF' }}
        </button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.rv {
  position: fixed;
  inset: 0;
  z-index: 90;
  background: #111418;
  color: #fff;
  display: flex;
  flex-direction: column;
  overscroll-behavior: none;
  touch-action: none;
  /* 横向きのスマホでノッチに重ならないようにする */
  padding-left: env(safe-area-inset-left);
  padding-right: env(safe-area-inset-right);
}
.rv-top {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px 12px 8px 16px;
  padding-top: max(10px, env(safe-area-inset-top));
}
.rv-title {
  flex: 1;
  min-width: 0;
}
.t1 {
  font-size: 14px;
  font-weight: 700;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.t2 {
  font-size: 11px;
  color: #9aa1ab;
  margin-top: 1px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.rv-score {
  display: flex;
  align-items: baseline;
  gap: 4px;
  flex-shrink: 0;
}
.rv-score b {
  font-size: 22px;
  font-weight: 800;
}
.max {
  font-size: 12px;
  color: #b7bcc6;
}
.rate-chip {
  margin-left: 6px;
  padding: 2px 9px;
  border-radius: 999px;
  color: #fff;
  font-size: 11.5px;
  font-weight: 700;
  align-self: center;
}
.self-chip {
  margin-left: 6px;
  font-size: 10.5px;
  font-weight: 700;
  padding: 3px 9px;
  border-radius: 999px;
  background: #efe9fb;
  color: #5b3fa0;
  align-self: center;
}
.rv-x {
  width: 36px;
  height: 36px;
  border: none;
  border-radius: 50%;
  background: rgba(255, 255, 255, 0.1);
  color: #fff;
  font-size: 22px;
  line-height: 1;
  cursor: pointer;
  flex-shrink: 0;
}
.rv-strip {
  display: flex;
  gap: 6px;
  overflow-x: auto;
  padding: 0 12px 8px;
  scrollbar-width: thin;
  touch-action: pan-x;
}
.rv-chip {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  max-width: 220px;
  padding: 5px 10px 5px 5px;
  border: 1px solid rgba(255, 255, 255, 0.18);
  border-radius: 999px;
  background: transparent;
  color: #d5d9e0;
  font-size: 11.5px;
  cursor: pointer;
  flex-shrink: 0;
}
.rv-chip .n {
  width: 20px;
  height: 20px;
  border-radius: 50%;
  background: rgba(255, 255, 255, 0.12);
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 10.5px;
  font-weight: 700;
}
.rv-chip .l {
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.rv-chip.on {
  background: #fff;
  color: #1c2024;
  border-color: #fff;
}
.rv-chip.on .n {
  background: #1c2024;
  color: #fff;
}
.rv-stage {
  position: relative;
  flex: 1;
  min-height: 0;
  overflow: hidden;
  display: flex;
  align-items: center;
  justify-content: center;
  touch-action: none;
  user-select: none;
  -webkit-user-select: none;
}
.rv-img {
  max-width: 100%;
  max-height: 100%;
  object-fit: contain;
  transform-origin: center center;
  will-change: transform;
  -webkit-user-drag: none;
  pointer-events: none;
}
.rv-msg {
  font-size: 13px;
  color: #9aa1ab;
}
.rv-nav {
  position: absolute;
  top: 50%;
  transform: translateY(-50%);
  width: 44px;
  height: 64px;
  border: none;
  border-radius: 12px;
  background: rgba(0, 0, 0, 0.45);
  color: #fff;
  font-size: 30px;
  line-height: 1;
  cursor: pointer;
}
.rv-nav.prev {
  left: 10px;
}
.rv-nav.next {
  right: 10px;
}
.rv-zoom {
  position: absolute;
  right: 12px;
  bottom: 12px;
  display: flex;
  align-items: center;
  gap: 2px;
  padding: 3px;
  border-radius: 10px;
  background: rgba(0, 0, 0, 0.55);
}
.rv-zoom button {
  min-width: 32px;
  height: 30px;
  border: none;
  border-radius: 7px;
  background: transparent;
  color: #fff;
  font-size: 15px;
  cursor: pointer;
}
.rv-zoom button:hover {
  background: rgba(255, 255, 255, 0.12);
}
.rv-zoom .fit {
  font-size: 11.5px;
  padding: 0 8px;
}
.rv-zoom span {
  font-size: 11px;
  color: #d5d9e0;
  min-width: 40px;
  text-align: center;
}
.rv-bottom {
  display: flex;
  align-items: flex-end;
  gap: 12px;
  padding: 10px 14px;
  padding-bottom: max(10px, env(safe-area-inset-bottom));
  border-top: 1px solid rgba(255, 255, 255, 0.08);
  flex-wrap: wrap;
}
.rv-info {
  flex: 1;
  min-width: 220px;
}
.pg {
  display: flex;
  align-items: baseline;
  gap: 8px;
  font-size: 12.5px;
}
.pg .sub {
  font-size: 11px;
  color: #9aa1ab;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.pg .cnt {
  margin-left: auto;
  font-size: 11px;
  color: #9aa1ab;
  flex-shrink: 0;
}
.rv-comment {
  margin-top: 6px;
  font-size: 12.5px;
  line-height: 1.6;
  color: #3a2f10;
  background: #fff4d6;
  border-radius: 8px;
  padding: 7px 10px;
  white-space: pre-wrap;
  max-height: 22vh;
  overflow-y: auto;
  cursor: pointer;
  touch-action: pan-y;
}
.rv-comment.closed {
  max-height: 1.9em;
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
}
.rv-comment .lbl {
  font-size: 10.5px;
  font-weight: 700;
  color: #9a7412;
  margin-right: 8px;
}
.rv-acts {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
  margin-left: auto;
}
.btn {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  height: 36px;
  padding: 0 13px;
  border: 1px solid rgba(255, 255, 255, 0.22);
  border-radius: 9px;
  background: transparent;
  color: #e6e8ec;
  font-size: 12.5px;
  font-weight: 600;
  cursor: pointer;
  white-space: nowrap;
}
.btn.primary {
  background: #fff;
  border-color: #fff;
  color: #1c2024;
}
.btn:disabled {
  opacity: 0.45;
}
@media (max-width: 640px) {
  .rv-score b {
    font-size: 18px;
  }
  .rv-nav {
    width: 36px;
    height: 52px;
    font-size: 26px;
  }
  .rv-acts {
    width: 100%;
  }
  .rv-acts .btn {
    flex: 1;
    justify-content: center;
    padding: 0 8px;
  }
}
</style>
