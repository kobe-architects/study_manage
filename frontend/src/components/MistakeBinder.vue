<script setup lang="ts">
/**
 * 間違いノートの全画面閲覧。印刷と同じページ（見出し・とじ代・ページ番号つき。1 ページに複数の問題）を見開きで表示し、
 * 右端の科目・分野のインデックス（タブ）から該当ページへ飛べる。
 * ページ 1 は右側（奇数ページ = とじ側が左）。スマホ幅では 1 ページずつ表示する。
 * ←→ キー・画面の左右タップ・スワイプでめくる。
 * iPad・スマホの横向き（immersive）では上のバーとタブを出さず、浮かせた ×・≡ だけにしてノートを大きく見せる（インデックスは ≡ のメニュー）。
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { mistakeApi } from '@/api/mistake'
import { PAGE_H, PAGE_W, groupKeyOf, groupNotes, pageIndexOf, paginate } from '@/lib/mistake'
import { renderBinderPage } from '@/lib/mistakeRender'
import { blockDoubleTapZoom, isTouch, viewportWidth } from '@/lib/native'
import type { MistakeNoteDetail, MistakeNoteSummary } from '@/types'

const props = defineProps<{ notes: MistakeNoteSummary[]; startId?: number | null }>()
const emit = defineEmits<{ close: [] }>()

const single = computed(() => viewportWidth.value < 760)
/** iPad・スマホの横向き: 上のバーと右端のインデックスを出さず、ノートを大きく見せる（インデックスは右上の ≡ から） */
const landscapeMq = window.matchMedia('(orientation: landscape)')
const landscape = ref(landscapeMq.matches)
const onOrient = () => (landscape.value = landscapeMq.matches)
const immersive = computed(() => isTouch && landscape.value && !single.value)
const idxOpen = ref(false)
watch(immersive, (v) => {
  if (!v) idxOpen.value = false
})
const root = ref<HTMLElement | null>(null)
let unblockTap: (() => void) | undefined
/** ページ割り（1 ページに複数の問題） */
const pages = computed(() => paginate(props.notes))
const total = computed(() => pages.value.length)

/** 右ページ（見開き）または表示ページ（1 ページ表示）の 0 始まりの番号 */
const pos = ref(0)
function spreadOf(i: number): number {
  return single.value ? i : i % 2 === 0 ? i : i + 1
}
const leftIdx = computed(() => (single.value ? -1 : pos.value - 1))
const rightIdx = computed(() => pos.value)
const canPrev = computed(() => pos.value > 0)
const canNext = computed(() => (single.value ? pos.value < total.value - 1 : pos.value + 1 <= total.value - 1))

function go(i: number) {
  pos.value = Math.max(0, Math.min(total.value - 1, spreadOf(i)))
}
function next() {
  if (!canNext.value) return
  pos.value = Math.min(total.value - 1, pos.value + (single.value ? 1 : 2))
}
function prev() {
  if (!canPrev.value) return
  pos.value = Math.max(0, pos.value - (single.value ? 1 : 2))
}
watch(single, () => go(pos.value))

// ---- ページの描画（印刷と同じ見た目）。結果はページ番号ごとにキャッシュ ----
const detailCache = new Map<number, Promise<MistakeNoteDetail>>()
const pageCache = new Map<number, Promise<string>>()
const urls = ref<Record<number, string>>({})

function detailOf(id: number): Promise<MistakeNoteDetail> {
  let p = detailCache.get(id)
  if (!p) {
    p = mistakeApi.show(id)
    p.catch(() => detailCache.delete(id))
    detailCache.set(id, p)
  }
  return p
}
async function ensurePage(i: number) {
  const page = pages.value[i]
  if (!page || urls.value[i] || pageCache.has(i)) return
  const p = (async () => {
    const details = new Map<number, MistakeNoteDetail>()
    await Promise.all(page.entries.map((e) => detailOf(e.note.id).then((d) => details.set(e.note.id, d)).catch(() => {})))
    const c = await renderBinderPage(page, details, { pageNo: i + 1, scale: 1 })
    return c.toDataURL('image/jpeg', 0.85)
  })()
  pageCache.set(i, p)
  try {
    urls.value = { ...urls.value, [i]: await p }
  } catch {
    pageCache.delete(i)
  }
}
watch(
  [pos, single],
  async () => {
    const want = single.value ? [pos.value, pos.value + 1, pos.value - 1] : [pos.value, pos.value - 1, pos.value + 1, pos.value + 2, pos.value - 2, pos.value - 3]
    for (const i of want) if (i >= 0 && i < total.value) await ensurePage(i)
  },
  { immediate: true },
)

const pageUrl = (i: number) => (i >= 0 && i < total.value ? urls.value[i] : undefined)
const pageAt = (i: number) => (i >= 0 && i < total.value ? pages.value[i] : undefined)

// ---- インデックス（科目 → 分野） ----
const groups = computed(() => groupNotes(props.notes))
const subjects = computed(() => {
  const out: { key: string; name: string; color: string | null; first: number }[] = []
  pages.value.forEach((p, i) => {
    const key = String(p.subjectId ?? 0)
    if (!out.some((x) => x.key === key)) out.push({ key, name: p.subjectName, color: p.color, first: i })
  })
  return out
})
const current = computed(() => pageAt(rightIdx.value) ?? pageAt(leftIdx.value))
const activeSubjectKey = computed(() => String(current.value?.subjectId ?? 0))
function fieldsOf(subjectKey: string) {
  return groups.value
    .filter((g) => String(g.subjectId ?? 0) === subjectKey)
    .map((g) => ({ key: g.key, field: g.field || '（分野なし）', first: pageIndexOf(pages.value, g.notes[0]!.id) }))
}
const fieldsOfActive = computed(() => fieldsOf(activeSubjectKey.value))
/** ≡ のメニュー用: 科目ごとの分野（全部） */
const index = computed(() => subjects.value.map((sb) => ({ ...sb, fields: fieldsOf(sb.key) })))
/** メニューから選んだページへ（メニューは閉じる） */
function pick(i: number) {
  go(i)
  idxOpen.value = false
}
/** 見開きに載っている分野（タブの強調用） */
const activeFieldKeys = computed(() => {
  const keys = new Set<string>()
  for (const i of [leftIdx.value, rightIdx.value]) for (const e of pageAt(i)?.entries ?? []) keys.add(groupKeyOf(e.note))
  return keys
})
const currentLabel = computed(() => {
  const c = current.value
  if (!c) return ''
  const fields = Array.from(new Set(c.entries.map((e) => e.note.field).filter(Boolean)))
  return fields.length ? `${c.subjectName} ／ ${fields.join('・')}` : c.subjectName
})

// ---- 操作 ----
function onKey(e: KeyboardEvent) {
  if (e.key === 'ArrowRight' || e.key === 'PageDown' || e.key === ' ') {
    e.preventDefault()
    next()
  } else if (e.key === 'ArrowLeft' || e.key === 'PageUp') {
    e.preventDefault()
    prev()
  } else if (e.key === 'Escape') {
    if (idxOpen.value) idxOpen.value = false
    else emit('close')
  }
}
let sw: { x: number; y: number; t: number } | null = null
function onPointerDown(e: PointerEvent) {
  sw = { x: e.clientX, y: e.clientY, t: e.timeStamp }
}
function onPointerUp(e: PointerEvent) {
  if (!sw) return
  const dx = e.clientX - sw.x
  const dy = e.clientY - sw.y
  const dt = e.timeStamp - sw.t
  sw = null
  if (Math.abs(dx) > 50 && Math.abs(dx) > Math.abs(dy) * 1.5 && dt < 800) {
    if (dx < 0) next()
    else prev()
    return
  }
  if (Math.abs(dx) < 8 && Math.abs(dy) < 8) {
    // 左右のタップでめくる
    const r = (e.currentTarget as HTMLElement).getBoundingClientRect()
    if (e.clientX < r.left + r.width * 0.3) prev()
    else if (e.clientX > r.left + r.width * 0.7) next()
  }
}

onMounted(() => {
  if (props.startId != null) {
    const i = pageIndexOf(pages.value, props.startId)
    if (i >= 0) go(i)
  }
  window.addEventListener('keydown', onKey)
  landscapeMq.addEventListener('change', onOrient)
  if (isTouch && root.value) unblockTap = blockDoubleTapZoom(root.value)
  // PC ではブラウザも全画面にする（対応していなければそのまま）
  try {
    if (!('ontouchstart' in window) && document.documentElement.requestFullscreen) document.documentElement.requestFullscreen().catch(() => {})
  } catch {
    // ignore
  }
})
onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKey)
  landscapeMq.removeEventListener('change', onOrient)
  unblockTap?.()
  try {
    if (document.fullscreenElement) document.exitFullscreen().catch(() => {})
  } catch {
    // ignore
  }
})

const ratio = PAGE_H / PAGE_W
</script>

<template>
  <div ref="root" class="binder ui-fullscreen" :class="{ immersive }">
    <!-- 横向き（iPad・スマホ）はバーを出さず、浮かせた × と ≡ だけにする -->
    <template v-if="immersive">
      <button class="fab x-fab" aria-label="閉じる" @click="emit('close')">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18" /></svg>
      </button>
      <button class="fab menu-fab" aria-label="インデックス" :aria-expanded="idxOpen" @click="idxOpen = true">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 7h16" /><path d="M4 12h16" /><path d="M4 17h16" /></svg>
      </button>
    </template>
    <div v-else class="bar">
      <div class="bar-title">
        間違いノート
        <span v-if="currentLabel" class="bar-sub">{{ currentLabel }}</span>
      </div>
      <div class="bar-pos dm">{{ Math.min(total, pos + 1) }} / {{ total }}</div>
      <button class="x" aria-label="閉じる" @click="emit('close')">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18" /></svg>
      </button>
    </div>

    <div class="stage" @pointerdown="onPointerDown" @pointerup="onPointerUp">
      <button class="nav l" :disabled="!canPrev" aria-label="前のページ" @click.stop="prev">
        <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M15 5l-7 7 7 7" /></svg>
      </button>

      <div class="spread" :class="{ single }" :style="{ '--ratio': ratio }">
        <div v-if="!single" class="page left" :class="{ blank: leftIdx < 0 }">
          <img v-if="pageUrl(leftIdx)" :src="pageUrl(leftIdx)" alt="" draggable="false" />
          <div v-else-if="leftIdx >= 0" class="loading">読み込み中…</div>
        </div>
        <div class="page right" :class="{ blank: rightIdx >= total }">
          <img v-if="pageUrl(rightIdx)" :src="pageUrl(rightIdx)" alt="" draggable="false" />
          <div v-else-if="rightIdx < total" class="loading">読み込み中…</div>
        </div>
        <div v-if="!single" class="spine"></div>
      </div>

      <!-- インデックス（右端のタブ。横向きのタッチ端末では ≡ のメニューに） -->
      <div v-if="!immersive" class="tabs" @pointerdown.stop @pointerup.stop>
        <template v-for="sb in subjects" :key="sb.key">
          <button class="tab subj" :class="{ on: sb.key === activeSubjectKey }" :style="{ '--c': sb.color ?? '#5b6b8c' }" @click="go(sb.first)">{{ sb.name }}</button>
          <template v-if="sb.key === activeSubjectKey">
            <button v-for="f in fieldsOfActive" :key="f.key" class="tab field" :class="{ on: activeFieldKeys.has(f.key) }" :style="{ '--c': sb.color ?? '#5b6b8c' }" @click="go(f.first)">{{ f.field }}</button>
          </template>
        </template>
      </div>

      <button class="nav r" :disabled="!canNext" aria-label="次のページ" @click.stop="next">
        <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M9 5l7 7-7 7" /></svg>
      </button>
    </div>

    <!-- インデックスのメニュー（横向き。≡ で右から出す。背景タップ・×・Esc で閉じる） -->
    <Transition name="idx" :duration="{ enter: 320, leave: 240 }">
      <div v-if="idxOpen" class="idx">
        <div class="idx-scrim" @click="idxOpen = false"></div>
        <div class="idx-panel">
          <div class="idx-head">
            <div class="idx-title">
              間違いノート
              <div v-if="currentLabel" class="idx-sub">{{ currentLabel }}</div>
            </div>
            <div class="idx-pos dm">{{ Math.min(total, pos + 1) }} / {{ total }}</div>
            <button class="x" aria-label="メニューを閉じる" @click="idxOpen = false">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18" /></svg>
            </button>
          </div>
          <div class="idx-list">
            <template v-for="sb in index" :key="sb.key">
              <button class="tab subj" :class="{ on: sb.key === activeSubjectKey }" :style="{ '--c': sb.color ?? '#5b6b8c' }" @click="pick(sb.first)">{{ sb.name }}</button>
              <button v-for="f in sb.fields" :key="f.key" class="tab field" :class="{ on: activeFieldKeys.has(f.key) }" :style="{ '--c': sb.color ?? '#5b6b8c' }" @click="pick(f.first)">{{ f.field }}</button>
            </template>
          </div>
          <button class="idx-close" @click="emit('close')">ノートを閉じる</button>
        </div>
      </div>
    </Transition>
  </div>
</template>

<style scoped>
.binder {
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
  gap: 14px;
  height: calc(50px + env(safe-area-inset-top));
  padding: env(safe-area-inset-top) 12px 0 18px;
  background: #1c2024;
  flex-shrink: 0;
}
.bar-title {
  flex: 1;
  min-width: 0;
  font-size: 14px;
  font-weight: 700;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.bar-sub {
  margin-left: 12px;
  font-size: 12.5px;
  font-weight: 500;
  color: #b7bcc5;
}
.bar-pos {
  font-size: 13px;
  color: #b7bcc5;
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
}
.stage {
  position: relative;
  flex: 1;
  min-height: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 16px 150px 16px 56px;
  touch-action: pan-y;
}
.nav {
  position: absolute;
  top: 50%;
  transform: translateY(-50%);
  width: 46px;
  height: 46px;
  border: none;
  border-radius: 50%;
  background: rgba(255, 255, 255, 0.12);
  color: #fff;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  z-index: 3;
}
.nav:disabled {
  opacity: 0.25;
  cursor: default;
}
.nav.l {
  left: 8px;
}
.nav.r {
  right: 100px;
}
.spread {
  position: relative;
  display: flex;
  /* 見開き 2 ページが枠に収まる大きさ（縦横どちらにも収める） */
  height: min(100%, calc((100vw - 220px) / 2 * var(--ratio)));
  aspect-ratio: calc(2 / var(--ratio));
  max-width: 100%;
}
.spread.single {
  height: min(100%, calc((100vw - 40px) * var(--ratio)));
  aspect-ratio: calc(1 / var(--ratio));
}
.page {
  position: relative;
  flex: 1;
  min-width: 0;
  background: #fff;
  overflow: hidden;
  box-shadow: 0 10px 40px rgba(0, 0, 0, 0.45);
}
.page.left {
  border-radius: 6px 0 0 6px;
}
.page.right {
  border-radius: 0 6px 6px 0;
}
.single .page.right {
  border-radius: 6px;
}
.page.blank {
  background: #3a3f47;
  box-shadow: none;
}
.page img {
  display: block;
  width: 100%;
  height: 100%;
  object-fit: contain;
  /* 長押しで「画像を保存」などのメニューが出ないように */
  -webkit-touch-callout: none;
  pointer-events: none;
}
.loading {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #9aa1ab;
  font-size: 13px;
}
.spine {
  position: absolute;
  left: 50%;
  top: 0;
  bottom: 0;
  width: 26px;
  margin-left: -13px;
  background: linear-gradient(90deg, rgba(0, 0, 0, 0) 0%, rgba(0, 0, 0, 0.22) 50%, rgba(0, 0, 0, 0) 100%);
  pointer-events: none;
}
.tabs {
  position: absolute;
  right: 10px;
  top: 50%;
  transform: translateY(-50%);
  width: 132px;
  max-height: calc(100% - 32px);
  overflow-y: auto;
  display: flex;
  flex-direction: column;
  gap: 4px;
  z-index: 3;
}
.tab {
  border: none;
  border-radius: 0 8px 8px 0;
  padding: 8px 10px;
  text-align: left;
  font-size: 12.5px;
  font-weight: 700;
  color: #fff;
  background: var(--c);
  opacity: 0.55;
  cursor: pointer;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  border-left: 4px solid rgba(255, 255, 255, 0.5);
}
.tab.on {
  opacity: 1;
  border-left-color: #fff;
}
.tab.field {
  margin-left: 14px;
  padding: 6px 10px;
  font-size: 11.5px;
  font-weight: 600;
  background: #fff;
  color: var(--c);
  opacity: 0.75;
  border-left-color: var(--c);
}
.tab.field.on {
  opacity: 1;
}

/* ---- 横向き（iPad・スマホ）: バーとタブを出さずノートを大きく。× と ≡ を浮かせる ---- */
.fab {
  position: absolute;
  top: calc(8px + env(safe-area-inset-top));
  width: 40px;
  height: 40px;
  border: none;
  border-radius: 50%;
  background: rgba(28, 32, 36, 0.78);
  color: #fff;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  box-shadow: 0 2px 10px rgba(0, 0, 0, 0.35);
  z-index: 5;
}
.x-fab {
  left: calc(8px + env(safe-area-inset-left));
}
.menu-fab {
  right: calc(8px + env(safe-area-inset-right));
}
.immersive .stage {
  padding: calc(8px + env(safe-area-inset-top)) 56px calc(8px + env(safe-area-inset-bottom));
}
.immersive .nav.r {
  right: 8px;
}
.immersive .spread {
  height: min(100%, calc((100vw - 120px) / 2 * var(--ratio)));
}
.idx {
  position: absolute;
  inset: 0;
  z-index: 10;
}
.idx-scrim {
  position: absolute;
  inset: 0;
  background: rgba(0, 0, 0, 0.45);
}
.idx-panel {
  position: absolute;
  top: 0;
  right: 0;
  bottom: 0;
  width: min(80vw, 300px);
  display: flex;
  flex-direction: column;
  background: #1c2024;
  box-shadow: -12px 0 40px rgba(0, 0, 0, 0.4);
  padding: env(safe-area-inset-top) env(safe-area-inset-right) env(safe-area-inset-bottom) 0;
  overscroll-behavior: contain;
}
.idx-head {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 14px 12px 12px 16px;
  border-bottom: 1px solid rgba(255, 255, 255, 0.1);
}
.idx-title {
  flex: 1;
  min-width: 0;
  font-size: 14px;
  font-weight: 700;
}
.idx-sub {
  margin-top: 2px;
  font-size: 12px;
  font-weight: 500;
  color: #b7bcc5;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.idx-pos {
  font-size: 12.5px;
  color: #b7bcc5;
}
.idx-list {
  flex: 1;
  min-height: 0;
  overflow-y: auto;
  overscroll-behavior: contain;
  padding: 10px 12px;
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.idx-list .tab {
  border-radius: 8px;
  font-size: 13.5px;
  padding: 10px 12px;
}
.idx-list .tab.field {
  font-size: 12.5px;
  padding: 8px 12px;
}
.idx-close {
  margin: 8px 12px 10px;
  padding: 11px;
  border: 1px solid rgba(255, 255, 255, 0.2);
  border-radius: 10px;
  background: transparent;
  color: #fff;
  font-size: 13.5px;
  font-weight: 600;
  cursor: pointer;
}
/* 開閉: 背景はフェード、メニューは右からスライド */
.idx-enter-active .idx-scrim,
.idx-leave-active .idx-scrim {
  transition: opacity 0.24s ease;
}
.idx-enter-from .idx-scrim,
.idx-leave-to .idx-scrim {
  opacity: 0;
}
.idx-enter-active .idx-panel {
  transition: transform 0.32s cubic-bezier(0.2, 0.9, 0.25, 1);
}
.idx-leave-active .idx-panel {
  transition: transform 0.22s cubic-bezier(0.4, 0, 1, 1);
}
.idx-enter-from .idx-panel,
.idx-leave-to .idx-panel {
  transform: translateX(100%);
}
@media (max-width: 760px) {
  .stage {
    padding: 10px 12px 96px;
  }
  .nav.l {
    left: 6px;
    bottom: 24px;
    top: auto;
    transform: none;
  }
  .nav.r {
    right: 6px;
    bottom: 24px;
    top: auto;
    transform: none;
  }
  .tabs {
    top: auto;
    bottom: 10px;
    left: 64px;
    right: 64px;
    width: auto;
    transform: none;
    flex-direction: row;
    flex-wrap: wrap;
    gap: 4px;
    max-height: 70px;
  }
  .tab,
  .tab.field {
    border-radius: 8px;
    margin-left: 0;
    padding: 5px 9px;
    font-size: 11px;
  }
}
</style>
