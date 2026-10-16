// 間違いノートのページを canvas に描く（全画面の閲覧・PDF 出力で共通。印刷したものと同じ見た目になる）。
// 1 ページには複数の問題を置ける（lib/mistake.ts の paginate が割り付ける）。問題と問題の間には罫線を引く。
import { drawStroke } from '@/lib/inkRender'
import { ENTRY_GAP, ENTRY_HEAD, GUTTER, HEADER_GAP, HEADER_H, LINE_HEIGHT, NOTE_H, NOTE_W, OUTER, PAGE_H, PAGE_TOP, PAGE_W, contentBox, fmtDate, loadImageEl, type BinderPage } from '@/lib/mistake'
import type { MistakeLayout, MistakeNoteDetail } from '@/types'

const FONT = "'Zen Kaku Gothic New', 'Hiragino Sans', 'Yu Gothic', 'Meiryo', sans-serif"
const INK = '#1c2024'

/** 文字を枠の幅で折り返す（日本語は 1 文字ずつ、英単語は単語の途中で切らない） */
export function wrapText(ctx: CanvasRenderingContext2D, text: string, maxW: number): string[] {
  const lines: string[] = []
  for (const para of text.split('\n')) {
    let line = ''
    let lastBreak = -1 // 直近の空白の位置（英単語の途中で切らないため）
    for (const ch of Array.from(para)) {
      const next = line + ch
      if (ctx.measureText(next).width <= maxW || line === '') {
        line = next
        if (ch === ' ') lastBreak = line.length - 1
        continue
      }
      if (lastBreak > 0 && /[A-Za-z0-9]/.test(ch) && /[A-Za-z0-9]/.test(line[line.length - 1] ?? '')) {
        lines.push(line.slice(0, lastBreak))
        line = line.slice(lastBreak + 1) + ch
      } else {
        lines.push(line)
        line = ch
      }
      lastBreak = -1
    }
    lines.push(line)
  }
  return lines
}

/** 文字が幅に収まらないときは「…」で切る */
function fitText(ctx: CanvasRenderingContext2D, text: string, maxW: number): string {
  if (ctx.measureText(text).width <= maxW) return text
  let s = text
  while (s.length > 1 && ctx.measureText(s + '…').width > maxW) s = s.slice(0, -1)
  return s + '…'
}

/**
 * 置いたもの（画像・文字・手書き）を ctx の (ox, oy) に描く。
* 置いたもの全体の左上を (ox, oy) に合わせ、scale 倍にして clipW × clipH に切り抜く。
 * problemOnly のときは対象の問題の写真だけを描く（説明用の画像・文字・手書きは描かない。位置・大きさはそのまま）。
 */
export async function drawNoteContent(ctx: CanvasRenderingContext2D, layout: MistakeLayout, ox: number, oy: number, scale: number, clipW = NOTE_W, clipH = NOTE_H, problemOnly = false) {
  const box = contentBox(layout)
  ctx.save()
  ctx.translate(ox, oy)
  ctx.beginPath()
  ctx.rect(0, 0, clipW, clipH)
  ctx.clip()
  ctx.scale(scale, scale)
  if (box) ctx.translate(-box.x, -box.y)

  // 画像（配置の順に）
  for (const it of layout.items) {
    if (it.type !== 'image' || !it.imageId || (problemOnly && it.role !== 'problem')) continue
    try {
      const img = await loadImageEl(it.imageId)
      const c = it.crop
      if (c) ctx.drawImage(img, c.x * img.naturalWidth, c.y * img.naturalHeight, c.w * img.naturalWidth, c.h * img.naturalHeight, it.x, it.y, it.w, it.h)
      else ctx.drawImage(img, it.x, it.y, it.w, it.h)
      ctx.strokeStyle = 'rgba(0,0,0,0.12)'
      ctx.lineWidth = 1
      ctx.strokeRect(it.x + 0.5, it.y + 0.5, it.w - 1, it.h - 1)
    } catch {
      ctx.fillStyle = '#f1f2f4'
      ctx.fillRect(it.x, it.y, it.w, it.h)
    }
  }
  // 文字
  for (const it of layout.items) {
    if (it.type !== 'text' || !it.text || problemOnly) continue
    const fs = it.fontSize ?? 30
    ctx.font = `400 ${fs}px ${FONT}`
    ctx.fillStyle = INK
    ctx.textBaseline = 'top'
    ctx.textAlign = 'left'
    const lh = fs * LINE_HEIGHT
    const pad = 6
    const lines = wrapText(ctx, it.text, it.w - pad * 2)
    ctx.save()
    ctx.beginPath()
    ctx.rect(it.x, it.y, it.w, it.h)
    ctx.clip()
    lines.forEach((ln, i) => {
      const y = it.y + pad + i * lh + (lh - fs) / 2
      if (y > it.y + it.h) return
      ctx.fillText(ln, it.x + pad, y)
    })
    ctx.restore()
  }
  // ペンの線（種類ごとの見た目は lib/inkRender.ts）
  if (!problemOnly) for (const st of layout.ink) drawStroke(ctx, st)
  ctx.restore()
}

export interface PageOptions {
  /** 通し番号（1 始まり）。奇数ページはとじ側が左、偶数ページは右 */
  pageNo: number
  /** 解像度の倍率（画面表示は 1、PDF は 1.4 程度） */
  scale?: number
  /** パンチ穴の目印を描くか */
  punchMarks?: boolean
  /** 対象の問題の写真だけを描く（説明用の画像・文字・手書きは描かない） */
  problemOnly?: boolean
}

/** とじ側（パンチ穴）が左のページか */
export function gutterLeft(pageNo: number): boolean {
  return pageNo % 2 === 1
}

function newPage(scale: number): { c: HTMLCanvasElement; x: CanvasRenderingContext2D } {
  const c = document.createElement('canvas')
  c.width = Math.round(PAGE_W * scale)
  c.height = Math.round(PAGE_H * scale)
  const x = c.getContext('2d')!
  x.scale(scale, scale)
  x.fillStyle = '#fff'
  x.fillRect(0, 0, PAGE_W, PAGE_H)
  return { c, x }
}

function drawPunchMarks(x: CanvasRenderingContext2D, left: boolean) {
  const cx = left ? GUTTER / 2 : PAGE_W - GUTTER / 2
  x.strokeStyle = '#d8dce1'
  x.lineWidth = 1.5
  for (const dy of [-240, 240]) {
    x.beginPath()
    x.arc(cx, PAGE_H / 2 + dy, 14, 0, Math.PI * 2)
    x.stroke()
  }
}

function drawFooter(x: CanvasRenderingContext2D, pageNo: number, left: boolean) {
  x.fillStyle = '#9aa1ab'
  x.font = `500 18px ${FONT}`
  x.textBaseline = 'middle'
  x.textAlign = 'center'
  const cx = (left ? GUTTER : OUTER) + (PAGE_W - GUTTER - OUTER) / 2
  x.fillText(String(pageNo), cx, PAGE_H - 36)
}

/**
 * バインダーの 1 ページ（複数の問題）。見出し（科目・分野）、問題ごとの見出し行と描画部分、問題の間の罫線、ページ番号、パンチ穴の目印。
 * details には各問題の詳細（layout）を入れておく（無い問題は枠だけ描く）。
 */
export async function renderBinderPage(page: BinderPage, details: Map<number, MistakeNoteDetail>, opts: PageOptions): Promise<HTMLCanvasElement> {
  if (document.fonts?.ready) await document.fonts.ready
  const scale = opts.scale ?? 1
  const left = gutterLeft(opts.pageNo)
  const { c, x } = newPage(scale)
  const ox = left ? GUTTER : OUTER
  const oy = PAGE_TOP
  const color = page.color ?? INK

  // ---- ページの見出し: 科目（科目の色）と、このページにある分野 ----
  x.textBaseline = 'middle'
  x.textAlign = 'left'
  x.fillStyle = color
  x.font = `700 24px ${FONT}`
  x.fillText(page.subjectName, ox, oy + 18)
  const sw = x.measureText(page.subjectName).width
  const fields = Array.from(new Set(page.entries.map((e) => e.note.field).filter((f): f is string => !!f)))
  if (fields.length) {
    x.fillStyle = '#6b7280'
    x.font = `500 17px ${FONT}`
    x.fillText(fitText(x, fields.join('　'), NOTE_W - sw - 30), ox + sw + 22, oy + 19)
  }
  x.strokeStyle = color
  x.lineWidth = 2
  x.beginPath()
  x.moveTo(ox, oy + HEADER_H - 12)
  x.lineTo(ox + NOTE_W, oy + HEADER_H - 12)
  x.stroke()

  // ---- 問題ごと ----
  const bodyTop = oy + HEADER_H + HEADER_GAP
  for (let i = 0; i < page.entries.length; i++) {
    const e = page.entries[i]!
    const n = e.note
    const top = bodyTop + e.y
    // 見出し行: 分野 ／ 小分類 タイトル（左）、出典 日付（右）
    x.textBaseline = 'middle'
    x.textAlign = 'right'
    x.fillStyle = '#6b7280'
    x.font = `400 15px ${FONT}`
    const right = [n.source, fmtDate(n.notedOn)].filter(Boolean).join('　')
    x.fillText(right, ox + NOTE_W, top + ENTRY_HEAD / 2)
    const rw = right ? x.measureText(right).width + 24 : 0
    x.textAlign = 'left'
    let lx = ox
    if (n.field) {
      x.fillStyle = color
      x.font = `700 17px ${FONT}`
      const f = fitText(x, n.field, (NOTE_W - rw) * 0.5)
      x.fillText(f, lx, top + ENTRY_HEAD / 2)
      lx += x.measureText(f).width + 16
    }
    const title = [n.studyItemName, n.title].filter(Boolean).join('　')
    if (title) {
      x.fillStyle = INK
      x.font = `700 19px ${FONT}`
      x.fillText(fitText(x, title, NOTE_W - rw - (lx - ox)), lx, top + ENTRY_HEAD / 2)
    }
    // 描画部分
    const d = details.get(n.id)
    if (d) {
      await drawNoteContent(x, d.layout, ox, top + ENTRY_HEAD, e.scale, NOTE_W, e.contentH + 2, opts.problemOnly)
    } else {
      x.fillStyle = '#f6f7f9'
      x.fillRect(ox, top + ENTRY_HEAD, NOTE_W, e.contentH)
    }
    // 問題の間の罫線
    if (i < page.entries.length - 1) {
      const ly = Math.round(top + e.h + ENTRY_GAP / 2) + 0.5
      x.strokeStyle = '#b8bec7'
      x.lineWidth = 1.5
      x.beginPath()
      x.moveTo(ox, ly)
      x.lineTo(ox + NOTE_W, ly)
      x.stroke()
    }
  }

  if (opts.punchMarks ?? true) drawPunchMarks(x, left)
  drawFooter(x, opts.pageNo, left)
  return c
}

export interface IndexEntry {
  subjectName: string
  color: string | null
  field: string
  title: string
  pageNo: number
}

/** 目次ページ（科目 → 分野 → 問題とページ番号）。入りきらなければ複数ページ */
export function renderIndexPages(entries: IndexEntry[], firstPageNo: number, title = '間違いノート', scale = 1): HTMLCanvasElement[] {
  const pages: HTMLCanvasElement[] = []
  const rowH = 38
  let pageNo = firstPageNo
  let i = 0
  while (i < entries.length || pages.length === 0) {
    const left = gutterLeft(pageNo)
    const { c, x } = newPage(scale)
    const ox = left ? GUTTER : OUTER
    let y = PAGE_TOP
    x.textBaseline = 'middle'
    x.textAlign = 'left'
    x.fillStyle = INK
    x.font = `700 34px ${FONT}`
    x.fillText(pages.length === 0 ? `${title}　目次` : `${title}　目次（続き）`, ox, y + 22)
    y += 70
    x.strokeStyle = '#1c2024'
    x.lineWidth = 2
    x.beginPath()
    x.moveTo(ox, y)
    x.lineTo(ox + NOTE_W, y)
    x.stroke()
    y += 24
    let lastGroup = ''
    const bottom = PAGE_H - 90
    while (i < entries.length && y + rowH <= bottom) {
      const e = entries[i]!
      const group = `${e.subjectName} ／ ${e.field}`
      if (group !== lastGroup) {
        if (y + rowH * 2 > bottom) break
        y += 10
        x.fillStyle = e.color ?? INK
        x.font = `700 24px ${FONT}`
        x.textAlign = 'left'
        x.fillText(group, ox, y + rowH / 2)
        y += rowH
        lastGroup = group
      }
      x.fillStyle = INK
      x.font = `400 21px ${FONT}`
      const label = e.title || '（無題）'
      const pn = `p.${e.pageNo}`
      x.textAlign = 'right'
      x.fillText(pn, ox + NOTE_W - 4, y + rowH / 2)
      const pnW = x.measureText(pn).width
      x.textAlign = 'left'
      const lx = ox + 36
      const maxW = NOTE_W - 36 - pnW - 24
      const t = fitText(x, label, maxW)
      x.fillText(t, lx, y + rowH / 2)
      const tw = x.measureText(t).width
      x.strokeStyle = '#cfd4db'
      x.lineWidth = 1
      x.setLineDash([2, 5])
      x.beginPath()
      x.moveTo(lx + tw + 10, y + rowH / 2 + 8)
      x.lineTo(ox + NOTE_W - pnW - 14, y + rowH / 2 + 8)
      x.stroke()
      x.setLineDash([])
      y += rowH
      i++
    }
    drawPunchMarks(x, left)
    drawFooter(x, pageNo, left)
    pages.push(c)
    pageNo++
    if (i >= entries.length) break
  }
  return pages
}

/** canvas を JPEG Blob に */
export function canvasToJpeg(c: HTMLCanvasElement, quality = 0.86): Promise<Blob> {
  return new Promise((resolve, reject) => c.toBlob((b) => (b ? resolve(b) : reject(new Error('画像の生成に失敗しました'))), 'image/jpeg', quality))
}
