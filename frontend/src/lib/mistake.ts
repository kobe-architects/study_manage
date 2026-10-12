// 間違いノート共通: ページの寸法・既定の配置・画像の読み込み・並び
import { mistakeApi } from '@/api/mistake'
import type { MistakeLayout, MistakeLayoutItem, MistakeNoteSummary } from '@/types'

/**
 * ページの寸法（px、6px = 1mm）。
 * 印刷は A4（1260×1782）で、とじ側 25mm・外側 10mm・上下 12mm の余白を取り、
 * 残りの 1050×1638 に見出し（56）＋間（12）＋ノート本体（1570）を置く。
 * 編集画面と画面表示はノート本体（NOTE_W × NOTE_H）の座標で扱う。
 */
export const NOTE_W = 1050
export const NOTE_H = 1570
export const PAGE_W = 1260
export const PAGE_H = 1782
export const GUTTER = 150 // とじ側（パンチ穴）の余白 25mm
export const OUTER = 60 // 外側の余白 10mm
export const PAGE_TOP = 72
export const HEADER_H = 56
export const HEADER_GAP = 12

/** ペンの色・太さ */
export const PEN_COLORS = ['#1c2024', '#e0533d', '#3b50cc', '#2e9d62']
export const PEN_WIDTHS: { label: string; width: number }[] = [
  { label: '細', width: 3 },
  { label: '中', width: 5 },
  { label: '太', width: 9 },
]
/** 文字の大きさ（ページ座標の px） */
export const FONT_SIZES: { label: string; size: number }[] = [
  { label: '小', size: 24 },
  { label: '中', size: 30 },
  { label: '大', size: 38 },
]
export const DEFAULT_FONT = 30
export const LINE_HEIGHT = 1.5

let seq = 0
export function newItemId(prefix = 'i'): string {
  seq += 1
  return `${prefix}${Date.now().toString(36)}${seq}`
}

export function ensureLayout(l?: Partial<MistakeLayout> | null): MistakeLayout {
  return { items: Array.isArray(l?.items) ? l!.items! : [], ink: Array.isArray(l?.ink) ? l!.ink! : [] }
}

/** 対象の問題の写真の既定の大きさ（幅いっぱい・高さは最大 720） */
export function fitProblem(w: number, h: number): { x: number; y: number; w: number; h: number } {
  const maxH = 720
  let fw = NOTE_W
  let fh = h > 0 && w > 0 ? Math.round((NOTE_W * h) / w) : 600
  if (fh > maxH) {
    fh = maxH
    fw = Math.round((maxH * w) / h)
  }
  return { x: Math.round((NOTE_W - fw) / 2), y: 0, w: fw, h: fh }
}

/** 説明用の画像の既定の大きさ（幅 420） */
export function fitExtra(w: number, h: number, below: number): { x: number; y: number; w: number; h: number } {
  const fw = 420
  const fh = h > 0 && w > 0 ? Math.round((fw * h) / w) : 300
  return { x: NOTE_W - fw - 20, y: Math.min(NOTE_H - fh - 20, below + 40), w: fw, h: fh }
}

/** 文字の枠の既定（問題の写真の下、ページの下端まで） */
export function defaultTextItem(below: number): MistakeLayoutItem {
  const y = below + 28
  return { id: newItemId('t'), type: 'text', text: '', fontSize: DEFAULT_FONT, x: 0, y, w: NOTE_W, h: Math.max(320, NOTE_H - y) }
}

/** 配置の一番下（文字の枠を置く位置の目安） */
export function bottomOf(items: MistakeLayoutItem[]): number {
  return items.reduce((m, it) => Math.max(m, it.y + it.h), 0)
}

/**
 * 置いたもの（画像・文字・手書き）全体を囲む矩形。何も無ければ null。
 * 編集画面はページの外にも自由に置けるので、印刷・閲覧ではこの矩形をページに収めて描く。
 */
export function contentBox(layout: MistakeLayout): { x: number; y: number; w: number; h: number } | null {
  let x1 = Infinity
  let y1 = Infinity
  let x2 = -Infinity
  let y2 = -Infinity
  for (const it of layout.items) {
    if (it.type === 'text' && !(it.text ?? '').trim()) continue
    x1 = Math.min(x1, it.x)
    y1 = Math.min(y1, it.y)
    x2 = Math.max(x2, it.x + it.w)
    y2 = Math.max(y2, it.y + it.h)
  }
  for (const st of layout.ink) {
    const r = st.width / 2
    for (let i = 0; i + 1 < st.points.length; i += 2) {
      x1 = Math.min(x1, st.points[i]! - r)
      y1 = Math.min(y1, st.points[i + 1]! - r)
      x2 = Math.max(x2, st.points[i]! + r)
      y2 = Math.max(y2, st.points[i + 1]! + r)
    }
  }
  if (!Number.isFinite(x1) || !Number.isFinite(y1)) return null
  return { x: x1, y: y1, w: Math.max(1, x2 - x1), h: Math.max(1, y2 - y1) }
}

/**
 * 印刷・閲覧で使う変換: 置いたもの全体の左上をページの左上に合わせ、ページより大きければ収まるように縮小する
 * （ページより小さいときは等倍のまま）。
 */
export function fitToPage(layout: MistakeLayout): { scale: number; dx: number; dy: number } {
  const b = contentBox(layout)
  if (!b) return { scale: 1, dx: 0, dy: 0 }
  const scale = Math.min(1, NOTE_W / b.w, NOTE_H / b.h)
  return { scale, dx: -b.x, dy: -b.y }
}

/**
 * 撮影した写真・貼り付けた画像を、向きを直して長辺 maxSide px の JPEG にする（アップロード容量を抑える）。
 */
export async function fileToJpeg(file: Blob, maxSide = 1800, quality = 0.88): Promise<{ blob: Blob; width: number; height: number }> {
  let bmp: ImageBitmap | HTMLImageElement
  try {
    bmp = await createImageBitmap(file, { imageOrientation: 'from-image' })
  } catch {
    bmp = await loadFromBlob(file)
  }
  const w = 'width' in bmp ? bmp.width : 0
  const h = 'height' in bmp ? bmp.height : 0
  const scale = Math.min(1, maxSide / Math.max(w, h))
  const c = document.createElement('canvas')
  c.width = Math.max(1, Math.round(w * scale))
  c.height = Math.max(1, Math.round(h * scale))
  const ctx = c.getContext('2d')!
  ctx.fillStyle = '#fff'
  ctx.fillRect(0, 0, c.width, c.height)
  ctx.drawImage(bmp, 0, 0, c.width, c.height)
  if ('close' in bmp) bmp.close()
  const blob = await new Promise<Blob>((resolve, reject) => c.toBlob((b) => (b ? resolve(b) : reject(new Error('画像の変換に失敗しました'))), 'image/jpeg', quality))
  return { blob, width: c.width, height: c.height }
}

function loadFromBlob(blob: Blob): Promise<HTMLImageElement> {
  return new Promise((resolve, reject) => {
    const url = URL.createObjectURL(blob)
    const img = new Image()
    img.onload = () => {
      URL.revokeObjectURL(url)
      resolve(img)
    }
    img.onerror = () => {
      URL.revokeObjectURL(url)
      reject(new Error('画像を読み込めません'))
    }
    img.src = url
  })
}

// ---- 認証つき画像の object URL（画面表示・描画で共有。アプリを閉じるまで保持） ----
const urlCache = new Map<string, Promise<string>>()

export function imageObjectUrl(imageId: number, thumb = false): Promise<string> {
  const key = `${imageId}:${thumb ? 't' : 'f'}`
  let p = urlCache.get(key)
  if (!p) {
    p = mistakeApi.imageBlob(imageId, thumb).then((b) => URL.createObjectURL(b))
    p.catch(() => urlCache.delete(key))
    urlCache.set(key, p)
  }
  return p
}

/** 画像の差し替え・削除のときにキャッシュを捨てる */
export function forgetImageUrl(imageId: number) {
  for (const t of ['t', 'f']) {
    const key = `${imageId}:${t}`
    const p = urlCache.get(key)
    if (p) {
      p.then((u) => URL.revokeObjectURL(u)).catch(() => {})
      urlCache.delete(key)
    }
  }
}

/** ローカルで作った画像（アップロード直後の表示用）を登録する */
export function registerImageUrl(imageId: number, blob: Blob) {
  const url = URL.createObjectURL(blob)
  urlCache.set(`${imageId}:f`, Promise.resolve(url))
  urlCache.set(`${imageId}:t`, Promise.resolve(url))
}

export async function loadImageEl(imageId: number): Promise<HTMLImageElement> {
  const url = await imageObjectUrl(imageId)
  return new Promise((resolve, reject) => {
    const img = new Image()
    img.onload = () => resolve(img)
    img.onerror = () => reject(new Error('画像を読み込めません'))
    img.src = url
  })
}

/** 科目 → 分野（大分類 ／ 中分類）のグループ（一覧・インデックス用）。順番は API の並び（バインダーのページ順）のまま */
export interface MistakeGroup {
  key: string
  subjectId: number | null
  subjectName: string
  color: string | null
  field: string
  notes: MistakeNoteSummary[]
}

/** グループのキー（科目・大分類・中分類） */
export function groupKeyOf(n: { subjectId: number | null; majorId: number | null; midId: number | null }): string {
  return `${n.subjectId ?? 0}|${n.majorId ?? 0}|${n.midId ?? 0}`
}

export function groupNotes(notes: MistakeNoteSummary[]): MistakeGroup[] {
  const out: MistakeGroup[] = []
  for (const n of notes) {
    const key = groupKeyOf(n)
    let g = out.find((x) => x.key === key)
    if (!g) {
      g = { key, subjectId: n.subjectId, subjectName: n.subjectName ?? '科目なし', color: n.colorSoft, field: n.field ?? '', notes: [] }
      out.push(g)
    }
    g.notes.push(n)
  }
  return out
}

export function fmtDate(iso: string): string {
  const [y, m, d] = iso.split('-').map(Number)
  return `${y}/${m}/${d}`
}
