<script setup lang="ts">
/**
 * 本のように読む PDF ビューア（ファイル共有の閲覧用）。全画面。
 * - ページは pdf.js で画像にして 1 ページずつ表示し、次へ／前へで紙をめくるアニメーション（左端を軸に 3D 回転）
 * - 見開き表示に切り替えられる。組み方は 2 つ（記憶。既定はハードカバー）:
 *     ハードカバー: 1 ページ目（表紙）は単独、2 ページ目も右だけ（左は見返し）、以降は 左=奇数・右=偶数（スキャンした本の並び）
 *     通常: 左=偶数・右=奇数（1 ページ目は右だけ）
 *   見開きのめくりは中央の綴じ目を軸に表裏 2 面の紙（表=今の右ページ・裏=次の左ページ）を回す
 * - PDF のブックマーク（アウトライン）を左の目次に出し、タップでそのページへ
 * - 画面の右半分タップ・→ キー・左スワイプで次へ、左半分・← キー・右スワイプで前へ。ページ番号の直接入力、拡大縮小、保存
 * 大きい PDF は Range リクエストで必要な部分だけ読む（サーバーは Accept-Ranges を返す）。
 */
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import type { PDFDocumentProxy } from 'pdfjs-dist'
import { PdfOutlineItem, type PdfOutlineNode } from '@/components/PdfOutlineItem'
import { saveFile } from '@/lib/native'

const props = defineProps<{
  /** 認証付き URL（Bearer を付けて取得する） */
  url: string
  title: string
  downloadName?: string
  /** 保存用に Blob を取得する（省略時は保存ボタンを出さない） */
  fetchBlob?: () => Promise<Blob>
  /** 最初に開くページ */
  startPage?: number
}>()
const emit = defineEmits<{ close: [] }>()

let doc: PDFDocumentProxy | null = null
const loading = ref(true)
const error = ref('')
const numPages = ref(0)
const page = ref(1)
const ratio = ref(1.414) // 高さ / 幅
/** ページ番号 → 画像（object URL） */
const imgs = reactive(new Map<number, string>())
const rendering = new Map<number, Promise<void>>()
const outline = ref<PdfOutlineNode[]>([])
const outlineOpen = ref(false)
const zoom = ref(1)
const flip = ref<{ dir: 'next' | 'prev'; page: number } | null>(null)
/** 見開き表示か（記憶する） */
const spread = ref(localStorage.getItem('sm_pdf_spread') === '1')
/** 見開きのめくり中: 下に見せる左右と、回る紙の表（front）・裏（back） */
const sflip = ref<{ dir: 'next' | 'prev'; baseLeft: number; baseRight: number; front: number; back: number } | null>(null)
/** 見開きの組み方: hard = ハードカバー（表紙は単独・以降 左=奇数）/ normal = 左=偶数・右=奇数 */
type SpreadKind = 'hard' | 'normal'
const spreadKind = ref<SpreadKind>(localStorage.getItem('sm_pdf_spread_kind') === 'normal' ? 'normal' : 'hard')
/** ページ n を含む見開き {l, r}。l=0 は空白（表紙の左・見返し）。r は常にそのページ自身か右隣（見開きの識別にも使う） */
function spreadOf(n: number): { l: number; r: number } {
  if (spreadKind.value === 'hard') {
    if (n <= 2) return { l: 0, r: n } // 表紙は単独、2 ページ目は左が見返し
    const l = n % 2 === 1 ? n : n - 1
    return { l, r: l + 1 }
  }
  const l = n % 2 === 0 ? n : n - 1
  return { l, r: l + 1 }
}
/** 次の見開き（無ければ null） */
function nextSpread(sp: { l: number; r: number }): { l: number; r: number } | null {
  return sp.r + 1 <= numPages.value ? spreadOf(sp.r + 1) : null
}
/** 前の見開き（無ければ null） */
function prevSpread(sp: { l: number; r: number }): { l: number; r: number } | null {
  const n = sp.l >= 1 ? sp.l - 1 : sp.r - 1
  return n >= 1 ? spreadOf(n) : null
}
/** 今見せている見開きの左右（めくり中は下の紙） */
const pair = computed(() => {
  if (sflip.value) return { l: sflip.value.baseLeft, r: sflip.value.baseRight }
  return spreadOf(page.value)
})
const canNext = computed(() => (spread.value ? nextSpread(spreadOf(page.value)) !== null : page.value < numPages.value))
const canPrev = computed(() => (spread.value ? prevSpread(spreadOf(page.value)) !== null : page.value > 1))
function inRange(n: number): boolean {
  return n >= 1 && n <= numPages.value
}
const pageInput = ref('')
const stage = ref<HTMLElement | null>(null)
const stageSize = reactive({ w: 0, h: 0 })
let ro: ResizeObserver | null = null
let seq = 0

const hasOutline = computed(() => outline.value.length > 0)
const reduceMotion = typeof window !== 'undefined' && window.matchMedia('(prefers-reduced-motion: reduce)').matches

/** ページ（紙）の表示サイズ: 画面に収まる大きさ × 拡大率 */
const paperStyle = computed(() => {
  const pad = 16
  const w0 = Math.max(100, stageSize.w - pad * 2)
  const h0 = Math.max(100, stageSize.h - pad * 2)
  const cols = spread.value ? 2 : 1
  let w = w0 / cols
  let h = w * ratio.value
  if (h > h0) {
    h = h0
    w = h / ratio.value
  }
  return { width: `${Math.round(w * cols * zoom.value)}px`, height: `${Math.round(h * zoom.value)}px` }
})

onMounted(async () => {
  window.addEventListener('keydown', onKey)
  if (stage.value) {
    ro = new ResizeObserver(() => {
      if (!stage.value) return
      stageSize.w = stage.value.clientWidth
      stageSize.h = stage.value.clientHeight
    })
    ro.observe(stage.value)
    stageSize.w = stage.value.clientWidth
    stageSize.h = stage.value.clientHeight
  }
  await load()
})
onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKey)
  ro?.disconnect()
  cleanup()
})

async function load() {
  loading.value = true
  error.value = ''
  const my = ++seq
  try {
    const { pdfjsLib } = await import('@/lib/pdf')
    const d = await pdfjsLib.getDocument({
      url: props.url,
      httpHeaders: { Authorization: `Bearer ${localStorage.getItem('sm_token') ?? ''}` },
      rangeChunkSize: 1024 * 1024,
    }).promise
    if (my !== seq) {
      d.destroy().catch(() => {})
      return
    }
    doc = d
    numPages.value = d.numPages
    const first = await d.getPage(1)
    const vp = first.getViewport({ scale: 1 })
    ratio.value = vp.height / vp.width
    first.cleanup()
    page.value = Math.min(Math.max(1, props.startPage ?? 1), d.numPages)
    loading.value = false
    await ensure(page.value)
    prefetch()
    loadOutline(d).catch(() => {})
  } catch (e) {
    error.value = `PDF を表示できませんでした（${(e as Error)?.message ?? e}）`
    loading.value = false
  }
}

function cleanup() {
  for (const u of imgs.values()) URL.revokeObjectURL(u)
  imgs.clear()
  rendering.clear()
  doc?.destroy().catch(() => {})
  doc = null
}

/** 描画する幅（CSS px × 端末の倍率。上限あり） */
function renderWidth(): number {
  const cssW = Math.min(1200, Math.max(320, ((stageSize.w || 800) / (spread.value ? 2 : 1)) * zoom.value))
  return Math.min(2200, Math.round(cssW * Math.min(2, window.devicePixelRatio || 1)))
}

/** ページを画像にして用意する（済みなら何もしない） */
function ensure(n: number): Promise<void> {
  if (!doc || n < 1 || n > numPages.value || imgs.has(n)) return Promise.resolve()
  let t = rendering.get(n)
  if (t) return t
  const d = doc
  t = (async () => {
    try {
      const p = await d.getPage(n)
      const base = p.getViewport({ scale: 1 })
      const vp = p.getViewport({ scale: renderWidth() / base.width })
      const c = document.createElement('canvas')
      c.width = Math.ceil(vp.width)
      c.height = Math.ceil(vp.height)
      await p.render({ canvasContext: c.getContext('2d')!, viewport: vp }).promise
      const b = await new Promise<Blob | null>((r) => c.toBlob(r, 'image/jpeg', 0.88))
      c.width = 0
      c.height = 0
      p.cleanup()
      if (b && doc === d) imgs.set(n, URL.createObjectURL(b))
      trim()
    } catch {
      // このページは描けなかった
    } finally {
      rendering.delete(n)
    }
  })()
  rendering.set(n, t)
  return t
}

/** 現在のページから遠い画像は捨てる（メモリ節約） */
function trim() {
  const keep = 10
  if (imgs.size <= keep) return
  const far = [...imgs.keys()].sort((a, b) => Math.abs(b - page.value) - Math.abs(a - page.value))
  for (const n of far.slice(0, imgs.size - keep)) {
    if (Math.abs(n - page.value) <= 2) continue
    URL.revokeObjectURL(imgs.get(n)!)
    imgs.delete(n)
  }
}
function prefetch() {
  if (spread.value) {
    const sp = spreadOf(page.value)
    const nx = nextSpread(sp)
    const pv = prevSpread(sp)
    const order = [sp.l, sp.r, nx?.l, nx?.r, pv?.l, pv?.r].filter((n): n is number => typeof n === 'number' && n >= 1)
    order.reduce((t, n) => t.then(() => ensure(n)), Promise.resolve()).catch(() => {})
    return
  }
  ensure(page.value + 1).then(() => ensure(page.value - 1)).then(() => ensure(page.value + 2)).catch(() => {})
}

/** ブックマーク（アウトライン）を読み、行き先のページ番号に直す */
async function loadOutline(d: PDFDocumentProxy) {
  const raw = await d.getOutline()
  if (!raw?.length) return
  let count = 0
  const conv = async (items: typeof raw, depth: number): Promise<PdfOutlineNode[]> => {
    const out: PdfOutlineNode[] = []
    for (const it of items) {
      if (++count > 1500) break
      let pageNo: number | null = null
      try {
        const dest = typeof it.dest === 'string' ? await d.getDestination(it.dest) : it.dest
        const ref = dest?.[0]
        if (ref && typeof ref === 'object') pageNo = (await d.getPageIndex(ref)) + 1
        else if (typeof ref === 'number') pageNo = ref + 1
      } catch {
        pageNo = null
      }
      out.push({ title: (it.title ?? '').trim() || '（無題）', page: pageNo, items: it.items?.length ? await conv(it.items, depth + 1) : [], open: depth < 1 })
    }
    return out
  }
  outline.value = await conv(raw, 0)
  // 広い画面ではブックマークがあれば最初から目次を開く
  if (window.innerWidth >= 900) outlineOpen.value = true
}

// ---- ページ移動（めくりアニメーション） ----
let busy = false
async function go(n: number) {
  if (!doc || busy || n < 1 || n > numPages.value || n === page.value) return
  if (spread.value) return goSpread(n)
  busy = true
  try {
    await ensure(n)
    const dir: 'next' | 'prev' = n > page.value ? 'next' : 'prev'
    if (reduceMotion || Math.abs(n - page.value) > 1) {
      // 目次でのジャンプなどはめくらず切り替える（遠いページを 1 枚ずつめくらない）
      page.value = n
      flip.value = null
      busy = false
      prefetch()
      return
    }
    if (dir === 'next') {
      // 下に次のページを出し、今のページを左へめくって捲り取る
      flip.value = { dir, page: page.value }
      page.value = n
    } else {
      // 前のページが左からめくれて戻ってくる。終わったら下のページを入れ替える
      flip.value = { dir, page: n }
    }
    // アニメーションが終わらなかったときの保険
    window.setTimeout(() => flip.value && endFlip(), 900)
  } catch {
    busy = false
  }
}
function endFlip() {
  const f = flip.value
  if (!f) return
  if (f.dir === 'prev') page.value = f.page
  flip.value = null
  busy = false
  prefetch()
}
/** 見開きでの移動。同じ見開きの中なら切り替えだけ、隣の見開きなら綴じ目を軸にめくる、遠ければ切り替え */
async function goSpread(n: number) {
  const from = spreadOf(page.value)
  const to = spreadOf(n)
  if (to.r === from.r) {
    page.value = n
    return
  }
  busy = true
  try {
    await Promise.all([ensure(to.l), ensure(to.r)])
    const nx = nextSpread(from)
    const pv = prevSpread(from)
    const adjacentNext = nx !== null && nx.r === to.r
    const adjacentPrev = pv !== null && pv.r === to.r
    if (reduceMotion || (!adjacentNext && !adjacentPrev)) {
      page.value = n
      sflip.value = null
      busy = false
      prefetch()
      return
    }
    if (adjacentNext) {
      // 右ページ（表）が左へ倒れ、裏に次の左ページが現れる。下は 左=今の左・右=次の右
      sflip.value = { dir: 'next', baseLeft: from.l, baseRight: to.r, front: from.r, back: to.l }
    } else {
      // 左に伏せていた紙（裏=今の左ページ）が起き上がって右に戻る（表=前の右ページ）。下は 左=前の左・右=今の右
      sflip.value = { dir: 'prev', baseLeft: to.l, baseRight: from.r, front: to.r, back: from.l }
    }
    page.value = n
    window.setTimeout(() => sflip.value && endSpreadFlip(), 900)
  } catch {
    busy = false
  }
}
function endSpreadFlip() {
  if (!sflip.value) return
  sflip.value = null
  busy = false
  prefetch()
}
function next() {
  if (spread.value) {
    const nx = nextSpread(spreadOf(page.value))
    if (nx) go(nx.l >= 1 ? nx.l : nx.r)
    return
  }
  go(page.value + 1)
}
function prev() {
  if (spread.value) {
    const pv = prevSpread(spreadOf(page.value))
    if (pv) go(pv.r <= numPages.value ? pv.r : pv.l)
    return
  }
  go(page.value - 1)
}
/** 今の見開き（または 1 ページ）を描き直す（解像度や組み方が変わったとき） */
function rerender() {
  flip.value = null
  sflip.value = null
  for (const [n, u] of imgs) {
    URL.revokeObjectURL(u)
    imgs.delete(n)
  }
  const sp = spreadOf(page.value)
  Promise.all(spread.value ? [ensure(sp.l), ensure(sp.r)] : [ensure(page.value)]).then(prefetch)
}
/** 1 ページ／見開きの切り替え（記憶する） */
function toggleSpread() {
  if (busy) return
  spread.value = !spread.value
  localStorage.setItem('sm_pdf_spread', spread.value ? '1' : '0')
  rerender()
}
/** 見開きの組み方（ハードカバー／通常）の切り替え（記憶する） */
function setSpreadKind(k: SpreadKind) {
  if (busy || spreadKind.value === k) return
  spreadKind.value = k
  localStorage.setItem('sm_pdf_spread_kind', k)
  flip.value = null
  sflip.value = null
  prefetch()
}
function onStageClick(e: MouseEvent) {
  if (zoom.value > 1) return
  const r = (e.currentTarget as HTMLElement).getBoundingClientRect()
  if (e.clientX - r.left > r.width / 2) next()
  else prev()
}
function jump() {
  const n = Number(pageInput.value)
  pageInput.value = ''
  if (Number.isInteger(n)) go(Math.min(Math.max(1, n), numPages.value))
}
function onKey(e: KeyboardEvent) {
  const t = e.target as HTMLElement | null
  if (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA')) return
  if (e.key === 'ArrowRight' || e.key === 'PageDown' || e.key === ' ') {
    e.preventDefault()
    next()
  } else if (e.key === 'ArrowLeft' || e.key === 'PageUp') {
    e.preventDefault()
    prev()
  } else if (e.key === 'Escape') emit('close')
}

// ---- スワイプ ----
let sw: { x: number; y: number; t: number } | null = null
function onTouchStart(e: TouchEvent) {
  if (e.touches.length !== 1 || zoom.value > 1) {
    sw = null
    return
  }
  sw = { x: e.touches[0]!.clientX, y: e.touches[0]!.clientY, t: e.timeStamp }
}
function onTouchEnd(e: TouchEvent) {
  const s = sw
  sw = null
  if (!s) return
  const t = e.changedTouches[0]
  if (!t) return
  const dx = t.clientX - s.x
  const dy = t.clientY - s.y
  if (Math.abs(dx) > 48 && Math.abs(dx) > Math.abs(dy) * 1.3 && e.timeStamp - s.t < 800) {
    if (dx < 0) next()
    else prev()
  }
}

function zoomBy(f: number) {
  zoom.value = Math.min(3, Math.max(1, Math.round(zoom.value * f * 100) / 100))
  if (zoom.value === 1) nextTick(() => stage.value?.scrollTo({ top: 0, left: 0 }))
}
/** 拡大率を変えたら、今のページを新しい解像度で描き直す */
watch(zoom, rerender)

const downloading = ref(false)
async function download() {
  if (!props.fetchBlob || downloading.value) return
  downloading.value = true
  try {
    await saveFile(await props.fetchBlob(), props.downloadName ?? `${props.title}.pdf`)
  } finally {
    downloading.value = false
  }
}
</script>

<template>
  <div class="bv ui-fullscreen">
    <div class="bv-top">
      <button class="bv-btn" @click="emit('close')">閉じる</button>
      <div class="bv-title" :title="title">{{ title }}</div>
      <div class="bv-tools">
        <button class="bv-icon" :class="{ on: outlineOpen }" :disabled="!hasOutline" :title="hasOutline ? 'ブックマーク（目次）' : 'この PDF にブックマークはありません'" @click="outlineOpen = !outlineOpen">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 6h16" /><path d="M4 12h16" /><path d="M4 18h10" /></svg>
        </button>
        <button class="bv-icon" :class="{ on: spread }" :title="spread ? '1 ページ表示にする' : '見開き表示にする'" :aria-label="spread ? '1 ページ表示' : '見開き表示'" @click="toggleSpread">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 5h8v14H3z" /><path d="M13 5h8v14h-8z" /></svg>
        </button>
        <div v-if="spread" class="bv-kind" role="radiogroup" aria-label="見開きの組み方">
          <button :class="{ on: spreadKind === 'hard' }" role="radio" :aria-checked="spreadKind === 'hard'" title="表紙を 1 枚目として単独、以降は左が奇数ページ" @click="setSpreadKind('hard')">ハードカバー</button>
          <button :class="{ on: spreadKind === 'normal' }" role="radio" :aria-checked="spreadKind === 'normal'" title="左が偶数・右が奇数ページ" @click="setSpreadKind('normal')">通常</button>
        </div>
        <button class="bv-icon" title="縮小" :disabled="zoom <= 1" @click="zoomBy(1 / 1.25)">−</button>
        <span class="bv-zoom">{{ Math.round(zoom * 100) }}%</span>
        <button class="bv-icon" title="拡大" :disabled="zoom >= 3" @click="zoomBy(1.25)">＋</button>
        <button v-if="fetchBlob" class="bv-icon" title="保存・共有" :disabled="downloading" @click="download">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12" /><path d="M7 8l5-5 5 5" /><path d="M5 12v7a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-7" /></svg>
        </button>
      </div>
    </div>

    <div class="bv-body">
      <!-- 目次（ブックマーク） -->
      <Transition name="bv-side">
        <aside v-if="outlineOpen && hasOutline" class="bv-outline no-gesture">
          <div class="ol-head">ブックマーク</div>
          <div class="ol-list">
            <template v-for="(n, i) in outline" :key="i">
              <PdfOutlineItem :node="n" :depth="0" :current="page" @go="go" />
            </template>
          </div>
        </aside>
      </Transition>
      <div v-if="outlineOpen && hasOutline" class="bv-scrim" @click="outlineOpen = false"></div>

      <!-- 紙面 -->
      <div ref="stage" class="bv-stage no-gesture" :class="{ zoomed: zoom > 1 }" @click="onStageClick" @touchstart.passive="onTouchStart" @touchend.passive="onTouchEnd">
        <div v-if="loading" class="bv-msg">読み込み中…</div>
        <div v-else-if="error" class="bv-msg">{{ error }}</div>
        <div v-else-if="spread" class="book spread" :style="paperStyle">
          <div class="paper half left" :class="{ blank: !inRange(pair.l) }">
            <img v-if="imgs.get(pair.l)" :src="imgs.get(pair.l)" :alt="`${pair.l}ページ`" draggable="false" />
            <div v-else-if="inRange(pair.l)" class="paper-loading">…</div>
          </div>
          <div class="paper half right" :class="{ blank: !inRange(pair.r) }">
            <img v-if="imgs.get(pair.r)" :src="imgs.get(pair.r)" :alt="`${pair.r}ページ`" draggable="false" />
            <div v-else-if="inRange(pair.r)" class="paper-loading">…</div>
          </div>
          <div v-if="sflip" class="leaf" :class="sflip.dir" @animationend="endSpreadFlip">
            <div class="face front" :class="{ blank: !inRange(sflip.front) }">
              <img v-if="imgs.get(sflip.front)" :src="imgs.get(sflip.front)" :alt="`${sflip.front}ページ`" draggable="false" />
              <div class="shade"></div>
            </div>
            <div class="face back" :class="{ blank: !inRange(sflip.back) }">
              <img v-if="imgs.get(sflip.back)" :src="imgs.get(sflip.back)" :alt="`${sflip.back}ページ`" draggable="false" />
              <div class="shade rev"></div>
            </div>
          </div>
          <div class="gutter"></div>
        </div>
        <div v-else class="book" :style="paperStyle">
          <div class="paper base">
            <img v-if="imgs.get(page)" :src="imgs.get(page)" :alt="`${page}ページ`" draggable="false" />
            <div v-else class="paper-loading">…</div>
          </div>
          <div v-if="flip" class="paper flip" :class="flip.dir" @animationend="endFlip">
            <img v-if="imgs.get(flip.page)" :src="imgs.get(flip.page)" :alt="`${flip.page}ページ`" draggable="false" />
            <div class="shade"></div>
          </div>
          <div class="spine"></div>
        </div>
        <button v-if="!loading && !error" class="edge left" :disabled="!canPrev" aria-label="前のページ" @click.stop="prev">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 5l-7 7 7 7" /></svg>
        </button>
        <button v-if="!loading && !error" class="edge right" :disabled="!canNext" aria-label="次のページ" @click.stop="next">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 5l7 7-7 7" /></svg>
        </button>
      </div>
    </div>

    <div class="bv-foot">
      <button class="bv-btn" :disabled="!canPrev" @click="prev">‹ 前へ</button>
      <form class="bv-page" @submit.prevent="jump">
        <input v-model="pageInput" type="number" inputmode="numeric" min="1" :max="numPages" :placeholder="String(page)" aria-label="ページ番号" />
        <span>/ {{ numPages }}</span>
      </form>
      <input class="bv-slider" type="range" min="1" :max="Math.max(1, numPages)" :value="page" aria-label="ページ" @change="go(Number(($event.target as HTMLInputElement).value))" />
      <button class="bv-btn" :disabled="!canNext" @click="next">次へ ›</button>
    </div>
  </div>
</template>

<style scoped>
.bv {
  position: fixed;
  inset: 0;
  z-index: 2600;
  display: flex;
  flex-direction: column;
  background: #2a2d33;
  color: #fff;
  padding: 0 env(safe-area-inset-right) 0 env(safe-area-inset-left);
  -webkit-user-select: none;
  user-select: none;
}
.bv-top {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: max(8px, env(safe-area-inset-top)) 10px 8px;
  background: #1c1f24;
  flex-shrink: 0;
}
.bv-title {
  flex: 1;
  min-width: 0;
  text-align: center;
  font-size: 14px;
  font-weight: 700;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.bv-tools {
  display: flex;
  align-items: center;
  gap: 2px;
}
.bv-btn {
  min-height: 36px;
  padding: 0 12px;
  border: none;
  border-radius: 9px;
  background: transparent;
  color: #cfe0ff;
  font-size: 14px;
  font-weight: 600;
  cursor: pointer;
  white-space: nowrap;
}
.bv-btn:disabled {
  opacity: 0.35;
}
.bv-icon {
  width: 36px;
  height: 36px;
  border: none;
  border-radius: 9px;
  background: transparent;
  color: #e6e8ec;
  font-size: 18px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
}
.bv-icon.on {
  background: #3b50cc;
}
.bv-icon:disabled {
  opacity: 0.3;
}
.bv-kind {
  display: inline-flex;
  margin: 0 4px;
  border: 1px solid #3a3f47;
  border-radius: 8px;
  overflow: hidden;
}
.bv-kind button {
  min-height: 28px;
  padding: 0 9px;
  border: none;
  background: transparent;
  color: #b6bdc7;
  font-size: 11px;
  font-weight: 700;
  cursor: pointer;
  white-space: nowrap;
}
.bv-kind button.on {
  background: #3b50cc;
  color: #fff;
}
.bv-zoom {
  font-size: 11.5px;
  color: #b6bdc7;
  min-width: 38px;
  text-align: center;
}
.bv-body {
  flex: 1;
  min-height: 0;
  display: flex;
  position: relative;
}
.bv-outline {
  width: 280px;
  flex-shrink: 0;
  background: #f6f7f9;
  color: #1c2024;
  display: flex;
  flex-direction: column;
  border-right: 1px solid #d8dce1;
  z-index: 2;
}
.ol-head {
  padding: 10px 12px;
  font-size: 12px;
  font-weight: 700;
  color: var(--mut);
  border-bottom: 1px solid #e3e6ea;
}
.ol-list {
  flex: 1;
  overflow-y: auto;
  padding: 4px 0 20px;
  font-size: 13px;
}
.ol-list :deep(.ol-row) {
  display: flex;
  align-items: flex-start;
  gap: 2px;
  padding-right: 6px;
}
.ol-list :deep(.ol-row.cur) {
  background: #e8eefb;
}
.ol-list :deep(.ol-tgl) {
  width: 22px;
  height: 30px;
  border: none;
  background: transparent;
  color: #9aa1ab;
  cursor: pointer;
  flex-shrink: 0;
  transition: transform 0.15s;
}
.ol-list :deep(.ol-tgl.open) {
  transform: rotate(90deg);
}
.ol-list :deep(.ol-sp) {
  width: 22px;
  flex-shrink: 0;
}
.ol-list :deep(.ol-title) {
  flex: 1;
  min-width: 0;
  display: flex;
  align-items: flex-start;
  gap: 8px;
  padding: 7px 4px;
  border: none;
  background: transparent;
  color: #1c2024;
  text-align: left;
  font-size: 12.5px;
  line-height: 1.35;
  cursor: pointer;
  font-family: inherit;
}
.ol-list :deep(.ol-title:disabled) {
  color: #9aa1ab;
  cursor: default;
}
.ol-list :deep(.ol-t) {
  flex: 1;
  min-width: 0;
  word-break: break-all;
}
.ol-list :deep(.ol-p) {
  color: #9aa1ab;
  font-size: 11px;
  flex-shrink: 0;
  padding-top: 1px;
}
.bv-scrim {
  display: none;
}
.bv-stage {
  flex: 1;
  min-width: 0;
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
  overflow: hidden;
  perspective: 2600px;
  cursor: pointer;
  touch-action: pan-y;
}
.bv-stage.zoomed {
  overflow: auto;
  align-items: flex-start;
  justify-content: flex-start;
  cursor: default;
  touch-action: auto;
}
.bv-stage.zoomed .book {
  margin: 16px auto;
}
.book {
  position: relative;
  flex-shrink: 0;
  transform-style: preserve-3d;
}
.paper {
  position: absolute;
  inset: 0;
  background: #fff;
  box-shadow: 0 10px 30px rgba(0, 0, 0, 0.45);
  overflow: hidden;
}
.paper img {
  display: block;
  width: 100%;
  height: 100%;
  object-fit: contain;
  pointer-events: none;
}
.paper-loading {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #9aa1ab;
  font-size: 24px;
}
/* 見開き: 左右 2 枚。空白（1 ページ目の左・最後の右）は少し暗い紙 */
.book.spread .paper.half {
  top: 0;
  bottom: 0;
  width: 50%;
}
/* .paper の inset:0 を左右で上書き（同じ詳細度以上で left/right を明示する） */
.book.spread .paper.half.left {
  left: 0;
  right: auto;
}
.book.spread .paper.half.right {
  left: auto;
  right: 0;
}
.paper.blank,
.face.blank {
  background: #e9ebef;
  box-shadow: none;
}
/* 見開きのめくり: 右半分に置いた表裏 2 面の紙を、綴じ目（左端）を軸に回す */
.leaf {
  position: absolute;
  top: 0;
  bottom: 0;
  left: 50%;
  width: 50%;
  transform-origin: left center;
  transform-style: preserve-3d;
  z-index: 3;
  will-change: transform;
}
.leaf .face {
  position: absolute;
  inset: 0;
  background: #fff;
  overflow: hidden;
  backface-visibility: hidden;
  -webkit-backface-visibility: hidden;
  box-shadow: 0 10px 30px rgba(0, 0, 0, 0.45);
}
.leaf .face img {
  display: block;
  width: 100%;
  height: 100%;
  object-fit: contain;
  pointer-events: none;
}
.leaf .face.back {
  transform: rotateY(180deg);
}
.leaf.next {
  animation: bvFlipOut 0.7s cubic-bezier(0.45, 0.05, 0.3, 1) forwards;
}
.leaf.prev {
  animation: bvFlipIn 0.7s cubic-bezier(0.45, 0.05, 0.3, 1) forwards;
}
.leaf .shade {
  position: absolute;
  inset: 0;
  background: linear-gradient(to right, rgba(0, 0, 0, 0.22), rgba(0, 0, 0, 0.02));
  animation: bvShade 0.7s ease forwards;
  pointer-events: none;
}
.leaf .shade.rev {
  background: linear-gradient(to left, rgba(0, 0, 0, 0.22), rgba(0, 0, 0, 0.02));
}
.gutter {
  position: absolute;
  left: 50%;
  top: 0;
  bottom: 0;
  width: 24px;
  transform: translateX(-50%);
  background: linear-gradient(to right, rgba(0, 0, 0, 0), rgba(0, 0, 0, 0.16) 50%, rgba(0, 0, 0, 0));
  pointer-events: none;
  z-index: 2;
}
/* めくる紙: 左端を軸に回る。裏面は見せない（90° を越えると消える／現れる） */
.paper.flip {
  transform-origin: left center;
  backface-visibility: hidden;
  -webkit-backface-visibility: hidden;
  z-index: 3;
  will-change: transform;
}
.paper.flip.next {
  animation: bvFlipOut 0.62s cubic-bezier(0.45, 0.05, 0.3, 1) forwards;
}
.paper.flip.prev {
  animation: bvFlipIn 0.62s cubic-bezier(0.45, 0.05, 0.3, 1) forwards;
}
.paper.flip .shade {
  position: absolute;
  inset: 0;
  background: linear-gradient(to right, rgba(0, 0, 0, 0.02), rgba(0, 0, 0, 0.22));
  animation: bvShade 0.62s ease forwards;
  pointer-events: none;
}
.spine {
  position: absolute;
  left: 0;
  top: 0;
  bottom: 0;
  width: 10px;
  background: linear-gradient(to right, rgba(0, 0, 0, 0.18), rgba(0, 0, 0, 0));
  pointer-events: none;
  z-index: 2;
}
@keyframes bvFlipOut {
  from {
    transform: rotateY(0deg);
  }
  to {
    transform: rotateY(-180deg);
  }
}
@keyframes bvFlipIn {
  from {
    transform: rotateY(-180deg);
  }
  to {
    transform: rotateY(0deg);
  }
}
@keyframes bvShade {
  from {
    opacity: 0;
  }
  40% {
    opacity: 1;
  }
  to {
    opacity: 0;
  }
}
.edge {
  position: absolute;
  top: 50%;
  transform: translateY(-50%);
  width: 44px;
  height: 64px;
  border: none;
  border-radius: 12px;
  background: rgba(0, 0, 0, 0.35);
  color: #fff;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  opacity: 0;
  transition: opacity 0.2s;
}
.edge.left {
  left: 8px;
}
.edge.right {
  right: 8px;
}
.bv-stage:hover .edge:not(:disabled) {
  opacity: 0.9;
}
.edge:disabled {
  opacity: 0 !important;
}
.bv-msg {
  color: #b6bdc7;
  font-size: 13px;
  padding: 20px;
  text-align: center;
}
.bv-foot {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px 12px calc(8px + env(safe-area-inset-bottom));
  background: #1c1f24;
  flex-shrink: 0;
}
.bv-page {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 13px;
  color: #b6bdc7;
}
.bv-page input {
  width: 58px;
  padding: 5px 6px;
  border: 1px solid #3a3f47;
  border-radius: 7px;
  background: #2a2d33;
  color: #fff;
  font-size: 13px;
  text-align: center;
  outline: none;
}
.bv-slider {
  flex: 1;
  accent-color: #3b50cc;
  min-width: 60px;
}
.bv-side-enter-active,
.bv-side-leave-active {
  transition: transform 0.22s ease, opacity 0.22s ease;
}
.bv-side-enter-from,
.bv-side-leave-to {
  transform: translateX(-30px);
  opacity: 0;
}
/* 狭い画面: 目次は紙面の上に重ねて出す */
@media (max-width: 899px) {
  .bv-outline {
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    width: min(320px, 85vw);
    box-shadow: 0 0 30px rgba(0, 0, 0, 0.4);
  }
  .bv-scrim {
    display: block;
    position: absolute;
    inset: 0;
    z-index: 1;
    background: rgba(0, 0, 0, 0.35);
  }
  .edge {
    display: none;
  }
}
@media (prefers-reduced-motion: reduce) {
  .paper.flip,
  .leaf {
    animation-duration: 0.01s !important;
  }
}
</style>
