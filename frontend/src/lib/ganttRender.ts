// ガントチャートを canvas に描画して画像にする（画像出力・PDF 出力用）。
// サーバーの FPDF は日本語フォントを持たないため、文字を含めてすべてここで描く。
import { daysBetween, parseDate } from '@/lib/design'
import { fmtYm, isDark, monthSpans, todayIso, yearSpans } from '@/lib/gantt'
import type { GanttChartDetail } from '@/api/gantt'

const FONT = "'Zen Kaku Gothic New', 'Hiragino Sans', 'Yu Gothic', 'Meiryo', sans-serif"

export interface RenderOptions {
  /** 解像度の倍率（既定 2） */
  scale?: number
  /** 1か月の幅（px） */
  monthW?: number
  /** 項目名の列の幅（px） */
  nameW?: number
}

/** 文字が幅に収まらないときは「…」で切る */
function fitText(ctx: CanvasRenderingContext2D, text: string, maxW: number): string {
  if (ctx.measureText(text).width <= maxW) return text
  let s = text
  while (s.length > 1 && ctx.measureText(s + '…').width > maxW) s = s.slice(0, -1)
  return s + '…'
}

export async function renderGanttCanvas(chart: GanttChartDetail, opts: RenderOptions = {}): Promise<HTMLCanvasElement> {
  if (document.fonts?.ready) await document.fonts.ready

  const scale = opts.scale ?? 2
  const nameW = opts.nameW ?? 300
  const monthW = opts.monthW ?? 56
  const rangeStart = parseDate(chart.startOn)
  const rangeEnd = parseDate(chart.endOn)
  const totalDays = Math.max(1, daysBetween(rangeStart, rangeEnd) + 1)
  const months = monthSpans(rangeStart, rangeEnd)
  const years = yearSpans(rangeStart, rangeEnd)
  const chartW = Math.max(900, months.length * monthW)
  const dayW = chartW / totalDays

  const PAD = 20
  const TOP = 66 // タイトル・期間
  const HY = 28 // 年の行
  const HM = 26 // 月の行
  const ROW = 40
  const rows = chart.rows
  const W = PAD * 2 + nameW + chartW
  const H = TOP + HY + HM + Math.max(1, rows.length) * ROW + PAD

  const c = document.createElement('canvas')
  c.width = Math.round(W * scale)
  c.height = Math.round(H * scale)
  const x = c.getContext('2d')!
  x.scale(scale, scale)
  x.fillStyle = '#ffffff'
  x.fillRect(0, 0, W, H)
  x.textBaseline = 'middle'

  const x0 = PAD + nameW // チャート部分の左端
  const y0 = TOP // 見出しの上端
  const yBody = y0 + HY + HM // 行の上端
  const bodyH = Math.max(1, rows.length) * ROW
  const ink = '#1c2024'

  // ---- タイトル・期間・出力日 ----
  x.fillStyle = ink
  x.font = `700 20px ${FONT}`
  x.textAlign = 'left'
  x.fillText(chart.title, PAD, 24)
  x.font = `400 12px ${FONT}`
  x.fillStyle = '#6b7280'
  x.fillText(`${fmtYm(chart.startOn)} 〜 ${fmtYm(chart.endOn)}`, PAD, 48)
  x.textAlign = 'right'
  const t = new Date()
  x.fillText(`出力日 ${t.getFullYear()}/${t.getMonth() + 1}/${t.getDate()}`, W - PAD, 48)

  // ---- 見出し ----
  x.fillStyle = '#f6f7f9'
  x.fillRect(PAD, y0, nameW + chartW, HY + HM)
  x.fillStyle = ink
  x.font = `700 13px ${FONT}`
  x.textAlign = 'left'
  x.fillText('科目・学習分野', PAD + 12, y0 + (HY + HM) / 2)

  x.strokeStyle = '#d4d8de'
  x.lineWidth = 1
  // 年
  x.font = `700 13px ${FONT}`
  x.textAlign = 'center'
  for (const y of years) {
    const left = x0 + y.startIdx * dayW
    const w = y.days * dayW
    x.fillStyle = ink
    if (w >= 40) x.fillText(`${y.year}年`, left + w / 2, y0 + HY / 2)
  }
  // 月
  x.font = `400 12px ${FONT}`
  for (const m of months) {
    const left = x0 + m.startIdx * dayW
    const w = m.days * dayW
    x.fillStyle = '#4b5260'
    if (w >= 16) x.fillText(String(m.month), left + w / 2, y0 + HY + HM / 2)
  }

  // ---- 罫線（縦） ----
  const line = (lx: number, ly1: number, ly2: number, color: string, width = 1) => {
    x.strokeStyle = color
    x.lineWidth = width
    x.beginPath()
    x.moveTo(Math.round(lx) + 0.5, ly1)
    x.lineTo(Math.round(lx) + 0.5, ly2)
    x.stroke()
  }
  const hline = (ly: number, lx1: number, lx2: number, color: string, width = 1) => {
    x.strokeStyle = color
    x.lineWidth = width
    x.beginPath()
    x.moveTo(lx1, Math.round(ly) + 0.5)
    x.lineTo(lx2, Math.round(ly) + 0.5)
    x.stroke()
  }
  for (const m of months) {
    const left = x0 + m.startIdx * dayW
    line(left, y0 + HY, yBody + bodyH, m.month === 1 ? '#9aa1ab' : '#e3e6ea')
  }
  for (const y of years) line(x0 + y.startIdx * dayW, y0, yBody + bodyH, '#9aa1ab')
  hline(y0 + HY, x0, x0 + chartW, '#d4d8de')

  // ---- 行 ----
  let prevGroup: string | null | undefined
  rows.forEach((row, i) => {
    const top = yBody + i * ROW
    const groupStart = i > 0 && (row.group ?? null) !== (prevGroup ?? null)
    prevGroup = row.group
    // 行の区切り（グループが変わる所は太く）
    hline(top, PAD, W - PAD, groupStart ? '#4b5260' : '#e9ebee', groupStart ? 2 : 1)

    // 項目名
    x.textAlign = 'left'
    x.fillStyle = ink
    x.font = `500 13px ${FONT}`
    const groupLabel = row.group && (i === 0 || groupStart) ? row.group : ''
    const gw = groupLabel ? x.measureText(groupLabel).width : 0
    x.fillText(fitText(x, row.title, nameW - 24 - (groupLabel ? gw + 14 : 0)), PAD + 12, top + ROW / 2)
    if (groupLabel) {
      x.font = `500 10px ${FONT}`
      x.fillStyle = '#9aa1ab'
      x.textAlign = 'right'
      x.fillText(groupLabel, x0 - 10, top + ROW / 2)
    }

    // 区間（チャート部分で切り抜く）
    x.save()
    x.beginPath()
    x.rect(x0, top, chartW, ROW)
    x.clip()
    for (const task of row.tasks) {
      const s = daysBetween(rangeStart, parseDate(task.startOn))
      const e = daysBetween(rangeStart, parseDate(task.endOn))
      if (e < 0 || s > totalDays - 1) continue
      const dark = isDark(task.color)
      if (task.kind === 'milestone') {
        const cx = x0 + (s + 0.5) * dayW
        const cy = top + ROW / 2
        const r = 7
        x.fillStyle = task.color
        x.beginPath()
        x.moveTo(cx, cy - r)
        x.lineTo(cx + r, cy)
        x.lineTo(cx, cy + r)
        x.lineTo(cx - r, cy)
        x.closePath()
        x.fill()
        x.fillStyle = ink
        x.font = `500 11px ${FONT}`
        x.textAlign = 'left'
        x.fillText(task.title, cx + r + 5, cy)
        continue
      }
      const left = x0 + Math.max(0, s) * dayW
      const right = x0 + (Math.min(totalDays - 1, e) + 1) * dayW
      const w = Math.max(2, right - left)
      const bt = top + 7
      const bh = ROW - 14
      x.fillStyle = task.color
      x.fillRect(left, bt, w, bh)
      x.strokeStyle = 'rgba(0,0,0,0.18)'
      x.lineWidth = 1
      x.strokeRect(left + 0.5, bt + 0.5, w - 1, bh - 1)
      // 進捗の帯（バーの下端）
      if (task.progress > 0) {
        x.fillStyle = dark ? 'rgba(255,255,255,0.85)' : 'rgba(28,32,36,0.55)'
        x.fillRect(left + 2, bt + bh - 5, (w - 4) * Math.min(100, task.progress) / 100, 3)
      }
      // 名前（収まらなければ 2 行、それでも無理なら省略）
      x.fillStyle = dark ? '#ffffff' : ink
      x.textAlign = 'center'
      x.font = `500 11px ${FONT}`
      const label = task.title
      const cx = left + w / 2
      if (x.measureText(label).width <= w - 6) {
        x.fillText(label, cx, bt + bh / 2)
      } else if (w >= 24 && label.length >= 2) {
        const half = Math.ceil(label.length / 2)
        const l1 = label.slice(0, half)
        const l2 = label.slice(half)
        x.font = `500 9.5px ${FONT}`
        if (Math.max(x.measureText(l1).width, x.measureText(l2).width) <= w - 4) {
          x.fillText(l1, cx, bt + bh / 2 - 5.5)
          x.fillText(l2, cx, bt + bh / 2 + 5.5)
        }
      }
    }
    x.restore()
  })
  hline(yBody + bodyH, PAD, W - PAD, '#9aa1ab')

  // ---- 今日の線 ----
  const todayIdx = daysBetween(rangeStart, parseDate(todayIso()))
  if (todayIdx >= 0 && todayIdx < totalDays) {
    const tx = x0 + (todayIdx + 0.5) * dayW
    line(tx, y0 + HY, yBody + bodyH, 'rgba(224,83,61,0.8)', 1.5)
    x.fillStyle = '#e0533d'
    x.font = `700 10px ${FONT}`
    x.textAlign = 'center'
    x.fillText('今日', tx, y0 + HY + HM - 7)
  }

  // ---- 外枠・項目名の列の境界 ----
  line(x0, y0, yBody + bodyH, '#9aa1ab')
  x.strokeStyle = '#9aa1ab'
  x.lineWidth = 1
  x.strokeRect(PAD + 0.5, y0 + 0.5, nameW + chartW - 1, HY + HM + bodyH - 1)

  return c
}

/** チャートを画像（PNG / JPEG）の Blob にする */
export async function renderGanttImage(chart: GanttChartDetail, mime: 'image/png' | 'image/jpeg' = 'image/png', quality = 0.92): Promise<Blob> {
  const c = await renderGanttCanvas(chart)
  return new Promise((resolve, reject) => {
    c.toBlob((b) => (b ? resolve(b) : reject(new Error('画像の生成に失敗しました'))), mime, quality)
  })
}
