/**
 * 問題 PDF に差し込む表紙を canvas で描画して JPEG にする（サーバーの FPDF に日本語フォントがないため画像で組み込む）。
 * A4 縦・150dpi 相当（1240×1754px）。
 */
const W = 1240
const H = 1754
const FONT = '"Hiragino Sans", "Hiragino Kaku Gothic ProN", "Yu Gothic", "Noto Sans JP", "Meiryo", sans-serif'

function canvas(): { c: HTMLCanvasElement; ctx: CanvasRenderingContext2D } {
  const c = document.createElement('canvas')
  c.width = W
  c.height = H
  const ctx = c.getContext('2d')!
  ctx.fillStyle = '#fff'
  ctx.fillRect(0, 0, W, H)
  return { c, ctx }
}

function toJpeg(c: HTMLCanvasElement): Promise<Blob> {
  return new Promise((resolve, reject) => c.toBlob((b) => (b ? resolve(b) : reject(new Error('cover'))), 'image/jpeg', 0.9))
}

/** 幅に収まるフォントサイズを求める（最大 max、最小 min） */
function fitFont(ctx: CanvasRenderingContext2D, text: string, maxWidth: number, max: number, min: number, weight = '700'): number {
  let size = max
  while (size > min) {
    ctx.font = `${weight} ${size}px ${FONT}`
    if (ctx.measureText(text).width <= maxWidth) break
    size -= 4
  }
  return size
}

/** 長い文字列を幅で折り返す（日本語は文字単位） */
function wrap(ctx: CanvasRenderingContext2D, text: string, maxWidth: number): string[] {
  const lines: string[] = []
  let cur = ''
  for (const ch of text) {
    const next = cur + ch
    if (ctx.measureText(next).width > maxWidth && cur) {
      lines.push(cur)
      cur = ch
    } else cur = next
  }
  if (cur) lines.push(cur)
  return lines
}

/** 背景色の明るさに応じて文字色を白／黒にする */
export function textOn(bg: string): string {
  const m = /^#?([0-9a-f]{6})$/i.exec(bg.trim())
  if (!m) return '#fff'
  const n = parseInt(m[1]!, 16)
  const lum = (0.2126 * ((n >> 16) & 255) + 0.7152 * ((n >> 8) & 255) + 0.0722 * (n & 255)) / 255
  return lum > 0.6 ? '#1c2024' : '#fff'
}

/** 章の表紙: 中央に章名を大きく。上に教材名を小さく */
export async function renderChapterCover(chapter: string, bookTitle: string): Promise<Blob> {
  const { c, ctx } = canvas()
  ctx.textAlign = 'center'
  ctx.textBaseline = 'middle'
  ctx.fillStyle = '#6b7280'
  ctx.font = `500 34px ${FONT}`
  ctx.fillText(bookTitle, W / 2, 200)
  ctx.fillStyle = '#111'
  const size = fitFont(ctx, chapter, W - 200, 120, 56)
  ctx.font = `700 ${size}px ${FONT}`
  const lines = wrap(ctx, chapter, W - 200)
  const lh = size * 1.3
  const y0 = H / 2 - ((lines.length - 1) * lh) / 2
  lines.forEach((l, i) => ctx.fillText(l, W / 2, y0 + i * lh))
  // 章名の下に細い罫線
  ctx.strokeStyle = '#111'
  ctx.lineWidth = 3
  ctx.beginPath()
  ctx.moveTo(W / 2 - 180, y0 + (lines.length - 1) * lh + size * 0.9)
  ctx.lineTo(W / 2 + 180, y0 + (lines.length - 1) * lh + size * 0.9)
  ctx.stroke()
  return toJpeg(c)
}

/** 小テストの表紙: 左上に科目バッジ、中央に「小テスト」と小テスト名・教材名、下部に氏名・解答日の記入欄 */
export async function renderQuizCover(o: { subject: string; color: string; quizTitle: string; bookTitle: string }): Promise<Blob> {
  const { c, ctx } = canvas()
  // 科目バッジ（左上）
  ctx.font = `700 40px ${FONT}`
  const bw = Math.max(200, ctx.measureText(o.subject).width + 80)
  const bx = 90
  const by = 90
  const bh = 84
  ctx.fillStyle = o.color || '#475569'
  ctx.beginPath()
  ctx.roundRect(bx, by, bw, bh, 42)
  ctx.fill()
  ctx.fillStyle = textOn(o.color || '#475569')
  ctx.textAlign = 'center'
  ctx.textBaseline = 'middle'
  ctx.fillText(o.subject, bx + bw / 2, by + bh / 2 + 2)

  // 中央: 小テスト
  ctx.fillStyle = '#111'
  ctx.font = `700 150px ${FONT}`
  ctx.fillText('小テスト', W / 2, 560)
  // 小テスト名
  ctx.fillStyle = '#111'
  const ts = fitFont(ctx, o.quizTitle, W - 200, 64, 36)
  ctx.font = `700 ${ts}px ${FONT}`
  const tl = wrap(ctx, o.quizTitle, W - 200)
  tl.forEach((l, i) => ctx.fillText(l, W / 2, 760 + i * ts * 1.35))
  // 教材名
  ctx.fillStyle = '#4b5563'
  const bs = fitFont(ctx, o.bookTitle, W - 240, 44, 28, '500')
  ctx.font = `500 ${bs}px ${FONT}`
  const bl = wrap(ctx, o.bookTitle, W - 240)
  const by2 = 760 + tl.length * ts * 1.35 + 20
  bl.forEach((l, i) => ctx.fillText(l, W / 2, by2 + i * bs * 1.35))

  // 下部: 氏名・解答日
  ctx.textAlign = 'left'
  ctx.fillStyle = '#111'
  ctx.font = `600 38px ${FONT}`
  ctx.strokeStyle = '#111'
  ctx.lineWidth = 3
  const rows = [
    { label: '氏名', y: 1330 },
    { label: '解答日', y: 1470 },
  ]
  for (const r of rows) {
    ctx.fillText(r.label, 160, r.y)
    ctx.beginPath()
    ctx.moveTo(320, r.y + 40)
    ctx.lineTo(W - 160, r.y + 40)
    ctx.stroke()
  }
  ctx.font = `500 34px ${FONT}`
  ctx.fillStyle = '#6b7280'
  ctx.fillText('年　　　月　　　日', 720, 1470)
  return toJpeg(c)
}
