<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import type { AnnotationDoc, AnnotationItem, AnnotationShape } from '@/types'

/**
 * 回答写真の採点・添削エディタ（PC / iPad 共用）。
 * - フリーハンド（Apple Pencil の筆圧なし・パームリジェクション付き）、図形（○ × △ ✓ 直線 矩形）、テキスト、消しゴム、選択移動
 * - テキストは入力後も「選択」でドラッグ移動・右下ハンドルでサイズ変更できる（入力確定時に自動で選択状態になる）
 * - 注釈は画像ピクセル座標のベクターとして保持し、変更が止まると自動保存（合成 JPEG も生成）
 * - toolbarTarget を指定するとツールバーをその要素へテレポートして縦型表示にする
 * - compact: 全画面添削のサイドメニュー用。ペン・消しゴム・戻す・進むだけ常時表示し、他は展開ボタンで表示
 * - fitToContainer: 表示領域（.stage）の高さに合わせて画像をフィットさせる（全画面表示用）
 * - フリーハンドで線を引いたまま止めて（ホールド）いると、直線に書き直される（ほぼ直線の場合のみ）
 * - タッチ操作はブラウザに任せず自前で処理する: 2本指のピンチで画像だけを拡縮（ページは拡縮しない）、
 *   「指はスクロール」のときは1本指でスクロール
 */
type Tool = 'select' | 'pen' | 'eraser' | AnnotationShape | 'text'

const props = defineProps<{
  imageUrl: string
  modelValue: AnnotationDoc | null
  saving?: boolean
  readonly?: boolean
  toolbarTarget?: string
  compact?: boolean
  fitToContainer?: boolean
}>()
const emit = defineEmits<{ save: [doc: AnnotationDoc, blob: Blob]; dirty: [boolean] }>()

const TOOLS: { key: Tool; label: string; icon: string }[] = [
  { key: 'select', label: '選択', icon: 'M5 3l14 8-6 2-3 6z' },
  { key: 'pen', label: 'ペン', icon: 'M4 20l4-1L19 8l-3-3L5 16z M14 7l3 3' },
  { key: 'eraser', label: '消しゴム', icon: 'M4 15l8-8 6 6-6 6H8z M9 20h11' },
  { key: 'circle', label: '○', icon: 'M12 20a8 8 0 1 0 0-16 8 8 0 0 0 0 16z' },
  { key: 'cross', label: '×', icon: 'M6 6l12 12 M18 6L6 18' },
  { key: 'triangle', label: '△', icon: 'M12 5l8 14H4z' },
  { key: 'check', label: '✓', icon: 'M4 13l5 5L20 6' },
  { key: 'line', label: '直線', icon: 'M5 19L19 5' },
  { key: 'rect', label: '矩形', icon: 'M5 6h14v12H5z' },
  { key: 'text', label: '文字', icon: 'M5 6h14 M12 6v13 M8 19h8' },
]
const COLORS = ['#e5322d', '#2d5be5', '#1f9d55', '#111111', '#e58f2d']
const iconOf = (k: Tool) => TOOLS.find((t) => t.key === k)?.icon ?? ''
const ICON_UNDO = 'M9 14L4 9l5-5 M4 9h10a6 6 0 0 1 0 12h-3'
const ICON_REDO = 'M15 14l5-5-5-5 M20 9H10a6 6 0 0 0 0 12h3'
/** compact（全画面サイドメニュー）で展開時に表示するツール */
const SECONDARY_TOOLS = TOOLS.filter((t) => t.key !== 'pen' && t.key !== 'eraser')
const WIDTHS: { key: 'thin' | 'mid' | 'thick'; label: string; f: number }[] = [
  { key: 'thin', label: '細', f: 1.4 },
  { key: 'mid', label: '中', f: 2.4 },
  { key: 'thick', label: '太', f: 4 },
]

const tool = ref<Tool>('pen')
const color = ref(COLORS[0]!)
/** 線の太さ係数（画像幅 500px 基準）。既定は「細」。プリセット以外にスライダーで自由に設定できる */
const widthF = ref(1.4)
const WIDTH_MIN = 0.6
const WIDTH_MAX = 6
const isWidth = (f: number) => Math.abs(widthF.value - f) < 0.05
/** 縦型ツールバーの詳細設定（色・太さ・取り消し・ズーム等）の展開状態。既定は閉じる */
const expanded = ref(false)
/** 指はスクロール（ペン・マウスのみで描く）。スマホ・タブレットでは既定でオン */
const penOnly = ref(typeof window !== 'undefined' && !!window.matchMedia?.('(pointer: coarse)').matches)
const zoom = ref(1)
const fitScale = ref(1)
const scale = computed(() => fitScale.value * zoom.value)

const container = ref<HTMLElement | null>(null)
const innerEl = ref<HTMLElement | null>(null)
const canvas = ref<HTMLCanvasElement | null>(null)
const img = ref<HTMLImageElement | null>(null)
const W = ref(0)
const H = ref(0)
const loaded = ref(false)

const items = ref<AnnotationItem[]>([])
const history = ref<string[]>([])
const redoStack = ref<string[]>([])
const selectedId = ref<string | null>(null)
const dirty = ref(false)

/** 画像サイズに対する相対的な線幅（画像幅 500px 基準） */
const unit = computed(() => Math.max(1, W.value / 500))
const lineWidth = computed(() => unit.value * widthF.value)
const textSize = computed(() => Math.round(W.value / 42))

// ---------- 読み込み ----------
function loadImage() {
  loaded.value = false
  const im = new Image()
  im.onload = () => {
    img.value = im
    W.value = im.naturalWidth
    H.value = im.naturalHeight
    loaded.value = true
    fit()
    nextTick(draw)
  }
  im.src = props.imageUrl
}

function loadItems() {
  items.value = props.modelValue?.items ? JSON.parse(JSON.stringify(props.modelValue.items)) : []
  history.value = []
  redoStack.value = []
  selectedId.value = null
  setDirty(false)
  draw()
}

watch(() => props.imageUrl, loadImage)
watch(
  () => props.modelValue,
  (v) => {
    // 自動保存した内容が親から戻ってきただけ（中身が同じ）なら再読込しない
    // （再読込すると履歴が消えて「元に戻す」が効かなくなる）
    if (JSON.stringify(v?.items ?? []) === JSON.stringify(items.value)) return
    loadItems()
  },
)

function setDirty(v: boolean) {
  if (dirty.value !== v) {
    dirty.value = v
    emit('dirty', v)
  }
  if (v && !props.readonly) scheduleSave()
}

// ---------- 自動保存（変更が止まったら保存） ----------
let saveTimer: ReturnType<typeof setTimeout> | null = null
function scheduleSave(delay = 1000) {
  if (saveTimer) clearTimeout(saveTimer)
  saveTimer = setTimeout(() => {
    saveTimer = null
    if (!dirty.value || props.saving) return
    // 描画中・テキスト入力中は完了を待つ
    if (activePointer !== null || textEdit.value) {
      scheduleSave(800)
      return
    }
    save()
  }, delay)
}

// ---------- 表示 ----------
let ro: ResizeObserver | null = null
let lastCw = 0
let lastCh = 0
function fit() {
  if (!container.value || !W.value) return
  lastCw = container.value.clientWidth
  const cw = lastCw - 2
  // 画像全体が画面に収まるサイズを既定にする（幅・高さの両方でフィット）
  const top = container.value.getBoundingClientRect().top
  lastCh = container.value.clientHeight
  const availH = props.fitToContainer && lastCh > 0
    ? Math.max(120, lastCh - 2)
    : Math.max(280, window.innerHeight - Math.max(0, top) - 24)
  fitScale.value = Math.min(1, cw / W.value, availH / H.value) || 1
  zoom.value = 1
  nextTick(draw)
}
function zoomBy(f: number) {
  zoom.value = Math.min(5, Math.max(0.5, zoom.value * f))
  nextTick(draw)
}

function draw() {
  const c = canvas.value
  if (!c || !W.value) return
  const cw = Math.round(W.value * scale.value)
  const ch = Math.round(H.value * scale.value)
  // 拡大時にキャンバスの実サイズが大きくなりすぎると（iOS Safari は約 1,600 万画素・1辺 8192px が上限）
  // 何も描画されなくなるため、実サイズの倍率（dpr）を上限内に収める
  let dpr = window.devicePixelRatio || 1
  const MAX_AREA = 14_000_000
  const MAX_SIDE = 8192
  if (cw * ch * dpr * dpr > MAX_AREA) dpr = Math.sqrt(MAX_AREA / (cw * ch))
  if (cw * dpr > MAX_SIDE) dpr = MAX_SIDE / cw
  if (ch * dpr > MAX_SIDE) dpr = MAX_SIDE / ch
  const bw = Math.max(1, Math.round(cw * dpr))
  const bh = Math.max(1, Math.round(ch * dpr))
  if (c.width !== bw || c.height !== bh) {
    c.width = bw
    c.height = bh
  }
  c.style.width = cw + 'px'
  c.style.height = ch + 'px'
  const ctx = c.getContext('2d')!
  ctx.setTransform((bw / cw) * scale.value, 0, 0, (bh / ch) * scale.value, 0, 0)
  ctx.clearRect(0, 0, W.value, H.value)
  for (const it of items.value) drawItem(ctx, it)
  if (temp) drawItem(ctx, temp)
  // 消しゴム: 消える範囲を円で示す
  if (tool.value === 'eraser' && hover.value && !props.readonly) {
    ctx.save()
    ctx.beginPath()
    ctx.arc(hover.value.x, hover.value.y, ERASE_TOL / scale.value, 0, Math.PI * 2)
    ctx.fillStyle = 'rgba(229, 50, 45, 0.12)'
    ctx.fill()
    ctx.setLineDash([4 / scale.value, 3 / scale.value])
    ctx.strokeStyle = '#e5322d'
    ctx.lineWidth = 1.5 / scale.value
    ctx.stroke()
    ctx.restore()
  }
  if (selectedId.value) {
    const it = items.value.find((x) => x.id === selectedId.value)
    if (it) {
      const b = bbox(it)
      const pad = 8 / scale.value
      ctx.save()
      ctx.setLineDash([6 / scale.value, 4 / scale.value])
      ctx.strokeStyle = '#3b50cc'
      ctx.lineWidth = 1.5 / scale.value
      ctx.strokeRect(b.x - pad, b.y - pad, b.w + pad * 2, b.h + pad * 2)
      // テキストは右下ハンドルでサイズ変更できる
      if (it.type === 'text') {
        const h = resizeHandle(it)
        ctx.setLineDash([])
        ctx.fillStyle = '#3b50cc'
        ctx.fillRect(h.x, h.y, h.s, h.s)
      }
      ctx.restore()
    }
  }
}

/** 選択中テキストのサイズ変更ハンドル（枠の右下角、画像座標） */
function resizeHandle(it: AnnotationItem): { x: number; y: number; s: number } {
  const b = bbox(it)
  const pad = 8 / scale.value
  const s = 12 / scale.value
  return { x: b.x + b.w + pad - s / 2, y: b.y + b.h + pad - s / 2, s }
}

function drawItem(ctx: CanvasRenderingContext2D, it: AnnotationItem) {
  ctx.save()
  ctx.lineCap = 'round'
  ctx.lineJoin = 'round'
  ctx.strokeStyle = it.color
  ctx.fillStyle = it.color
  if (it.type === 'pen') {
    ctx.lineWidth = it.width
    const p = it.points
    if (p.length < 4) {
      if (p.length >= 2) {
        ctx.beginPath()
        ctx.arc(p[0]!, p[1]!, it.width / 2, 0, Math.PI * 2)
        ctx.fill()
      }
    } else {
      ctx.beginPath()
      ctx.moveTo(p[0]!, p[1]!)
      for (let i = 2; i < p.length - 2; i += 2) {
        const mx = (p[i]! + p[i + 2]!) / 2
        const my = (p[i + 1]! + p[i + 3]!) / 2
        ctx.quadraticCurveTo(p[i]!, p[i + 1]!, mx, my)
      }
      ctx.lineTo(p[p.length - 2]!, p[p.length - 1]!)
      ctx.stroke()
    }
  } else if (it.type === 'text') {
    ctx.font = `700 ${it.size}px 'Zen Kaku Gothic New', -apple-system, sans-serif`
    ctx.textBaseline = 'top'
    it.text.split('\n').forEach((line, i) => ctx.fillText(line, it.x, it.y + i * it.size * 1.3))
  } else {
    ctx.lineWidth = it.width
    const x1 = Math.min(it.x1, it.x2)
    const y1 = Math.min(it.y1, it.y2)
    const x2 = Math.max(it.x1, it.x2)
    const y2 = Math.max(it.y1, it.y2)
    const w = x2 - x1
    const h = y2 - y1
    ctx.beginPath()
    switch (it.type) {
      case 'circle':
        ctx.ellipse(x1 + w / 2, y1 + h / 2, Math.max(1, w / 2), Math.max(1, h / 2), 0, 0, Math.PI * 2)
        break
      case 'cross':
        ctx.moveTo(x1, y1)
        ctx.lineTo(x2, y2)
        ctx.moveTo(x2, y1)
        ctx.lineTo(x1, y2)
        break
      case 'triangle':
        ctx.moveTo(x1 + w / 2, y1)
        ctx.lineTo(x2, y2)
        ctx.lineTo(x1, y2)
        ctx.closePath()
        break
      case 'check':
        ctx.moveTo(x1, y1 + h * 0.55)
        ctx.lineTo(x1 + w * 0.38, y2)
        ctx.lineTo(x2, y1)
        break
      case 'line':
        ctx.moveTo(it.x1, it.y1)
        ctx.lineTo(it.x2, it.y2)
        break
      case 'rect':
        ctx.rect(x1, y1, w, h)
        break
    }
    ctx.stroke()
  }
  ctx.restore()
}

const measureCtx = document.createElement('canvas').getContext('2d')!
function bbox(it: AnnotationItem): { x: number; y: number; w: number; h: number } {
  if (it.type === 'pen') {
    let minX = Infinity
    let minY = Infinity
    let maxX = -Infinity
    let maxY = -Infinity
    for (let i = 0; i < it.points.length; i += 2) {
      minX = Math.min(minX, it.points[i]!)
      maxX = Math.max(maxX, it.points[i]!)
      minY = Math.min(minY, it.points[i + 1]!)
      maxY = Math.max(maxY, it.points[i + 1]!)
    }
    const p = it.width / 2
    return { x: minX - p, y: minY - p, w: maxX - minX + p * 2, h: maxY - minY + p * 2 }
  }
  if (it.type === 'text') {
    measureCtx.font = `700 ${it.size}px 'Zen Kaku Gothic New', -apple-system, sans-serif`
    const lines = it.text.split('\n')
    const w = Math.max(...lines.map((l) => measureCtx.measureText(l).width), it.size)
    return { x: it.x, y: it.y, w, h: lines.length * it.size * 1.3 }
  }
  const x = Math.min(it.x1, it.x2)
  const y = Math.min(it.y1, it.y2)
  return { x, y, w: Math.abs(it.x2 - it.x1), h: Math.abs(it.y2 - it.y1) }
}

function hit(it: AnnotationItem, x: number, y: number, tol: number): boolean {
  const b = bbox(it)
  if (x < b.x - tol || x > b.x + b.w + tol || y < b.y - tol || y > b.y + b.h + tol) return false
  if (it.type === 'pen') {
    const t = it.width / 2 + tol
    for (let i = 0; i < it.points.length - 2; i += 2) {
      if (distToSeg(x, y, it.points[i]!, it.points[i + 1]!, it.points[i + 2]!, it.points[i + 3]!) <= t) return true
    }
    return it.points.length <= 2
  }
  if (it.type === 'line') return distToSeg(x, y, it.x1, it.y1, it.x2, it.y2) <= it.width / 2 + tol
  return true
}
function distToSeg(px: number, py: number, x1: number, y1: number, x2: number, y2: number): number {
  const dx = x2 - x1
  const dy = y2 - y1
  const l2 = dx * dx + dy * dy
  let t = l2 ? ((px - x1) * dx + (py - y1) * dy) / l2 : 0
  t = Math.max(0, Math.min(1, t))
  const cx = x1 + t * dx
  const cy = y1 + t * dy
  return Math.hypot(px - cx, py - cy)
}

// ---------- 履歴 ----------
function snapshot() {
  history.value.push(JSON.stringify(items.value))
  if (history.value.length > 100) history.value.shift()
  redoStack.value = []
}
function commit() {
  setDirty(true)
  draw()
}
function undo() {
  const s = history.value.pop()
  if (s === undefined) return
  redoStack.value.push(JSON.stringify(items.value))
  items.value = JSON.parse(s)
  selectedId.value = null
  commit()
}
function redo() {
  const s = redoStack.value.pop()
  if (s === undefined) return
  history.value.push(JSON.stringify(items.value))
  items.value = JSON.parse(s)
  selectedId.value = null
  commit()
}
function clearAll() {
  if (!items.value.length) return
  if (!confirm('この用紙の注釈をすべて消しますか？')) return
  snapshot()
  items.value = []
  selectedId.value = null
  commit()
}
function deleteSelected() {
  if (!selectedId.value) return
  snapshot()
  items.value = items.value.filter((x) => x.id !== selectedId.value)
  selectedId.value = null
  commit()
}

// ---------- ポインタ操作 ----------
let temp: AnnotationItem | null = null
let activePointer: number | null = null
/** 消しゴムの有効半径（画面 px）と、消しゴム表示用のポインタ位置（画像座標） */
const ERASE_TOL = 14
const hover = ref<{ x: number; y: number } | null>(null)
/** 描画中のポインタ種別（pen / touch / mouse）。ペンで描いている間は指（手のひら）を無視するために使う */
let activeType = ''
let start: { x: number; y: number } | null = null
let dragItem: { id: string; ox: number; oy: number } | null = null
/** テキストのサイズ変更ドラッグ中の状態 */
let resizeItem: { id: string; startSize: number; sx: number; sy: number; snapped: boolean } | null = null
let moved = false
const uid = () => Math.random().toString(36).slice(2, 10)

// ---------- フリーハンドのホールドで直線化 ----------
let holdTimer: ReturnType<typeof setTimeout> | null = null
let holdAnchor: { x: number; y: number } | null = null
const HOLD_MS = 550
function clearHold() {
  if (holdTimer) clearTimeout(holdTimer)
  holdTimer = null
  holdAnchor = null
}
/** ペンが動いたら計測をやり直す。止まったまま HOLD_MS 経つと直線化を試みる */
function armHold(p: { x: number; y: number }) {
  if (holdAnchor && Math.hypot(p.x - holdAnchor.x, p.y - holdAnchor.y) * scale.value < 4) return
  if (holdTimer) clearTimeout(holdTimer)
  holdAnchor = p
  holdTimer = setTimeout(straightenIfHeld, HOLD_MS)
}
function straightenIfHeld() {
  holdTimer = null
  if (!temp || temp.type !== 'pen') return
  const pts = temp.points
  if (pts.length < 8) return
  const x1 = pts[0]!
  const y1 = pts[1]!
  const x2 = pts[pts.length - 2]!
  const y2 = pts[pts.length - 1]!
  const len = Math.hypot(x2 - x1, y2 - y1)
  if (len * scale.value < 40) return // 短すぎる（点・小さな丸など）は対象外
  // 始点→現在位置の線分からのずれが小さい（ほぼ直線）場合だけ直線にする
  let maxD = 0
  for (let i = 0; i < pts.length; i += 2) {
    maxD = Math.max(maxD, distToSeg(pts[i]!, pts[i + 1]!, x1, y1, x2, y2))
  }
  if (maxD > Math.max(10 / scale.value, len * 0.08)) return
  // 水平・垂直に近ければ揃える
  let ex = x2
  let ey = y2
  const deg = Math.abs((Math.atan2(y2 - y1, x2 - x1) * 180) / Math.PI)
  if (deg < 8 || deg > 172) ey = y1
  else if (Math.abs(deg - 90) < 8) ex = x1
  temp = { id: temp.id, type: 'line', color: temp.color, width: temp.width, x1, y1, x2: ex, y2: ey }
  draw()
}

function toImage(e: PointerEvent): { x: number; y: number } {
  const r = canvas.value!.getBoundingClientRect()
  return { x: (e.clientX - r.left) / scale.value, y: (e.clientY - r.top) / scale.value }
}

function onDown(e: PointerEvent) {
  if (props.readonly || !loaded.value) return
  if (e.pointerType === 'pen' && !penOnly.value) penOnly.value = true // Apple Pencil を検出したら指はスクロール扱い
  if (e.pointerType === 'touch' && penOnly.value) return
  if (activePointer !== null) {
    // 指で描いている途中にペンが触れたらペンを優先する。それ以外（描画中の別ポインタ）は無視
    if (e.pointerType === 'pen' && activeType === 'touch') cancelActive()
    else return
  }
  if (e.button !== 0 && e.pointerType === 'mouse') return
  if (textEdit.value) commitText()
  activePointer = e.pointerId
  activeType = e.pointerType
  try {
    canvas.value!.setPointerCapture(e.pointerId)
  } catch {
    // キャプチャできない環境でも描画は続ける（終了は document 側の保険で拾う）
  }
  e.preventDefault()
  const p = toImage(e)
  start = p
  moved = false
  const tol = 10 / scale.value

  switch (tool.value) {
    case 'pen':
      temp = { id: uid(), type: 'pen', color: color.value, width: lineWidth.value, points: [p.x, p.y] }
      armHold(p)
      break
    case 'eraser':
      eraseAt(p.x, p.y)
      break
    case 'select': {
      // 選択中テキストのサイズ変更ハンドルを優先判定
      const sel = selectedId.value ? items.value.find((x) => x.id === selectedId.value) : null
      if (sel && sel.type === 'text') {
        const h = resizeHandle(sel)
        const grip = Math.max(h.s, 16 / scale.value)
        if (Math.abs(p.x - (h.x + h.s / 2)) <= grip && Math.abs(p.y - (h.y + h.s / 2)) <= grip) {
          resizeItem = { id: sel.id, startSize: sel.size, sx: p.x, sy: p.y, snapped: false }
          break
        }
      }
      const it = [...items.value].reverse().find((x) => hit(x, p.x, p.y, tol))
      selectedId.value = it?.id ?? null
      if (it) dragItem = { id: it.id, ox: p.x, oy: p.y }
      draw()
      break
    }
    case 'text':
      break
    default:
      temp = { id: uid(), type: tool.value, color: color.value, width: lineWidth.value, x1: p.x, y1: p.y, x2: p.x, y2: p.y }
  }
}

function onMove(e: PointerEvent) {
  if (tool.value === 'eraser' && !props.readonly && (activePointer === null || activePointer === e.pointerId)) {
    // 押していなくても（マウス・ペンのホバー）消しゴムの範囲を表示する
    hover.value = toImage(e)
    if (activePointer !== e.pointerId) draw()
  }
  if (activePointer !== e.pointerId) return
  const events = typeof e.getCoalescedEvents === 'function' ? e.getCoalescedEvents() : [e]
  const last = toImage(e)
  if (start && Math.hypot(last.x - start.x, last.y - start.y) * scale.value > 4) moved = true

  if (temp?.type === 'pen') {
    for (const ev of events) {
      const p = toImage(ev)
      temp.points.push(p.x, p.y)
    }
    armHold(last)
    draw()
  } else if (temp && temp.type !== 'text') {
    temp.x2 = last.x
    temp.y2 = last.y
    draw()
  } else if (tool.value === 'eraser') {
    eraseAt(last.x, last.y)
  } else if (resizeItem) {
    const it = items.value.find((x) => x.id === resizeItem!.id)
    if (it && it.type === 'text') {
      if (!resizeItem.snapped) {
        snapshot()
        resizeItem.snapped = true
      }
      const d = (last.x - resizeItem.sx + (last.y - resizeItem.sy)) / 2
      it.size = Math.max(Math.round(W.value / 90), Math.min(Math.round(W.value / 4), Math.round(resizeItem.startSize + d)))
      draw()
    }
  } else if (dragItem) {
    const dx = last.x - dragItem.ox
    const dy = last.y - dragItem.oy
    dragItem.ox = last.x
    dragItem.oy = last.y
    const it = items.value.find((x) => x.id === dragItem!.id)
    if (it) {
      if (!moved) snapshot()
      moveItem(it, dx, dy)
      draw()
    }
  }
}

function onUp(e: PointerEvent) {
  if (activePointer !== e.pointerId) return
  activePointer = null
  clearHold()
  const p = toImage(e)

  if (temp) {
    if (temp.type !== 'pen' && temp.type !== 'text' && !moved) {
      // タップのみ: 既定サイズの図形を中央配置
      const s = W.value / 14
      temp.x1 = p.x - s / 2
      temp.y1 = p.y - s / 2
      temp.x2 = p.x + s / 2
      temp.y2 = p.y + s / 2
    }
    snapshot()
    items.value.push(temp)
    temp = null
    commit()
  } else if (tool.value === 'text' && !moved) {
    openText(p.x, p.y)
  } else if (tool.value === 'select' && resizeItem) {
    if (resizeItem.snapped) setDirty(true)
    resizeItem = null
    draw()
  } else if (tool.value === 'select' && dragItem) {
    if (moved) setDirty(true)
    dragItem = null
    draw()
  }
  start = null
}

// ---------- タッチのピンチ拡縮・1本指スクロール（ステージで受ける） ----------
const touches = new Map<number, { x: number; y: number; t: number }>()
let pinch: { dist: number; zoom0: number } | null = null
let pan: { id: number; x: number; y: number } | null = null
function cancelActive() {
  if (activePointer === null) return
  try {
    canvas.value?.releasePointerCapture(activePointer)
  } catch {
    // ignore
  }
  activePointer = null
  clearHold()
  temp = null
  dragItem = null
  resizeItem = null
  draw()
}
function touchMid(): { x: number; y: number; dist: number } {
  const [a, b] = [...touches.values()]
  if (!a || !b) return { x: 0, y: 0, dist: 0 }
  return { x: (a.x + b.x) / 2, y: (a.y + b.y) / 2, dist: Math.hypot(a.x - b.x, a.y - b.y) }
}
function onStageDown(e: PointerEvent) {
  if (props.readonly || e.pointerType !== 'touch') return
  // ペン（Apple Pencil）で描いている最中の指（手のひら）はピンチ・スクロールに使わない
  if (activePointer !== null && activeType === 'pen') return
  const now = performance.now()
  // 終了イベントが届かず残った古い指の記録は捨てる（残っていると2本指扱いになり描画が取り消され続ける）
  for (const [id, t] of touches) {
    if (now - t.t > 15000) touches.delete(id)
  }
  touches.set(e.pointerId, { x: e.clientX, y: e.clientY, t: now })
  if (touches.size === 2) {
    const [a, b] = [...touches.values()]
    // ほぼ同時に置かれた2本の指だけをピンチとみなす（遅れて触れた指は手のひら等として無視）
    if (a && b && Math.abs(a.t - b.t) <= 500) {
      cancelActive()
      pan = null
      pinch = { dist: Math.max(1, touchMid().dist), zoom0: zoom.value }
    }
  } else if (touches.size === 1 && penOnly.value) {
    pan = { id: e.pointerId, x: e.clientX, y: e.clientY }
  }
}
/** 指を離した位置がステージ外でも記録を消す。描画中ポインタの終了が canvas に届かなかった場合の保険にもなる */
function onDocPointerEnd(e: PointerEvent) {
  if (touches.has(e.pointerId)) onStageUp(e)
  if (activePointer === e.pointerId) {
    if (e.type === 'pointerup') onUp(e)
    else onCancel(e)
  }
}
let pinchBusy = false
async function onStageMove(e: PointerEvent) {
  if (!touches.has(e.pointerId)) return
  touches.set(e.pointerId, { x: e.clientX, y: e.clientY, t: touches.get(e.pointerId)?.t ?? performance.now() })
  const st = container.value
  if (!st) return
  if (pinch && touches.size >= 2) {
    e.preventDefault()
    if (pinchBusy) return
    pinchBusy = true
    const m = touchMid()
    const rect = st.getBoundingClientRect()
    const inner = innerEl.value
    const s0 = scale.value
    const offL = inner?.offsetLeft ?? 0
    const offT = inner?.offsetTop ?? 0
    // 指の中央にある画像上の点を拡縮後も同じ位置に保つ
    const ix = (m.x - rect.left + st.scrollLeft - offL) / s0
    const iy = (m.y - rect.top + st.scrollTop - offT) / s0
    zoom.value = Math.min(5, Math.max(0.5, (pinch.zoom0 * m.dist) / pinch.dist))
    await nextTick()
    draw()
    const s1 = scale.value
    st.scrollLeft = ix * s1 + (inner?.offsetLeft ?? 0) - (m.x - rect.left)
    st.scrollTop = iy * s1 + (inner?.offsetTop ?? 0) - (m.y - rect.top)
    pinchBusy = false
  } else if (pan && pan.id === e.pointerId && touches.size === 1) {
    st.scrollLeft -= e.clientX - pan.x
    st.scrollTop -= e.clientY - pan.y
    pan = { id: e.pointerId, x: e.clientX, y: e.clientY }
  }
}
function onStageUp(e: PointerEvent) {
  if (!touches.has(e.pointerId)) return
  touches.delete(e.pointerId)
  if (touches.size < 2) pinch = null
  if (pan && pan.id === e.pointerId) pan = null
}
/** Safari のページ拡縮（gesture イベント）と2本指の既定動作を止める */
function onGesture(e: Event) {
  if (!props.readonly) e.preventDefault()
}
function onTouchMove(e: TouchEvent) {
  if (!props.readonly && e.touches.length > 1) e.preventDefault()
}

function onCancel(e: PointerEvent) {
  if (activePointer !== e.pointerId) return
  activePointer = null
  clearHold()
  temp = null
  dragItem = null
  resizeItem = null
  draw()
}

function moveItem(it: AnnotationItem, dx: number, dy: number) {
  if (it.type === 'pen') {
    for (let i = 0; i < it.points.length; i += 2) {
      it.points[i]! += dx
      it.points[i + 1]! += dy
    }
  } else if (it.type === 'text') {
    it.x += dx
    it.y += dy
  } else {
    it.x1 += dx
    it.x2 += dx
    it.y1 += dy
    it.y2 += dy
  }
}

let eraseSnap = false
function eraseAt(x: number, y: number) {
  const tol = ERASE_TOL / scale.value
  const before = items.value.length
  const next = items.value.filter((it) => !hit(it, x, y, tol))
  if (next.length !== before) {
    if (!eraseSnap) {
      snapshot()
      eraseSnap = true
      setTimeout(() => (eraseSnap = false), 600)
    }
    items.value = next
    commit()
  }
}

// ---------- テキスト ----------
const textEdit = ref<{ x: number; y: number; value: string; id: string | null } | null>(null)
const textArea = ref<HTMLTextAreaElement | null>(null)
function openText(x: number, y: number, existing?: AnnotationItem & { type: 'text' }) {
  textEdit.value = { x, y, value: existing?.text ?? '', id: existing?.id ?? null }
  nextTick(() => textArea.value?.focus())
}
function commitText() {
  const t = textEdit.value
  if (!t) return
  textEdit.value = null
  const text = t.value.trim()
  if (t.id) {
    const it = items.value.find((x) => x.id === t.id)
    if (it && it.type === 'text') {
      snapshot()
      if (text) it.text = text
      else items.value = items.value.filter((x) => x.id !== t.id)
      commit()
    }
    return
  }
  if (!text) return
  snapshot()
  const id = uid()
  items.value.push({ id, type: 'text', color: color.value, size: textSize.value, x: t.x, y: t.y, text })
  // 入力直後にドラッグ移動・サイズ変更できるよう、選択状態にしてツールを「選択」に切り替える
  selectedId.value = id
  tool.value = 'select'
  commit()
}
function onDblClick(e: MouseEvent) {
  if (props.readonly) return
  const r = canvas.value!.getBoundingClientRect()
  const x = (e.clientX - r.left) / scale.value
  const y = (e.clientY - r.top) / scale.value
  const it = [...items.value].reverse().find((i) => i.type === 'text' && hit(i, x, y, 6 / scale.value))
  if (it && it.type === 'text') {
    selectedId.value = it.id
    openText(it.x, it.y, it)
  }
}

// ---------- 保存 ----------
let lastSavedSnapshot = ''
async function save() {
  if (!img.value) return
  const c = document.createElement('canvas')
  c.width = W.value
  c.height = H.value
  const ctx = c.getContext('2d')!
  ctx.drawImage(img.value, 0, 0)
  for (const it of items.value) drawItem(ctx, it)
  const blob = await new Promise<Blob>((resolve, reject) => c.toBlob((b) => (b ? resolve(b) : reject(new Error('export'))), 'image/jpeg', 0.86))
  const doc: AnnotationDoc = { version: 1, width: W.value, height: H.value, items: JSON.parse(JSON.stringify(items.value)) }
  lastSavedSnapshot = JSON.stringify(items.value)
  emit('save', doc, blob)
}
/** 保存完了通知。保存後にさらに変更されていた場合は dirty のままにして再保存を予約する */
function markSaved() {
  if (JSON.stringify(items.value) === lastSavedSnapshot) setDirty(false)
  else scheduleSave()
}
defineExpose({ save, markSaved, isDirty: () => dirty.value, zoomBy, fit, zoom })

// ---------- キーボード ----------
function onKey(e: KeyboardEvent) {
  const target = e.target as HTMLElement | null
  if (target && (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA')) return
  if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'z') {
    e.preventDefault()
    e.shiftKey ? redo() : undo()
  } else if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'y') {
    e.preventDefault()
    redo()
  } else if (e.key === 'Delete' || e.key === 'Backspace') {
    if (selectedId.value) {
      e.preventDefault()
      deleteSelected()
    }
  }
}

onMounted(() => {
  loadImage()
  loadItems()
  // ズームで内容が伸縮すると高さ（やスクロールバー分の幅）が変わり ResizeObserver が発火するが、
  // そこで再フィットするとズームがリセットされてしまうため、幅が大きく変わったときだけ再フィットする
  ro = new ResizeObserver(() => {
    const cw = container.value?.clientWidth ?? 0
    const ch = container.value?.clientHeight ?? 0
    if (Math.abs(cw - lastCw) > 24 || (props.fitToContainer && Math.abs(ch - lastCh) > 24)) fit()
  })
  if (container.value) {
    ro.observe(container.value)
    container.value.addEventListener('gesturestart', onGesture)
    container.value.addEventListener('gesturechange', onGesture)
    container.value.addEventListener('touchmove', onTouchMove, { passive: false })
  }
  window.addEventListener('keydown', onKey)
  document.addEventListener('pointerup', onDocPointerEnd)
  document.addEventListener('pointercancel', onDocPointerEnd)
})
onBeforeUnmount(() => {
  ro?.disconnect()
  container.value?.removeEventListener('gesturestart', onGesture)
  container.value?.removeEventListener('gesturechange', onGesture)
  container.value?.removeEventListener('touchmove', onTouchMove)
  window.removeEventListener('keydown', onKey)
  document.removeEventListener('pointerup', onDocPointerEnd)
  document.removeEventListener('pointercancel', onDocPointerEnd)
  if (saveTimer) clearTimeout(saveTimer)
  clearHold()
})
</script>

<template>
  <div class="editor" :class="{ fill: fitToContainer }">
    <Teleport :to="toolbarTarget" :disabled="!toolbarTarget">
      <div v-if="!readonly && compact" class="toolbar vertical compact">
        <!-- 常時表示: ペン・消しゴム・戻す・進む＋展開 -->
        <div class="group tools">
          <button class="tb" :class="{ on: tool === 'pen' }" title="ペン" @click="tool = 'pen'; selectedId = null; draw()">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path :d="iconOf('pen')" /></svg>
          </button>
          <button class="tb" :class="{ on: tool === 'eraser' }" title="消しゴム" @click="tool = 'eraser'; selectedId = null; draw()">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path :d="iconOf('eraser')" /></svg>
          </button>
          <button class="tb" :disabled="!history.length" title="元に戻す" @click="undo">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path :d="ICON_UNDO" /></svg>
          </button>
          <button class="tb" :disabled="!redoStack.length" title="やり直す" @click="redo">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path :d="ICON_REDO" /></svg>
          </button>
          <button class="tb more" :class="{ on: expanded }" :title="expanded ? 'ツールを閉じる' : 'その他のツール'" @click="expanded = !expanded">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="currentColor"><circle cx="5" cy="12" r="2" /><circle cx="12" cy="12" r="2" /><circle cx="19" cy="12" r="2" /></svg>
          </button>
        </div>
        <template v-if="expanded">
          <div class="group tools">
            <button v-for="t in SECONDARY_TOOLS" :key="t.key" class="tb" :class="{ on: tool === t.key }" :title="t.label" @click="tool = t.key; selectedId = null; hover = null; draw()">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path :d="t.icon" /></svg>
              <span class="tb-label">{{ t.label }}</span>
            </button>
          </div>
          <div class="group colors">
            <button v-for="c in COLORS" :key="c" class="sw" :class="{ on: color === c }" :style="{ background: c }" :title="c" @click="color = c"></button>
          </div>
          <div class="group">
            <button v-for="w in WIDTHS" :key="w.key" class="tb sm" :class="{ on: isWidth(w.f) }" @click="widthF = w.f">{{ w.label }}</button>
            <div class="wslider v" title="太さ">
              <input v-model.number="widthF" type="range" :min="WIDTH_MIN" :max="WIDTH_MAX" step="0.2" />
            </div>
            <span class="wval"><i :style="{ width: Math.min(24, 3 + widthF * 3) + 'px', height: Math.min(24, 3 + widthF * 3) + 'px' }"></i>{{ widthF.toFixed(1) }}</span>
          </div>
          <div class="group">
            <button class="tb sm" :disabled="!selectedId" title="選択を削除" @click="deleteSelected">削除</button>
            <button class="tb sm" :disabled="!items.length" title="すべて消す" @click="clearAll">全消去</button>
          </div>
          <div class="group">
            <button class="tb sm" @click="zoomBy(1.25)">＋</button>
            <span class="zoom">{{ Math.round(zoom * 100) }}%</span>
            <button class="tb sm" @click="zoomBy(1 / 1.25)">−</button>
            <button class="tb sm" @click="fit">全体</button>
          </div>
          <label class="chk" title="オンにすると指はスクロール・ペン（Apple Pencil）やマウスだけで描きます">
            <input v-model="penOnly" type="checkbox" /> 指はスクロール
          </label>
        </template>
      </div>
      <div v-else-if="!readonly" class="toolbar" :class="{ vertical: !!toolbarTarget }">
        <!-- ツールアイコン（縦型ではアイコンのみ・常時表示） -->
        <div class="group tools">
          <button v-for="t in TOOLS" :key="t.key" class="tb" :class="{ on: tool === t.key }" :title="t.label" @click="tool = t.key; selectedId = null; hover = null; draw()">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path :d="t.icon" /></svg>
            <span class="tb-label">{{ t.label }}</span>
          </button>
        </div>
        <!-- 詳細設定の展開／閉じる（縦型のみ・既定は閉じる） -->
        <button v-if="toolbarTarget" class="expander" :title="saving ? '保存中…' : dirty ? '自動保存待ち…' : '保存済み'" @click="expanded = !expanded">
          <span class="dot" :class="{ ok: !dirty && !saving }"></span>
          詳細設定
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" :style="{ transform: expanded ? 'rotate(180deg)' : '', marginLeft: 'auto' }"><path d="M6 9l6 6 6-6" /></svg>
        </button>
        <template v-if="!toolbarTarget || expanded">
          <div class="group">
            <button v-for="c in COLORS" :key="c" class="sw" :class="{ on: color === c }" :style="{ background: c }" :title="c" @click="color = c"></button>
          </div>
          <div class="group">
            <button v-for="w in WIDTHS" :key="w.key" class="tb sm" :class="{ on: isWidth(w.f) }" @click="widthF = w.f">{{ w.label }}</button>
            <div class="wslider" title="太さ">
              <input v-model.number="widthF" type="range" :min="WIDTH_MIN" :max="WIDTH_MAX" step="0.2" />
            </div>
            <span class="wval"><i :style="{ width: Math.min(24, 3 + widthF * 3) + 'px', height: Math.min(24, 3 + widthF * 3) + 'px' }"></i>{{ widthF.toFixed(1) }}</span>
          </div>
          <div class="group">
            <button class="tb sm" :disabled="!history.length" title="元に戻す (Ctrl+Z)" @click="undo">↶</button>
            <button class="tb sm" :disabled="!redoStack.length" title="やり直す (Ctrl+Y)" @click="redo">↷</button>
            <button class="tb sm" :disabled="!selectedId" title="選択を削除" @click="deleteSelected">削除</button>
            <button class="tb sm" :disabled="!items.length" title="すべて消す" @click="clearAll">全消去</button>
          </div>
          <div class="group">
            <button class="tb sm" @click="zoomBy(1 / 1.25)">−</button>
            <span class="zoom">{{ Math.round(zoom * 100) }}%</span>
            <button class="tb sm" @click="zoomBy(1.25)">＋</button>
            <button class="tb sm" @click="fit">全体</button>
          </div>
          <label class="chk" title="オンにすると指はスクロール・ペン（Apple Pencil）やマウスだけで描きます">
            <input v-model="penOnly" type="checkbox" /> 指はスクロール
          </label>
          <div class="save-state" :class="{ dirty: dirty || saving }" title="変更は自動で保存されます">
            {{ saving ? '保存中…' : dirty ? '自動保存待ち…' : '保存済み' }}
          </div>
        </template>
      </div>
    </Teleport>

    <div
      ref="container"
      class="stage"
      :class="{ [tool]: true, touchy: !readonly }"
      @contextmenu.prevent
      @pointerdown="onStageDown"
      @pointermove="onStageMove"
      @pointerup="onStageUp"
      @pointercancel="onStageUp"
    >
      <div v-if="!loaded" class="loading">画像を読み込み中…</div>
      <div v-else ref="innerEl" class="inner" :style="{ width: Math.round(W * scale) + 'px', height: Math.round(H * scale) + 'px' }">
        <img :src="imageUrl" class="base" alt="" draggable="false" />
        <canvas
          ref="canvas"
          class="overlay"
          :style="{ touchAction: readonly ? 'auto' : 'none' }"
          @pointerdown="onDown"
          @pointermove="onMove"
          @pointerup="onUp"
          @pointercancel="onCancel"
          @pointerleave="hover = null; draw()"
          @dblclick="onDblClick"
          @contextmenu.prevent
        ></canvas>
        <div v-if="textEdit" class="text-edit" :style="{ left: textEdit.x * scale + 'px', top: textEdit.y * scale + 'px' }">
          <textarea
            ref="textArea"
            v-model="textEdit.value"
            rows="2"
            :style="{ color, fontSize: Math.max(12, textSize * scale) + 'px' }"
            placeholder="文字を入力（Enterで確定）"
            @keydown.enter.exact.prevent="commitText"
            @keydown.esc="textEdit = null"
            @blur="commitText"
          ></textarea>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.editor {
  display: flex;
  flex-direction: column;
  gap: 8px;
  min-width: 0;
}
.toolbar {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  padding: 8px 10px;
  background: #fff;
  border: 1px solid var(--line);
  border-radius: 12px;
  position: sticky;
  top: 0;
  z-index: 3;
}
.group {
  display: flex;
  align-items: center;
  gap: 3px;
  padding-right: 8px;
  border-right: 1px solid #eceef0;
}
.group:last-of-type {
  border-right: none;
}
.tb {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 1px;
  min-width: 40px;
  padding: 4px 6px;
  border: 1px solid transparent;
  border-radius: 8px;
  background: transparent;
  color: var(--mut);
  font-size: 10px;
  cursor: pointer;
  touch-action: manipulation;
}
.tb.sm {
  flex-direction: row;
  min-width: 30px;
  height: 30px;
  font-size: 12px;
  font-weight: 600;
  padding: 0 8px;
}
.tb:hover {
  background: #f3f4f7;
}
.tb.on {
  background: #1c2024;
  border-color: #1c2024;
  color: #fff;
}
.tb:disabled {
  opacity: 0.35;
  cursor: default;
}
.sw {
  width: 22px;
  height: 22px;
  border-radius: 50%;
  border: 2px solid #fff;
  box-shadow: 0 0 0 1px #d8dce1;
  cursor: pointer;
  margin: 0 2px;
}
.sw.on {
  box-shadow: 0 0 0 2px #1c2024;
}
/* 太さスライダー（横型ツールバー）。縦型（compact）では回転して縦に置く */
.wslider {
  display: flex;
  align-items: center;
  padding: 0 4px;
}
.wslider input[type='range'] {
  width: 90px;
  accent-color: #1c2024;
  touch-action: pan-x;
}
.wslider.v {
  width: 44px;
  height: 104px;
  justify-content: center;
  overflow: hidden;
}
.wslider.v input[type='range'] {
  width: 96px;
  transform: rotate(-90deg);
  touch-action: none;
}
.wval {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: 10.5px;
  color: var(--mut);
  font-variant-numeric: tabular-nums;
}
.wval i {
  display: inline-block;
  border-radius: 50%;
  background: currentColor;
  color: var(--ink);
}
.toolbar.vertical.compact .wval {
  flex-direction: column;
  color: #cfd3d9;
}
.toolbar.vertical.compact .wval i {
  color: #fff;
}
.zoom {
  font-size: 11px;
  color: var(--mut);
  width: 40px;
  text-align: center;
  font-variant-numeric: tabular-nums;
}
.chk {
  font-size: 11.5px;
  color: var(--mut);
  display: inline-flex;
  align-items: center;
  gap: 4px;
  white-space: nowrap;
}
.save-state {
  margin-left: auto;
  padding: 7px 12px;
  border-radius: 9px;
  background: #eef5ef;
  color: #2f7a4f;
  font-size: 11.5px;
  font-weight: 700;
  white-space: nowrap;
}
.save-state.dirty {
  background: #fff6e0;
  color: #8a5a00;
}
.stage {
  position: relative;
  overflow: auto;
  background: #e9ebef;
  border: 1px solid var(--line);
  border-radius: 12px;
  max-height: calc(100vh - 150px);
  min-height: 320px;
  -webkit-overflow-scrolling: touch;
  /* 長押しでのコピー・調べるメニュー（iOS の callout）を出さない */
  -webkit-touch-callout: none;
  -webkit-user-select: none;
  user-select: none;
}
/* 縦型ツールバー（左レールにテレポートしたとき） */
.toolbar.vertical {
  position: static;
  flex-direction: column;
  align-items: stretch;
  border: none;
  background: transparent;
  padding: 0;
  gap: 0;
}
.toolbar.vertical .group {
  border-right: none;
  border-bottom: 1px solid #eceef0;
  padding: 7px 0;
  flex-wrap: wrap;
  justify-content: flex-start;
}
.toolbar.vertical .group.tools {
  display: grid;
  grid-template-columns: repeat(5, 1fr);
  gap: 2px;
  border-bottom: none;
}
.toolbar.vertical .tb {
  flex-direction: row;
  justify-content: flex-start;
  gap: 6px;
  min-width: 0;
  padding: 6px 8px;
  font-size: 11.5px;
}
/* 縦型はアイコンのみ表示 */
.toolbar.vertical .tools .tb {
  justify-content: center;
  padding: 8px 0;
}
.toolbar.vertical .tools .tb-label {
  display: none;
}
.expander {
  display: flex;
  align-items: center;
  gap: 6px;
  width: 100%;
  padding: 7px 8px;
  margin-top: 4px;
  border: 1px solid #e3e6ea;
  border-radius: 9px;
  background: #f8f9fb;
  font-size: 11.5px;
  font-weight: 600;
  color: var(--mut);
  cursor: pointer;
}
.expander:hover {
  background: #f1f2f4;
}
.dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: #d98a1a;
  flex-shrink: 0;
}
.dot.ok {
  background: #2f9e5b;
}
/* タブレット・スマホ: レールが全幅になるため、縦型ツールバーを横並びに戻す */
@media (max-width: 1100px) {
  .toolbar.vertical {
    flex-direction: row;
    flex-wrap: wrap;
    align-items: center;
    gap: 6px;
  }
  .toolbar.vertical .group {
    border-bottom: none;
    border-right: 1px solid #eceef0;
    padding: 0 8px 0 0;
  }
  .toolbar.vertical .group.tools {
    display: flex;
    flex-wrap: wrap;
    gap: 2px;
  }
  .toolbar.vertical .tools .tb {
    padding: 8px 9px;
  }
  .toolbar.vertical .expander {
    width: auto;
    margin-top: 0;
    gap: 5px;
  }
  .toolbar.vertical .save-state {
    margin: 0 0 0 auto;
  }
}
.toolbar.vertical .chk {
  padding: 8px 0 2px;
}
.toolbar.vertical .save-state {
  margin: 8px 0 0;
  text-align: center;
}
/* 全画面添削のサイドメニュー（compact）: 縦一列・アイコンのみ */
.toolbar.vertical.compact {
  flex-direction: column;
  flex-wrap: nowrap;
  align-items: stretch;
  gap: 0;
  padding: 0;
}
.toolbar.vertical.compact .group {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 4px;
  padding: 6px 0;
  border: none;
  border-bottom: 1px solid rgba(255, 255, 255, 0.12);
}
.toolbar.vertical.compact .group.tools {
  display: flex;
  flex-direction: column;
}
.toolbar.vertical.compact .tb {
  width: 44px;
  height: 44px;
  min-width: 0;
  padding: 0;
  justify-content: center;
  border-radius: 10px;
  color: #cfd3d9;
  font-size: 12px;
  font-weight: 700;
}
.toolbar.vertical.compact .tb:hover {
  background: rgba(255, 255, 255, 0.1);
}
.toolbar.vertical.compact .tb.on {
  background: #fff;
  border-color: #fff;
  color: #1c2024;
}
.toolbar.vertical.compact .tb.more.on {
  background: rgba(255, 255, 255, 0.22);
  color: #fff;
}
.toolbar.vertical.compact .tb.sm {
  height: 36px;
}
.toolbar.vertical.compact .tools .tb-label {
  display: none;
}
.toolbar.vertical.compact .sw {
  width: 24px;
  height: 24px;
  margin: 2px 0;
  border-color: #2a2f36;
}
.toolbar.vertical.compact .sw.on {
  box-shadow: 0 0 0 2px #fff;
}
.toolbar.vertical.compact .zoom {
  color: #cfd3d9;
  width: auto;
}
.toolbar.vertical.compact .chk {
  color: #cfd3d9;
  flex-direction: column;
  font-size: 10px;
  padding: 6px 2px;
  white-space: normal;
  text-align: center;
}
/* 編集中はタッチをブラウザに任せない（ピンチは画像だけを拡縮、1本指スクロールは自前） */
.stage.touchy {
  touch-action: none;
}
/* 表示領域の高さに合わせる（全画面添削） */
.editor.fill {
  width: 100%;
  height: 100%;
  gap: 0;
}
.editor.fill .stage {
  flex: 1;
  min-height: 0;
  max-height: none;
  border: none;
  border-radius: 0;
}
.stage.pen .overlay,
.stage.circle .overlay,
.stage.cross .overlay,
.stage.triangle .overlay,
.stage.check .overlay,
.stage.line .overlay,
.stage.rect .overlay {
  cursor: crosshair;
}
.stage.text .overlay {
  cursor: text;
}
.stage.eraser .overlay {
  cursor: cell;
}
.loading {
  padding: 40px;
  text-align: center;
  font-size: 12.5px;
  color: var(--faint);
}
.inner {
  position: relative;
  margin: 0 auto;
}
.base {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  display: block;
  user-select: none;
  -webkit-user-select: none;
  -webkit-touch-callout: none;
  pointer-events: none;
}
.overlay {
  position: absolute;
  left: 0;
  top: 0;
  user-select: none;
  -webkit-user-select: none;
  -webkit-touch-callout: none;
}
.text-edit {
  position: absolute;
  z-index: 2;
}
.text-edit textarea {
  min-width: 180px;
  padding: 4px 6px;
  border: 1px dashed #3b50cc;
  border-radius: 6px;
  background: rgba(255, 255, 255, 0.92);
  font-weight: 700;
  font-family: inherit;
  line-height: 1.3;
  resize: both;
  outline: none;
}
</style>
