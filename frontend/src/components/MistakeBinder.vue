<script setup lang="ts">
/**
 * 間違いノートの全画面閲覧。印刷と同じページ（見出し・とじ代・ページ番号つき。1 ページに複数の問題）を見開きで表示し、
 * 右端の科目・分野のインデックス（タブ）から該当ページへ飛べる。
 * ページ 1 は右側（奇数ページ = とじ側が左）。スマホ幅では 1 ページずつ表示する。
 * ←→ キー・画面の左右タップ・スワイプでめくる。めくるときは紙が 3D で回転するアニメーション（見開きは背で回る。1 ページ表示は紙全体が左へ返る）。
 * タッチ端末（iPad・スマホ。immersive）では縦横とも上のバーとタブを出さず、浮かせた ×・≡ だけにしてノートを大きく見せる（インデックスは ≡ のメニュー）。
 * 縦向きのときは見開きにせず 1 ページずつ表示する。
 * 右上の目のボタンで「問題だけ表示」（説明用の画像・文字・手書きを隠す。位置はそのまま）と「すべて表示」を切り替える（端末に記憶）。
 * 拡縮: iPad・スマホはピンチ（拡大中は 1 本指のドラッグで移動、ダブルタップで拡大 ⇄ 戻す）、PC はホイール（ダブルクリックでも）。
 * +/− キー・0 キーでも。ページをめくると等倍に戻る。拡大中は左右タップ・スワイプでのめくりは止まる（矢印ボタン・キーは使える）。
 */
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { mistakeApi } from '@/api/mistake'
import { PAGE_H, PAGE_W, groupKeyOf, groupNotes, pageIndexOf, paginate } from '@/lib/mistake'
import { renderBinderPage } from '@/lib/mistakeRender'
import { blockDoubleTapZoom, blockUiTextSelection, isTouch, viewportWidth } from '@/lib/native'
import type { MistakeNoteDetail, MistakeNoteSummary } from '@/types'

const props = defineProps<{ notes: MistakeNoteSummary[]; startId?: number | null }>()
const emit = defineEmits<{ close: [] }>()

/** 画面の向き（タッチ端末は縦向きだと 1 ページずつ表示） */
const landscapeMq = window.matchMedia('(orientation: landscape)')
const landscape = ref(landscapeMq.matches)
const onOrient = () => (landscape.value = landscapeMq.matches)
/** 1 ページずつ表示: スマホ幅、またはタッチ端末の縦向き */
const single = computed(() => viewportWidth.value < 760 || (isTouch && !landscape.value))
/** タッチ端末（iPad・スマホ）: 縦横とも上のバーと右端のインデックスを出さず、ノートを大きく見せる（インデックスは右上の ≡ から） */
const immersive = isTouch
const idxOpen = ref(false)
const root = ref<HTMLElement | null>(null)
const unblock: (() => void)[] = []
/** ページ割り（1 ページに複数の問題） */
const pages = computed(() => paginate(props.notes))
const total = computed(() => pages.value.length)

/** 右ページ（見開き）または表示ページ（1 ページ表示）の 0 始まりの番号 */
const pos = ref(0)
function spreadOf(i: number): number {
  return single.value ? i : i % 2 === 0 ? i : i + 1
}
/**
 * めくっている最中の紙（3D 回転）。front = 表（めくる前に見えていた面）、back = 裏（めくった後に見える面）。
 * staticLeft / staticRight はアニメーション中に下に敷いておくページ（めくる紙に隠れる側は新しいページ、反対側は古いページのまま）。
 */
interface Flip {
  side: 'left' | 'right' | 'full'
  front?: string
  back?: string
  from: string
  to: string
  go: boolean
  staticLeft?: number
  staticRight?: number
}
const flip = ref<Flip | null>(null)
let flipSeq = 0
const FLIP_MS = 720
const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches
const leftIdx = computed(() => (single.value ? -1 : (flip.value?.staticLeft ?? pos.value - 1)))
const rightIdx = computed(() => flip.value?.staticRight ?? pos.value)
const canPrev = computed(() => pos.value > 0)
const canNext = computed(() => (single.value ? pos.value < total.value - 1 : pos.value + 1 <= total.value - 1))

/** インデックスなどからの移動（アニメーションなし） */
function go(i: number) {
  flipSeq++
  flip.value = null
  resetZoom()
  pos.value = Math.max(0, Math.min(total.value - 1, spreadOf(i)))
}
function next() {
  if (!canNext.value || flip.value) return
  turn(pos.value, Math.min(total.value - 1, pos.value + (single.value ? 1 : 2)), 1)
}
function prev() {
  if (!canPrev.value || flip.value) return
  turn(pos.value, Math.max(0, pos.value - (single.value ? 1 : 2)), -1)
}
/** from → to へ、紙をめくるアニメーションつきで移動する */
async function turn(from: number, to: number, dir: 1 | -1) {
  resetZoom()
  if (reduceMotion) {
    pos.value = to
    return
  }
  const seq = ++flipSeq
  // めくった先のページを先に描いておく（長くても 0.5 秒だけ待つ）
  const want = single.value ? [to] : [to, to - 1]
  await Promise.race([Promise.all(want.filter((i) => i >= 0 && i < total.value).map((i) => ensurePage(i))), new Promise((r) => setTimeout(r, 500))])
  if (seq !== flipSeq) return
  let f: Flip
  if (single.value) {
    // 1 ページ表示: 進むときは今の紙が左へ返って消える。戻るときは左から前の紙が返ってきて覆う
    f =
      dir > 0
        ? { side: 'full', front: pageUrl(from), from: 'rotateY(0deg)', to: 'rotateY(-180deg)', go: false }
        : { side: 'full', front: pageUrl(to), from: 'rotateY(-180deg)', to: 'rotateY(0deg)', go: false, staticRight: from }
  } else if (dir > 0) {
    // 見開きで進む: 右の紙が背を軸に左へ回り、裏（次の左ページ）が左に乗る。右の下には次の右ページ
    f = { side: 'right', front: pageUrl(from), back: pageUrl(to - 1), from: 'rotateY(0deg)', to: 'rotateY(-180deg)', go: false, staticLeft: from - 1 }
  } else {
    // 見開きで戻る: 左の紙が背を軸に右へ回り、裏（前の右ページ）が右に乗る。左の下には前の左ページ
    f = { side: 'left', front: pageUrl(from - 1), back: pageUrl(to), from: 'rotateY(0deg)', to: 'rotateY(180deg)', go: false, staticRight: from }
  }
  flip.value = f
  pos.value = to
  await nextTick()
  requestAnimationFrame(() =>
    requestAnimationFrame(() => {
      if (seq === flipSeq && flip.value) flip.value.go = true
    }),
  )
  window.setTimeout(() => {
    if (seq === flipSeq) flip.value = null
  }, FLIP_MS + 40)
}
watch(single, () => go(pos.value))

// ---- ページの描画（印刷と同じ見た目）。結果はページ番号ごとにキャッシュ ----
const detailCache = new Map<number, Promise<MistakeNoteDetail>>()
const pageCache = new Map<number, Promise<string>>()
const urls = ref<Record<number, string>>({})
/** 問題だけ表示（説明用の画像・文字・手書きを隠す。位置はそのまま）。右上の目のボタンで切替。端末に記憶 */
const problemOnly = ref(localStorage.getItem('sm_mn_ponly') === '1')
watch(problemOnly, (v) => {
  localStorage.setItem('sm_mn_ponly', v ? '1' : '0')
  // 描いたページは表示の種類ごとに違うので、切り替えたら描き直す
  pageCache.clear()
  urls.value = {}
})

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
    const c = await renderBinderPage(page, details, { pageNo: i + 1, scale: 1, problemOnly: problemOnly.value })
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
  [pos, single, problemOnly],
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
  } else if (e.key === '+' || e.key === '=' || e.key === ';') {
    e.preventDefault()
    zoomStep(1)
  } else if (e.key === '-') {
    e.preventDefault()
    zoomStep(-1)
  } else if (e.key === '0') {
    e.preventDefault()
    resetZoom()
  }
}
// ---- 拡縮・移動（ピンチ／ホイール／ドラッグ） ----
const ZOOM_MIN = 1
const ZOOM_MAX = 5
const zoom = ref(1)
const pan = ref({ x: 0, y: 0 })
const zoomed = computed(() => zoom.value > 1.02)
const spreadEl = ref<HTMLElement | null>(null)
const spreadStyle = computed(() => ({ '--ratio': String(ratio), transform: zoomed.value ? `translate(${pan.value.x}px, ${pan.value.y}px) scale(${zoom.value})` : undefined }))
function resetZoom() {
  zoom.value = 1
  pan.value = { x: 0, y: 0 }
}
/** 見開きの中心（画面座標） */
function spreadCenter(): { x: number; y: number } {
  const el = spreadEl.value
  if (!el) return { x: 0, y: 0 }
  // 拡縮前の配置の中心 = getBoundingClientRect の中心（transform-origin が中心なので、中心は pan の分だけずれる）
  const r = el.getBoundingClientRect()
  return { x: r.left + r.width / 2 - pan.value.x, y: r.top + r.height / 2 - pan.value.y }
}
/** 移動の範囲: 拡大した見開きが画面から出すぎないように */
function clampPan(p: { x: number; y: number }, z: number): { x: number; y: number } {
  const el = spreadEl.value
  if (!el || z <= 1) return { x: 0, y: 0 }
  const mx = ((z - 1) * el.offsetWidth) / 2 + 40
  const my = ((z - 1) * el.offsetHeight) / 2 + 40
  return { x: Math.max(-mx, Math.min(mx, p.x)), y: Math.max(-my, Math.min(my, p.y)) }
}
/** 画面上の点 (sx, sy) を動かさずに拡縮する */
function zoomAt(newZoom: number, sx: number, sy: number) {
  const z0 = zoom.value
  const z1 = Math.max(ZOOM_MIN, Math.min(ZOOM_MAX, newZoom))
  if (z1 === z0) return
  const c = spreadCenter()
  // 見開き上の点 p（等倍・中心基準）は screen = c + pan + z*p で写る。screen を固定して pan を求め直す
  const px = (sx - c.x - pan.value.x) / z0
  const py = (sy - c.y - pan.value.y) / z0
  zoom.value = z1
  pan.value = z1 <= 1 ? { x: 0, y: 0 } : clampPan({ x: sx - c.x - z1 * px, y: sy - c.y - z1 * py }, z1)
}
function onWheel(e: WheelEvent) {
  // ホイール 1 目盛り（deltaY ≈ 100）で約 5%。トラックパッドのピンチ（ctrlKey）は刻みが細かいので強めに
  const k = e.ctrlKey ? 0.01 : 0.0005
  zoomAt(zoom.value * Math.exp(-e.deltaY * k), e.clientX, e.clientY)
}
function zoomStep(dir: 1 | -1) {
  const c = spreadCenter()
  zoomAt(zoom.value * (dir > 0 ? 1.25 : 0.8), c.x + pan.value.x, c.y + pan.value.y)
}

/** 触れている指（ポインター）。2 本でピンチ、拡大中の 1 本はドラッグ移動 */
const pointers = new Map<number, { x: number; y: number }>()
let pinch: { d0: number; z0: number } | null = null
let dragging = false
let gestured = false // ピンチ・ドラッグをした後は、離したときにタップ・スワイプと解釈しない
let sw: { x: number; y: number; t: number } | null = null
let lastTap: { x: number; y: number; t: number } | null = null
function onPointerDown(e: PointerEvent) {
  pointers.set(e.pointerId, { x: e.clientX, y: e.clientY })
  if (pointers.size === 2) {
    const [a, b] = Array.from(pointers.values())
    pinch = { d0: Math.max(10, Math.hypot(a!.x - b!.x, a!.y - b!.y)), z0: zoom.value }
    gestured = true
    sw = null
    return
  }
  if (pointers.size === 1) {
    gestured = false
    dragging = false
    sw = { x: e.clientX, y: e.clientY, t: e.timeStamp }
  }
}
function onPointerMove(e: PointerEvent) {
  const prev = pointers.get(e.pointerId)
  if (!prev) return
  pointers.set(e.pointerId, { x: e.clientX, y: e.clientY })
  if (pinch && pointers.size >= 2) {
    const [a, b] = Array.from(pointers.values())
    const d = Math.hypot(a!.x - b!.x, a!.y - b!.y)
    zoomAt(pinch.z0 * (d / pinch.d0), (a!.x + b!.x) / 2, (a!.y + b!.y) / 2)
    return
  }
  if (pointers.size === 1 && zoomed.value) {
    // 拡大中の 1 本指（またはマウスのドラッグ）は移動
    const dx = e.clientX - prev.x
    const dy = e.clientY - prev.y
    if (!dragging && sw && Math.hypot(e.clientX - sw.x, e.clientY - sw.y) < 6) return
    dragging = true
    gestured = true
    pan.value = clampPan({ x: pan.value.x + dx, y: pan.value.y + dy }, zoom.value)
  }
}
function onPointerUp(e: PointerEvent) {
  pointers.delete(e.pointerId)
  if (pointers.size < 2) pinch = null
  if (pointers.size > 0) return
  const s0 = sw
  sw = null
  dragging = false
  if (gestured || !s0) return
  const dx = e.clientX - s0.x
  const dy = e.clientY - s0.y
  const dt = e.timeStamp - s0.t
  const isTap = Math.abs(dx) < 8 && Math.abs(dy) < 8
  // ダブルタップ／ダブルクリック: 拡大 ⇄ 戻す
  if (isTap && lastTap && e.timeStamp - lastTap.t < 320 && Math.hypot(e.clientX - lastTap.x, e.clientY - lastTap.y) < 30) {
    lastTap = null
    if (zoomed.value) resetZoom()
    else zoomAt(2.5, e.clientX, e.clientY)
    return
  }
  lastTap = isTap ? { x: e.clientX, y: e.clientY, t: e.timeStamp } : null
  if (zoomed.value) return // 拡大中はタップ・スワイプでめくらない（矢印・キーで）
  if (Math.abs(dx) > 50 && Math.abs(dx) > Math.abs(dy) * 1.5 && dt < 800) {
    if (dx < 0) next()
    else prev()
    return
  }
  if (isTap) {
    // 左右のタップでめくる（真ん中のタップはダブルタップ待ち）
    const r = (e.currentTarget as HTMLElement).getBoundingClientRect()
    window.setTimeout(() => {
      if (lastTap && lastTap.t === e.timeStamp) {
        lastTap = null
        if (e.clientX < r.left + r.width * 0.3) prev()
        else if (e.clientX > r.left + r.width * 0.7) next()
      }
    }, 330)
  }
}
function onPointerCancel(e: PointerEvent) {
  pointers.delete(e.pointerId)
  if (pointers.size < 2) pinch = null
  if (pointers.size === 0) {
    sw = null
    dragging = false
  }
}

onMounted(() => {
  if (props.startId != null) {
    const i = pageIndexOf(pages.value, props.startId)
    if (i >= 0) go(i)
  }
  window.addEventListener('keydown', onKey)
  landscapeMq.addEventListener('change', onOrient)
  if (root.value) {
    unblock.push(blockUiTextSelection(root.value))
    if (isTouch) unblock.push(blockDoubleTapZoom(root.value))
  }
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
  unblock.forEach((f) => f())
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
      <!-- 問題だけ表示 ⇄ すべて表示（目のアイコン。文字なし） -->
      <button class="fab ponly-fab" :class="{ on: problemOnly }" :aria-pressed="problemOnly" :title="problemOnly ? 'すべて表示（説明・手書きも見せる）' : '問題だけ表示（説明・手書きを隠す）'" :aria-label="problemOnly ? 'すべて表示' : '問題だけ表示'" @click="problemOnly = !problemOnly">
        <svg v-if="problemOnly" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12z" /><circle cx="12" cy="12" r="3" /><path d="M3 3l18 18" /></svg>
        <svg v-else width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12z" /><circle cx="12" cy="12" r="3" /></svg>
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
      <button class="x" :class="{ on: problemOnly }" :aria-pressed="problemOnly" :title="problemOnly ? 'すべて表示（説明・手書きも見せる）' : '問題だけ表示（説明・手書きを隠す）'" :aria-label="problemOnly ? 'すべて表示' : '問題だけ表示'" @click="problemOnly = !problemOnly">
        <svg v-if="problemOnly" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12z" /><circle cx="12" cy="12" r="3" /><path d="M3 3l18 18" /></svg>
        <svg v-else width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12z" /><circle cx="12" cy="12" r="3" /></svg>
      </button>
      <button class="x" aria-label="閉じる" @click="emit('close')">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18" /></svg>
      </button>
    </div>

    <div class="stage" :class="{ zoomed }" @pointerdown="onPointerDown" @pointermove="onPointerMove" @pointerup="onPointerUp" @pointercancel="onPointerCancel" @wheel.prevent="onWheel">
      <button class="nav l" :disabled="!canPrev" aria-label="前のページ" @pointerdown.stop @pointerup.stop @click.stop="prev">
        <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M15 5l-7 7 7 7" /></svg>
      </button>

      <div ref="spreadEl" class="spread" :class="{ single }" :style="spreadStyle">
        <div v-if="!single" class="page left" :class="{ blank: leftIdx < 0 }">
          <img v-if="pageUrl(leftIdx)" :src="pageUrl(leftIdx)" alt="" draggable="false" />
          <div v-else-if="leftIdx >= 0" class="loading">読み込み中…</div>
        </div>
        <div class="page right" :class="{ blank: rightIdx >= total }">
          <img v-if="pageUrl(rightIdx)" :src="pageUrl(rightIdx)" alt="" draggable="false" />
          <div v-else-if="rightIdx < total" class="loading">読み込み中…</div>
        </div>
        <div v-if="!single" class="spine"></div>
        <!-- めくっている紙（3D 回転。表と裏） -->
        <div v-if="flip" class="flip" :class="[flip.side, { go: flip.go }]" :style="{ transform: flip.go ? flip.to : flip.from }">
          <div class="face front">
            <img v-if="flip.front" :src="flip.front" alt="" draggable="false" />
            <div v-else class="paper"></div>
            <div class="shade"></div>
          </div>
          <div class="face back">
            <img v-if="flip.back" :src="flip.back" alt="" draggable="false" />
            <div v-else class="paper"></div>
            <div class="shade"></div>
          </div>
        </div>
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

      <button class="nav r" :disabled="!canNext" aria-label="次のページ" @pointerdown.stop @pointerup.stop @click.stop="next">
        <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M9 5l7 7-7 7" /></svg>
      </button>
      <!-- 拡大中: 倍率と「戻す」 -->
      <button v-if="zoomed" class="zoom-badge dm" title="等倍に戻す（ダブルタップ・0 キーでも）" @pointerdown.stop @pointerup.stop @click="resetZoom">{{ Math.round(zoom * 100) }}% ×</button>
    </div>

    <!-- インデックスのメニュー（横向き。≡ で右から出す。背景タップ・×・Esc で閉じる） -->
    <Transition name="idx" :duration="{ enter: 320, leave: 240 }">
      <div v-if="idxOpen" class="idx">
        <div class="idx-scrim" data-tap @click="idxOpen = false"></div>
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
  /* ピンチ・ドラッグを自前で扱う */
  touch-action: none;
  overflow: hidden;
}
.stage.zoomed {
  cursor: grab;
}
.zoom-badge {
  position: absolute;
  left: 50%;
  bottom: calc(14px + env(safe-area-inset-bottom));
  transform: translateX(-50%);
  z-index: 6;
  padding: 7px 14px;
  border: none;
  border-radius: 999px;
  background: rgba(28, 32, 36, 0.82);
  color: #fff;
  font-size: 12.5px;
  font-weight: 700;
  cursor: pointer;
  box-shadow: 0 2px 10px rgba(0, 0, 0, 0.35);
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
  perspective: 2600px;
  /* 見開き 2 ページが枠に収まる大きさ（縦横どちらにも収める） */
  height: min(100%, calc((100vw - 220px) / 2 * var(--ratio)));
  aspect-ratio: calc(2 / var(--ratio));
  max-width: 100%;
  transform-origin: center center;
  will-change: transform;
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
/* ---- めくる紙（3D） ---- */
.flip {
  position: absolute;
  top: 0;
  bottom: 0;
  z-index: 4;
  transform-style: preserve-3d;
  transition: transform 0.72s cubic-bezier(0.45, 0.05, 0.3, 1);
  will-change: transform;
  pointer-events: none;
}
.flip.right {
  left: 50%;
  width: 50%;
  transform-origin: left center;
}
.flip.left {
  left: 0;
  width: 50%;
  transform-origin: right center;
}
.flip.full {
  left: 0;
  width: 100%;
  transform-origin: left center;
}
.face {
  position: absolute;
  inset: 0;
  background: #fff;
  overflow: hidden;
  -webkit-backface-visibility: hidden;
  backface-visibility: hidden;
  box-shadow: 0 10px 40px rgba(0, 0, 0, 0.45);
}
.face img {
  display: block;
  width: 100%;
  height: 100%;
  object-fit: contain;
  pointer-events: none;
}
.face.back {
  transform: rotateY(180deg);
}
.flip.right .face.front,
.flip.left .face.back {
  border-radius: 0 6px 6px 0;
}
.flip.right .face.back,
.flip.left .face.front {
  border-radius: 6px 0 0 6px;
}
.flip.full .face {
  border-radius: 6px;
}
.paper {
  position: absolute;
  inset: 0;
  background: linear-gradient(90deg, #f1f1f1, #fff 45%, #e9e9e9);
}
/* 背に近い側が回転中に暗くなる */
.shade {
  position: absolute;
  inset: 0;
  opacity: 0;
  transition: opacity 0.36s ease;
}
.flip.right .shade,
.flip.full .shade {
  background: linear-gradient(90deg, rgba(0, 0, 0, 0.28), rgba(0, 0, 0, 0) 45%);
}
.flip.left .shade {
  background: linear-gradient(-90deg, rgba(0, 0, 0, 0.28), rgba(0, 0, 0, 0) 45%);
}
.flip.go .shade {
  opacity: 1;
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
.ponly-fab {
  right: calc(56px + env(safe-area-inset-right));
}
/* 問題だけ表示のとき（目のボタンが押された状態） */
.fab.on,
.x.on {
  background: #fff;
  color: #1c2024;
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
.immersive .spread.single {
  height: min(100%, calc((100vw - 120px) * var(--ratio)));
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
  /* スマホ: タブが無い分、下はめくるボタンの高さだけ空ける */
  .immersive .stage {
    padding: calc(8px + env(safe-area-inset-top)) 12px calc(76px + env(safe-area-inset-bottom));
  }
  .immersive .spread.single {
    height: min(100%, calc((100vw - 24px) * var(--ratio)));
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
