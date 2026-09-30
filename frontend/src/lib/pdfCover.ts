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

/**
 * 目安時間のスタンプ（各ページの右上に置く小さな画像）。透過 PNG で返す。
 * 描画サイズ 520×150px を、サーバー側で幅 38mm 程度に縮めて配置する。
 */
export async function renderTimeStamp(minutes: number): Promise<Blob> {
  const c = document.createElement('canvas')
  c.width = 520
  c.height = 150
  const ctx = c.getContext('2d')!
  ctx.fillStyle = 'rgba(255, 255, 255, 0.92)'
  ctx.strokeStyle = '#1c2024'
  ctx.lineWidth = 6
  ctx.beginPath()
  ctx.roundRect(4, 4, 512, 142, 26)
  ctx.fill()
  ctx.stroke()
  ctx.fillStyle = '#1c2024'
  ctx.textBaseline = 'middle'
  ctx.textAlign = 'left'
  ctx.font = `600 44px ${FONT}`
  ctx.fillText('目安', 32, 78)
  ctx.textAlign = 'right'
  ctx.font = `700 76px ${FONT}`
  ctx.fillText(`${minutes}`, 392, 80)
  ctx.font = `600 44px ${FONT}`
  ctx.fillText('分', 484, 82)
  return new Promise((resolve, reject) => c.toBlob((b) => (b ? resolve(b) : reject(new Error('stamp'))), 'image/png'))
}

/** 「目安時間 合計 45分（★1つ＝5分）」の 1 行を描く（表紙用） */
function drawTotalTime(ctx: CanvasRenderingContext2D, minutes: number, y: number) {
  ctx.textAlign = 'center'
  ctx.textBaseline = 'middle'
  ctx.fillStyle = '#1c2024'
  ctx.font = `700 40px ${FONT}`
  const label = `目安時間 合計 ${minutes}分`
  const w = ctx.measureText(label).width + 90
  ctx.strokeStyle = '#1c2024'
  ctx.lineWidth = 3
  ctx.beginPath()
  ctx.roundRect(W / 2 - w / 2, y - 40, w, 80, 40)
  ctx.stroke()
  ctx.fillText(label, W / 2, y + 2)
  ctx.fillStyle = '#6b7280'
  ctx.font = `500 26px ${FONT}`
  ctx.fillText('（各問題の★1つ＝5分。右上に問題ごとの目安を記載）', W / 2, y + 72)
}

/** 章の表紙: 中央に章名を大きく。上に教材名を小さく。目安時間の合計があれば下に表示 */
export async function renderChapterCover(chapter: string, bookTitle: string, totalMinutes?: number | null): Promise<Blob> {
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
  if (totalMinutes) drawTotalTime(ctx, totalMinutes, y0 + (lines.length - 1) * lh + size * 0.9 + 170)
  return toJpeg(c)
}

/** 小テストの表紙: 左上に科目バッジ、中央に「小テスト」と小テスト名・教材名、下部に氏名・解答日の記入欄 */
export async function renderQuizCover(o: { subject: string; color: string; quizTitle: string; bookTitle: string; totalMinutes?: number | null }): Promise<Blob> {
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
  // 目安時間の合計（★のある教材のみ）
  if (o.totalMinutes) drawTotalTime(ctx, o.totalMinutes, Math.min(1180, by2 + bl.length * bs * 1.35 + 90))

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
